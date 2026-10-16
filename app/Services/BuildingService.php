<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Location;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Illuminate\Support\Facades\DB;

class BuildingService
{
    public function create(User $actor, array $data): Building
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($data) {
            Codes::lockPhysicalGraph();
            $building = Building::create($this->fields($data));
            Audit::record('building.created', $building);

            return $building;
        }, 3);
    }

    public function update(User $actor, Building $building, array $data, int $expected): Building
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($building, $data, $expected) {
            Codes::lockPhysicalGraph();
            $current = Building::whereKey($building->id)->lockForUpdate()->firstOrFail();
            Version::check($current, $expected);
            abort_if($current->archived_at, 409, 'Здание уже в архиве.');
            $current->fill($this->fields($data));
            $current->version++;
            $current->save();
            Audit::record('building.updated', $current);

            return $current;
        }, 3);
    }

    public function archive(User $actor, Building $building, int $expected): void
    {
        Access::check($actor, 'write');
        DB::transaction(function () use ($building, $expected): void {
            Codes::lockPhysicalGraph();
            $current = Building::whereKey($building->id)->lockForUpdate()->firstOrFail();
            Version::check($current, $expected);
            abort_if($current->archived_at, 409, 'Здание уже в архиве.');
            abort_if(Location::where('building_id', $current->id)->whereNull('archived_at')->lockForUpdate()->get(['id'])->isNotEmpty(), 422, 'Сначала перенесите или архивируйте все кабинеты здания.');
            $current->archived_at = now();
            $current->version++;
            $current->save();
            Audit::record('building.archived', $current);
        }, 3);
    }

    private function fields(array $data): array
    {
        return ['name' => $data['name'], 'address' => $data['address'] ?? null, 'notes' => $data['notes'] ?? null];
    }
}
