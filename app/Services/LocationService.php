<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Building;
use App\Models\Location;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Illuminate\Support\Facades\DB;

class LocationService
{
    public function create(User $actor, array $data): Location
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($data): Location {
            Codes::lockPhysicalGraph();
            $buildingId = $this->activeBuildingId($data['building_id'] ?? null);
            $location = Location::create([
                'name' => $data['name'],
                'code' => Codes::next('SKX-R'),
                'building_id' => $buildingId,
                'notes' => $data['notes'] ?? null,
                'is_public' => $data['is_public'] ?? true,
            ]);
            Audit::record('location.created', $location, ['building_id' => $buildingId]);

            return $location;
        }, 3);
    }

    public function update(User $actor, Location $location, array $data): Location
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($location, $data): Location {
            Codes::lockPhysicalGraph();
            // The graph lock also protects building archive, so a selected
            // building cannot disappear between this check and the room write.
            $hasBuilding = array_key_exists('building_id', $data);
            $buildingId = $hasBuilding ? $this->activeBuildingId($data['building_id']) : null;
            $room = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
            abort_if($room->archived_at, 404);
            Version::check($room, $data['expected_version']);
            $previousBuildingId = $room->building_id;
            $room->fill([
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
                'is_public' => $data['is_public'],
            ]);
            if ($hasBuilding) {
                $room->building_id = $buildingId;
            }
            $room->version++;
            $room->save();
            Audit::record('location.updated', $room, ['from_building_id' => $previousBuildingId, 'to_building_id' => $room->building_id]);

            return $room;
        }, 3);
    }

    private function activeBuildingId(mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }
        $building = Building::whereKey($id)->lockForUpdate()->first();
        abort_unless($building && ! $building->archived_at, 422, 'Выберите действующее здание.');

        return $building->id;
    }

    /** Remove an empty room from active use while retaining historical links. */
    public function archive(User $actor, Location $location, int $expected): void
    {
        Access::check($actor, 'write');
        DB::transaction(function () use ($location, $expected): void {
            Codes::lockPhysicalGraph();
            $room = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
            Version::check($room, $expected);
            abort_if($room->archived_at, 409, 'Кабинет уже в архиве.');
            abort_if(Asset::where('location_id', $room->id)->whereNull('archived_at')->lockForUpdate()->get(['id'])->isNotEmpty(), 422, 'Сначала переместите или архивируйте имущество кабинета.');
            abort_if(Location::where('parent_id', $room->id)->whereNull('archived_at')->lockForUpdate()->get(['id'])->isNotEmpty(), 422, 'Сначала переместите или архивируйте дочерние помещения.');
            $room->archived_at = now();
            $room->version++;
            $room->save();
            Audit::record('location.archived', $room);
        }, 3);
    }
}
