<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AssetWriteoff;
use App\Models\Location;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class WriteoffService
{
    public function writeOff(User $actor, Asset $asset, array $input): Asset
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $asset, $data) {
            Codes::lockPhysicalGraph();
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            Version::check($asset, $data['expected_version']);
            abort_if($asset->archived_at || $asset->status === 'written_off', 409, 'Предмет уже списан или находится в архиве.');
            $this->assertCanWriteOff($asset);
            $pausedPlans = DB::table('maintenance_plans')->where('asset_id', $asset->id)->where('status', 'active')->orderBy('id')->lockForUpdate()->get();
            foreach ($pausedPlans as $plan) {
                DB::table('maintenance_plans')->where('id', $plan->id)->update(['status' => 'paused', 'version' => $plan->version + 1, 'updated_at' => now()]);
            }
            AssetWriteoff::create([
                'asset_id' => $asset->id, 'asset_uuid' => $asset->uuid, 'asset_code' => $asset->code,
                'asset_name' => $asset->name, 'snapshot' => $asset->getAttributes() + ['paused_maintenance_plans' => $pausedPlans->map(fn ($row) => (array) $row)->all()], 'reason' => $data['reason'],
                'written_off_by' => $actor->id, 'written_off_at' => now(),
            ]);
            $before = $asset->status;
            $asset->status = 'written_off';
            $asset->version++;
            $asset->save();
            $this->movement($actor, $asset, $before, $asset->location_id, 'Списание: '.$data['reason']);
            Audit::record('asset.written_off', $asset, ['reason' => $data['reason'], 'version' => $asset->version, 'actor_id' => $actor->id, 'paused_plan_ids' => $pausedPlans->pluck('id')->all()]);

            return $asset->refresh();
        }, 3);
    }

    public function restore(User $actor, Asset $asset, array $input): Asset
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, [
            'expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000',
            'status' => 'required|in:stock,in_use,repair', 'location_id' => ['present', 'nullable', Rule::exists('locations', 'id')->whereNull('archived_at')],
        ])->validate();

        return DB::transaction(function () use ($actor, $asset, $data) {
            Codes::lockPhysicalGraph();
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            Version::check($asset, $data['expected_version']);
            abort_unless($asset->status === 'written_off', 409, 'Предмет не находится в списании.');
            abort_if($asset->archived_at, 409, 'Архивная запись недоступна для восстановления.');
            if ($data['location_id']) {
                Location::whereKey($data['location_id'])->whereNull('archived_at')->lockForUpdate()->firstOrFail();
            }
            $fromLocation = $asset->location_id;
            $asset->status = $data['status'];
            $asset->location_id = $data['location_id'];
            $asset->version++;
            $asset->save();
            $record = AssetWriteoff::where('asset_id', $asset->id)->whereNull('restored_at')->whereNull('purged_at')->latest('id')->lockForUpdate()->first();
            $record?->update(['restored_by' => $actor->id, 'restored_at' => now(), 'restore_reason' => $data['reason']]);
            $this->movement($actor, $asset, 'written_off', $fromLocation, 'Возврат из списания: '.$data['reason']);
            Audit::record('asset.writeoff_restored', $asset, ['reason' => $data['reason'], 'version' => $asset->version, 'actor_id' => $actor->id]);

            // Restoring an asset does not reactivate maintenance or license assignments.
            return $asset->refresh();
        }, 3);
    }

    public function purge(User $actor, Asset $asset, array $input): void
    {
        Access::check($actor, 'purge-assets');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'confirmation_code' => 'required|string|max:64', 'reason' => 'required|string|max:2000'])->validate();
        $backupPath = null;

        try {
            DB::transaction(function () use ($actor, $asset, $data, &$backupPath) {
                Codes::lockPhysicalGraph();
                $asset = Asset::lockForUpdate()->findOrFail($asset->id);
                Version::check($asset, $data['expected_version']);
                abort_unless($asset->status === 'written_off', 409, 'Полное удаление доступно только из списания.');
                abort_unless(hash_equals($asset->code, $data['confirmation_code']), 422, 'Для подтверждения введите точный код предмета.');
                $blockers = $this->purgeBlockers($asset);
                abort_if($blockers !== [], 422, 'Удаление невозможно: '.implode('; ', $blockers).'. История связанных операций должна сохраниться.');
                $movements = DB::table('asset_movements')->where('asset_id', $asset->id)->orderBy('id')->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
                $unusedPlans = $this->unusedPlans($asset, true);
                $presetApplications = DB::table('maintenance_preset_applications')->where('asset_id', $asset->id)->orderBy('id')->lockForUpdate()->get();
                $snapshot = ['asset' => $asset->getAttributes(), 'movements' => $movements, 'unused_maintenance_plans' => $unusedPlans->map(fn ($row) => (array) $row)->all(), 'maintenance_preset_applications' => $presetApplications->map(fn ($row) => (array) $row)->all(), 'deleted_at' => now()->toIso8601String(), 'deleted_by' => $actor->id, 'reason' => $data['reason']];
                $backupPath = 'deletion-backups/'.Str::uuid().'.json.enc';
                $encrypted = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                // A private, encrypted per-record backup must exist before the row is deleted.
                abort_unless(Storage::disk('local')->put($backupPath, $encrypted), 503, 'Резервная копия не создана. Удаление отменено.');
                $record = AssetWriteoff::where('asset_id', $asset->id)->whereNull('restored_at')->whereNull('purged_at')->latest('id')->lockForUpdate()->first();
                if (! $record) {
                    // Legacy written-off rows may predate this module.
                    $record = AssetWriteoff::create(['asset_id' => $asset->id, 'asset_uuid' => $asset->uuid, 'asset_code' => $asset->code, 'asset_name' => $asset->name, 'snapshot' => ['legacy_written_off' => true], 'reason' => 'Списан до включения модуля; исходные дата и автор неизвестны.', 'written_off_by' => null, 'written_off_at' => null]);
                }
                $record->update(['purged_by' => $actor->id, 'purged_at' => now(), 'purge_reason' => $data['reason'], 'purge_backup_path' => $backupPath, 'purge_backup_sha256' => hash('sha256', $encrypted)]);
                DB::table('retired_asset_codes')->insert(['asset_uuid' => $asset->uuid, 'former_asset_id' => $asset->id, 'code' => $asset->code, 'retired_at' => now()]);
                // Keep the original dates and actors even after deleting the asset row.
                Audit::record('asset.purged', $asset, ['code' => $asset->code, 'reason' => $data['reason'], 'actor_id' => $actor->id, 'movements_snapshot' => $movements, 'unused_maintenance_plans' => $snapshot['unused_maintenance_plans'], 'maintenance_preset_applications' => $snapshot['maintenance_preset_applications'], 'backup_sha256' => hash('sha256', $encrypted)]);
                DB::table('asset_movements')->where('asset_id', $asset->id)->delete();
                DB::table('maintenance_preset_applications')->where('asset_id', $asset->id)->delete();
                DB::table('maintenance_plans')->whereIn('id', $unusedPlans->pluck('id')->all())->delete();
                $asset->delete();
                // Typed QR aliases deliberately remain reserved against the former ID.
            });
        } catch (Throwable $error) {
            if ($backupPath) {
                Storage::disk('local')->delete($backupPath);
            }
            throw $error;
        }
    }

    public function purgeBlockers(Asset $asset): array
    {
        $blockers = [];
        $checks = [
            ['physical_placements', ['asset_id', 'parent_asset_id'], 'сохраняется физическое размещение'],
            ['asset_components', ['parent_asset_id', 'child_asset_id'], 'сохраняется история состава ПК'],
            ['kit_members', ['asset_id'], 'предмет входит в комплект'],
            ['software_installations', ['asset_id'], 'есть установки программного обеспечения'],
            ['license_assignments', ['asset_id'], 'есть история назначения лицензий'],
            ['work_orders', ['asset_id'], 'есть работы по обслуживанию'],
            ['maintenance_requests', ['asset_id'], 'есть заявки о неисправностях'],
            ['inventory_snapshot_items', ['asset_id'], 'есть история инвентаризации'],
            ['inventory_asset_reservations', ['asset_id'], 'идёт инвентаризация'],
            ['inventory_observations', ['asset_id'], 'есть наблюдения инвентаризации'],
            ['inventory_resolutions', ['asset_id'], 'есть решения инвентаризации'],
            ['documents', ['asset_id'], 'есть загруженные документы'],
        ];
        foreach ($checks as [$table, $columns, $message]) {
            if (DB::table($table)->where(function ($q) use ($columns, $asset) {
                foreach ($columns as $column) {
                    $q->orWhere($column, $asset->id);
                }
            })->exists()) {
                $blockers[] = $message;
            }
        }
        if (DB::table('maintenance_plans')->where('asset_id', $asset->id)->whereNotIn('id', $this->unusedPlans($asset)->pluck('id')->all())->exists()) {
            $blockers[] = 'есть регламенты с историей обслуживания или действующие планы';
        }

        return $blockers;
    }

    private function assertCanWriteOff(Asset $asset): void
    {
        abort_if(DB::table('physical_placements')->where('asset_id', $asset->id)->orWhere('parent_asset_id', $asset->id)->lockForUpdate()->get()->isNotEmpty(), 422, 'Перед списанием снимите оборудование со шкафа и отсоедините установленные предметы.');
        abort_if(DB::table('asset_components')->whereNull('removed_at')->where(fn ($q) => $q->where('parent_asset_id', $asset->id)->orWhere('child_asset_id', $asset->id))->lockForUpdate()->get()->isNotEmpty(), 422, 'Перед списанием снимите активные комплектующие из состава ПК.');
        abort_if(DB::table('kit_members')->where('asset_id', $asset->id)->lockForUpdate()->get()->isNotEmpty(), 422, 'Перед списанием исключите предмет из комплекта.');
        abort_if(DB::table('license_assignments')->where('asset_id', $asset->id)->whereNull('released_at')->lockForUpdate()->get()->isNotEmpty(), 422, 'Перед списанием освободите назначенные лицензии.');
        abort_if(DB::table('software_installations')->where('asset_id', $asset->id)->whereNull('archived_at')->lockForUpdate()->get()->isNotEmpty(), 422, 'Перед списанием перенесите установки ПО на другой объект или в среду размещения.');
        abort_if(DB::table('inventory_asset_reservations')->where('asset_id', $asset->id)->lockForUpdate()->get()->isNotEmpty(), 422, 'Сначала завершите инвентаризацию этого оборудования.');
        abort_if(DB::table('work_orders')->where('asset_id', $asset->id)->whereNotIn('status', ['closed', 'cancelled'])->lockForUpdate()->get()->isNotEmpty(), 422, 'Перед списанием завершите или отмените открытые работы.');
        abort_if(DB::table('maintenance_requests')->where('asset_id', $asset->id)->whereIn('status', ['new', 'needs_info'])->lockForUpdate()->get()->isNotEmpty(), 422, 'Перед списанием разберите открытые заявки о неисправностях.');
    }

    private function unusedPlans(Asset $asset, bool $lock = false): Collection
    {
        $query = DB::table('maintenance_plans')->where('asset_id', $asset->id)->where('status', 'paused')->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('maintenance_occurrences')->whereColumn('maintenance_occurrences.maintenance_plan_id', 'maintenance_plans.id');
        })->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    private function movement(User $actor, Asset $asset, string $before, ?int $fromLocation, string $reason): void
    {
        AssetMovement::create(['asset_id' => $asset->id, 'from_location_id' => $fromLocation, 'to_location_id' => $asset->location_id, 'from_status' => $before, 'to_status' => $asset->status, 'reason' => $reason, 'actor_id' => $actor->id, 'asset_version' => $asset->version, 'occurred_at' => now()]);
    }
}
