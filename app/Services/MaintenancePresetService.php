<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaintenancePresetService
{
    public function catalog(): array
    {
        return config('maintenance-presets');
    }

    public function recommend(Asset $asset): string
    {
        $category = $asset->category;
        $mapping = [
            'legacy-v1-category-1' => 'computer', 'legacy-v1-category-2' => 'computer', 'legacy-v1-category-3' => 'computer',
            'legacy-v1-category-4' => 'printer', 'legacy-v1-category-5' => 'network', 'legacy-v1-category-6' => 'peripheral',
            'legacy-v1-category-7' => 'printer', 'legacy-v1-category-8' => 'ups', 'legacy-v1-category-9' => 'display',
            'legacy-v1-category-10' => 'tablet', 'legacy-v1-category-11' => 'audio', 'legacy-v1-category-12' => 'stage',
            'legacy-v1-category-13' => 'display', 'computers' => 'computer', 'servers' => 'server', 'network' => 'network',
            'racks' => 'rack', 'peripherals' => 'peripheral',
        ];
        if (isset($mapping[$category?->slug])) {
            return $mapping[$category->slug];
        }

        return match (true) {
            $category?->kind === 'computer' => 'computer',
            $category?->kind === 'rack' => 'rack',
            $category?->domain === 'audio' => 'audio',
            $category?->domain === 'stage' => 'stage',
            default => 'generic',
        };
    }

    /** Applies suggestions only; no work, acceptance, past service or asset condition is invented. */
    public function apply(User $actor, array $input): array
    {
        Access::check($actor, 'write');
        $catalog = $this->catalog();
        $data = Validator::make($input, [
            'preset_key' => ['required', 'string', Rule::in(array_keys($catalog))],
            'asset_uuids' => 'required|array|min:1|max:500', 'asset_uuids.*' => 'required|uuid|distinct',
            'asset_versions' => 'required|array|min:1|max:500', 'asset_versions.*' => 'required|integer|min:1',
            'first_due_on' => 'nullable|date_format:Y-m-d',
            'interval_unit' => ['sometimes', 'required', Rule::in(['day', 'week', 'month', 'year'])],
            'interval_count' => 'sometimes|required|integer|min:1|max:120',
        ])->validate();
        $data['asset_uuids'] = array_map('strtolower', $data['asset_uuids']);
        if (count(array_unique($data['asset_uuids'])) !== count($data['asset_uuids'])) {
            throw ValidationException::withMessages(['asset_uuids' => 'Каждый предмет можно выбрать только один раз.']);
        }
        $versions = [];
        foreach ($data['asset_versions'] as $uuid => $version) {
            if (! is_string($uuid) || ! Str::isUuid($uuid) || array_key_exists(strtolower($uuid), $versions)) {
                throw ValidationException::withMessages(['asset_versions' => 'Версии должны быть заданы по различным UUID имущества.']);
            }
            $versions[strtolower($uuid)] = $version;
        }
        $data['asset_versions'] = $versions;
        foreach ($data['asset_uuids'] as $uuid) {
            if (! array_key_exists($uuid, $data['asset_versions'])) {
                throw ValidationException::withMessages(['asset_versions' => 'Не указана версия выбранного предмета. Обновите страницу.']);
            }
        }
        $preset = $catalog[$data['preset_key']];

        return DB::transaction(function () use ($actor, $data, $preset) {
            Codes::lockPhysicalGraph();
            $assets = Asset::with('category')->whereIn('uuid', $data['asset_uuids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($assets->count() === count($data['asset_uuids']), 404, 'Один из выбранных предметов отсутствует.');
            foreach ($assets as $asset) {
                Version::check($asset, $data['asset_versions'][$asset->uuid]);
                abort_if($asset->archived_at || $asset->status === 'written_off', 422, 'Нельзя назначить регламент архивному или списанному предмету.');
                abort_unless($this->recommend($asset) === $data['preset_key'], 422, 'Рекомендуемый регламент не соответствует категории выбранного предмета.');
            }
            $result = ['created' => 0, 'skipped' => 0, 'plans' => [], 'preset_key' => $data['preset_key']];
            foreach ($assets as $asset) {
                $application = DB::table('maintenance_preset_applications')->where('asset_id', $asset->id)->where('preset_key', $data['preset_key'])->lockForUpdate()->first();
                if ($application || MaintenancePlan::where('asset_id', $asset->id)->where('status', 'active')->lockForUpdate()->first()) {
                    $result['skipped']++;

                    continue;
                }
                $plan = app(OperationsService::class)->createPlan($actor, [
                    'asset_uuid' => $asset->uuid, 'name' => $preset['name'], 'mode' => 'completion',
                    'interval_unit' => $data['interval_unit'] ?? $preset['interval_unit'],
                    'interval_count' => $data['interval_count'] ?? $preset['interval_count'],
                    'first_due_on' => $data['first_due_on'] ?? null, 'lead_days' => $preset['lead_days'],
                    'checklist_schema' => $preset['checklist_schema'],
                ]);
                DB::table('maintenance_preset_applications')->insert([
                    'asset_id' => $asset->id, 'preset_key' => $data['preset_key'], 'maintenance_plan_id' => $plan->id,
                    'preset_snapshot' => json_encode($preset, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_by' => $actor->id, 'created_at' => now(),
                ]);
                Audit::record('maintenance.preset.applied', $plan, ['preset_key' => $data['preset_key'], 'asset_uuid' => $asset->uuid, 'first_due_on' => $data['first_due_on'] ?? null]);
                $result['created']++;
                $result['plans'][] = $plan->uuid;
            }

            return $result;
        });
    }
}
