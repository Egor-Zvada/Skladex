<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\LicenseAssignment;
use App\Models\LicenseInstance;
use App\Models\SoftwareProduct;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\LicenseKeyCipher;
use App\Support\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LicenseService
{
    public function __construct(private LicenseKeyCipher $cipher) {}

    public function create(User $actor, array $input): LicenseInstance
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, $this->rules() + [
            'software_product_id' => 'required|integer|exists:software_products,id',
            'license_key' => 'nullable|string|max:16384',
            'asset_id' => 'nullable|integer|exists:assets,id',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $product = SoftwareProduct::lockForUpdate()->findOrFail($data['software_product_id']);
            abort_if($product->archived_at !== null, 409, 'Программа находится в архиве.');
            $key = $data['license_key'] ?? null;
            $assetId = $data['asset_id'] ?? null;
            unset($data['license_key'], $data['asset_id']);
            $license = LicenseInstance::create($data + ['code' => Codes::next('SKX-L')]);
            if ($key !== null && $key !== '') {
                try {
                    $encrypted = $this->cipher->encrypt($key);
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages(['license_key' => 'Защита ключей лицензий не настроена. Обратитесь к администратору.']);
                }
                $license->secret()->create(['ciphertext' => $encrypted, 'key_id' => config('skladex.license_key_id')]);
            }
            if ($assetId) {
                $this->createAssignment($actor, $license, (int) $assetId);
            }
            Audit::record('license.created', $license, ['software_product_id' => $product->id, 'has_key' => $key !== null && $key !== '', 'asset_id' => $assetId]);

            return $license;
        }, 3);
    }

    public function update(User $actor, LicenseInstance $license, array $input): LicenseInstance
    {
        Access::check($actor, 'write');
        // Key replacement is intentionally a separate future command; it must not be
        // accidentally overwritten by an ordinary metadata form or blank input.
        $data = Validator::make($input, $this->rules() + ['expected_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($license, $data) {
            $locked = $this->locked($license, $data['expected_version']);
            $metric = $data['metric'] ?? $locked->metric;
            $capacity = array_key_exists('capacity', $data) ? $data['capacity'] : $locked->capacity;
            if ($metric === 'device' && $capacity !== null && $locked->activeAssignments()->count() > (int) $capacity) {
                throw ValidationException::withMessages(['capacity' => 'Ёмкость меньше количества действующих привязок. Сначала освободите места.']);
            }
            unset($data['expected_version']);
            $locked->fill($data);
            $locked->version++;
            $locked->save();
            Audit::record('license.updated', $locked, ['capacity' => $locked->capacity, 'metric' => $locked->metric]);

            return $locked;
        }, 3);
    }

    public function storeKey(User $actor, LicenseInstance $license, array $input): LicenseInstance
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'license_key' => 'required|string|max:16384'])->validate();

        return DB::transaction(function () use ($actor, $license, $data) {
            $locked = $this->locked($license, $data['expected_version']);
            $exists = $locked->secret()->exists();
            if ($exists) {
                Access::check($actor, 'reveal-license');
            }
            try {
                $encrypted = $this->cipher->encrypt($data['license_key']);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['license_key' => 'Защита ключей лицензий не настроена. Обратитесь к администратору.']);
            }
            $locked->secret()->updateOrCreate([], ['ciphertext' => $encrypted, 'key_id' => config('skladex.license_key_id')]);
            $locked->increment('version');
            Audit::record($exists ? 'license.key.replaced' : 'license.key.stored', $locked);

            return $locked;
        }, 3);
    }

    public function assign(User $actor, LicenseInstance $license, array $input): LicenseInstance
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'asset_id' => 'required|integer|exists:assets,id'])->validate();

        return DB::transaction(function () use ($actor, $license, $data) {
            $locked = $this->locked($license, $data['expected_version']);
            $assignment = $this->createAssignment($actor, $locked, (int) $data['asset_id']);
            $locked->increment('version');
            Audit::record('license.assigned', $locked, ['asset_id' => $assignment->asset_id, 'assignment_id' => $assignment->id]);

            return $locked;
        }, 3);
    }

    public function release(User $actor, LicenseInstance $license, LicenseAssignment $assignment, array $input): LicenseInstance
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($license, $assignment, $data) {
            $locked = $this->locked($license, $data['expected_version']);
            $row = LicenseAssignment::lockForUpdate()->findOrFail($assignment->id);
            abort_unless($row->license_instance_id === $locked->id, 404);
            abort_if($row->released_at !== null, 409, 'Место уже освобождено.');
            $row->update(['released_at' => now()]);
            $locked->increment('version');
            Audit::record('license.released', $locked, ['assignment_id' => $row->id, 'asset_id' => $row->asset_id]);

            return $locked;
        }, 3);
    }

    public function reveal(User $actor, LicenseInstance $license, array $input): string
    {
        Access::check($actor, 'reveal-license');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($license, $data) {
            $locked = $this->locked($license, $data['expected_version'], allowArchived: true);
            $secret = $locked->secret()->first();
            abort_unless($secret, 404, 'Ключ для этой лицензии не сохранён.');
            try {
                $plaintext = $this->cipher->decrypt($secret->ciphertext, $secret->key_id);
            } catch (RuntimeException $exception) {
                abort(409, 'Не удалось открыть ключ. Проверьте доступность правильной версии мастер-ключа.');
            }
            Audit::record('license.key.revealed', $locked);

            return $plaintext;
        }, 3);
    }

    public function warnings(LicenseInstance $license): array
    {
        $warnings = [];
        if ($license->capacity === null) {
            $warnings[] = 'Ёмкость неизвестна: привязки учитываются, лицензионные ограничения требуют ручной проверки.';
        }
        if ($license->metric !== 'device') {
            $warnings[] = 'Метрика не является количеством устройств. Привязки к ПК справочные; занятые и свободные лицензионные места автоматически не рассчитываются. Требуется ручная проверка.';
        }
        if ($license->expires_on && $license->expires_on->format('Y-m-d') < now(config('skladex.timezone'))->toDateString()) {
            $warnings[] = 'Указанный срок лицензии истёк. Проверьте условия использования.';
        }

        return $warnings;
    }

    public function usage(LicenseInstance $license): array
    {
        $count = $license->active_assignments_count ?? $license->activeAssignments()->count();

        return [
            'equipment_links' => (int) $count,
            'remaining' => $license->metric === 'device' && $license->capacity !== null ? max(0, $license->capacity - $count) : null,
            'needs_review' => $license->metric !== 'device' || $license->capacity === null,
        ];
    }

    private function createAssignment(User $actor, LicenseInstance $license, int $assetId): LicenseAssignment
    {
        $asset = Asset::lockForUpdate()->findOrFail($assetId);
        if ($asset->archived_at || $asset->status === 'written_off') {
            throw ValidationException::withMessages(['asset_id' => 'Нельзя привязать лицензию к списанному или архивному оборудованию.']);
        }
        if ($license->activeAssignments()->where('asset_id', $asset->id)->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Этот компьютер уже занимает место данной лицензии.']);
        }
        if ($license->metric === 'device' && $license->capacity !== null && $license->activeAssignments()->count() >= $license->capacity) {
            throw ValidationException::withMessages(['asset_id' => 'Все места этой лицензии заняты. Сначала освободите место или исправьте ёмкость.']);
        }

        return $license->assignments()->create(['asset_id' => $asset->id, 'actor_id' => $actor->id, 'assigned_at' => now()]);
    }

    private function locked(LicenseInstance $license, mixed $expected, bool $allowArchived = false): LicenseInstance
    {
        $locked = LicenseInstance::lockForUpdate()->findOrFail($license->id);
        Version::check($locked, $expected);
        abort_if(! $allowArchived && $locked->archived_at !== null, 409, 'Лицензия находится в архиве.');

        return $locked;
    }

    private function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'external_reference' => 'nullable|string|max:255',
            'metric' => ['sometimes', Rule::in(['device', 'user', 'instance', 'unknown'])],
            'capacity' => 'nullable|integer|min:0|max:1000000000',
            'expires_on' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:10000',
        ];
    }
}
