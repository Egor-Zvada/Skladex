<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\SoftwareInstallation;
use App\Models\SoftwareProduct;
use App\Models\SoftwareRelease;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SoftwareService
{
    public function create(User $actor, array $input): SoftwareProduct
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, $this->productRules())->validate();

        return DB::transaction(function () use ($data) {
            $product = SoftwareProduct::create($data);
            Audit::record('software.created', $product);

            return $product;
        }, 3);
    }

    public function update(User $actor, SoftwareProduct $product, array $input): SoftwareProduct
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, $this->productRules() + ['expected_version' => 'required|integer|min:1'])->validate();

        return DB::transaction(function () use ($product, $data) {
            $locked = SoftwareProduct::lockForUpdate()->findOrFail($product->id);
            Version::check($locked, $data['expected_version']);
            $this->active($locked);
            unset($data['expected_version']);
            $locked->fill($data);
            $locked->version++;
            $locked->save();
            Audit::record('software.updated', $locked);

            return $locked;
        }, 3);
    }

    public function addRelease(User $actor, SoftwareProduct $product, array $input): SoftwareRelease
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, [
            'expected_version' => 'required|integer|min:1',
            'version_name' => 'required|string|max:120',
            'released_on' => 'nullable|date_format:Y-m-d',
            'supported_until' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:10000',
        ])->validate();

        return DB::transaction(function () use ($product, $data) {
            $locked = SoftwareProduct::lockForUpdate()->findOrFail($product->id);
            Version::check($locked, $data['expected_version']);
            $this->active($locked);
            if ($locked->releases()->where('version_name', $data['version_name'])->exists()) {
                throw ValidationException::withMessages(['version_name' => 'Такая версия этой программы уже существует.']);
            }
            unset($data['expected_version']);
            $release = $locked->releases()->create($data);
            $locked->increment('version');
            Audit::record('software.release.created', $locked, ['release_id' => $release->id]);

            return $release;
        }, 3);
    }

    public function saveInstallation(User $actor, SoftwareProduct $product, array $input, ?SoftwareInstallation $installation = null): SoftwareInstallation
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, [
            'expected_version' => 'required|integer|min:1',
            'software_release_id' => 'nullable|integer|exists:software_releases,id',
            'asset_id' => 'nullable|integer|exists:assets,id',
            'environment' => 'nullable|string|max:160',
            'service_name' => 'nullable|string|max:255',
            'installed_on' => 'nullable|date_format:Y-m-d',
            'verified_now' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:10000',
        ])->validate();
        if (empty($data['asset_id']) && trim($data['environment'] ?? '') === '') {
            throw ValidationException::withMessages(['environment' => 'Укажите компьютер/оборудование или среду размещения (ВМ, контейнер, внешний сервис).']);
        }

        return DB::transaction(function () use ($actor, $product, $installation, $data) {
            $lockedProduct = SoftwareProduct::lockForUpdate()->findOrFail($product->id);
            $this->active($lockedProduct);
            if ($installation) {
                $saved = SoftwareInstallation::lockForUpdate()->findOrFail($installation->id);
                abort_unless($saved->software_product_id === $lockedProduct->id, 404);
                Version::check($saved, $data['expected_version']);
                $this->active($saved);
            } else {
                Version::check($lockedProduct, $data['expected_version']);
                $saved = new SoftwareInstallation(['software_product_id' => $lockedProduct->id]);
            }
            if (! empty($data['software_release_id'])) {
                $release = SoftwareRelease::findOrFail($data['software_release_id']);
                if ($release->software_product_id !== $lockedProduct->id) {
                    throw ValidationException::withMessages(['software_release_id' => 'Версия относится к другой программе.']);
                }
            }
            if (! empty($data['asset_id'])) {
                $asset = Asset::lockForUpdate()->findOrFail($data['asset_id']);
                if ($asset->archived_at || $asset->status === 'written_off') {
                    throw ValidationException::withMessages(['asset_id' => 'Нельзя размещать установку на списанном или архивном оборудовании.']);
                }
            }
            $verified = $data['verified_now'] ?? false;
            unset($data['expected_version'], $data['verified_now']);
            // An edited version/host is not automatically a verified real deployment.
            if ($installation && (array_key_exists('software_release_id', $data) && $data['software_release_id'] != $saved->software_release_id || array_key_exists('asset_id', $data) && $data['asset_id'] != $saved->asset_id || array_key_exists('environment', $data) && $data['environment'] !== $saved->environment)) {
                $saved->last_verified_at = null;
            }
            $saved->fill($data);
            if ($verified) {
                $saved->last_verified_at = now();
            }
            if ($installation) {
                $saved->version++;
            }
            $saved->save();
            $lockedProduct->increment('version');
            Audit::record($installation ? 'software.installation.updated' : 'software.installation.created', $saved, ['software_product_id' => $lockedProduct->id, 'verified' => (bool) $verified, 'actor_id' => $actor->id]);

            return $saved;
        }, 3);
    }

    private function productRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'vendor' => 'nullable|string|max:255',
            'kind' => ['required', Rule::in(['third_party', 'in_house', 'firmware'])],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'repository_url' => ['nullable', 'url:http,https', 'max:2048', function ($attribute, $value, $fail) {
                $url = parse_url($value);
                if (isset($url['user']) || isset($url['pass'])) {
                    $fail('URL репозитория не должен содержать логин, пароль или токен.');
                }
            }],
            'description' => 'nullable|string|max:10000',
        ];
    }

    private function active($model): void
    {
        abort_if($model->archived_at !== null, 409, 'Запись находится в архиве.');
    }
}
