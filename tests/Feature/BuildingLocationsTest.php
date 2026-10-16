<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Location;
use App\Models\QrAlias;
use App\Models\User;
use App\Services\BuildingService;
use App\Services\LocationService;
use App\Services\QrResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class BuildingLocationsTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($this->operator);
    }

    public function test_legacy_rooms_remain_unassigned_until_operator_selects_a_building_and_qr_stays_valid(): void
    {
        $room = Location::create(['code' => 'каб-101/Б', 'name' => '101']);
        QrAlias::create(['alias' => 'location-id:каб-101/Б', 'target_type' => 'location', 'target_id' => $room->id]);
        $building = Building::create(['name' => 'ВВЕ']);
        Building::create(['name' => 'Центр единоборств']);
        $uuid = $room->uuid;
        $this->assertNull($room->fresh()->building_id);

        $this->patch(route('locations.update', $room), ['name' => '101', 'is_public' => true, 'expected_version' => 1, 'building_id' => $building->id])->assertRedirect();

        $this->assertSame($building->id, $room->fresh()->building_id);
        $this->assertSame('каб-101/Б', $room->fresh()->code);
        $this->assertSame($uuid, $room->fresh()->uuid);
        $this->assertSame($room->id, app(QrResolver::class)->resolve('http://skladex.vve.local/cabinet.php?id='.rawurlencode('каб-101/Б'))->id);
        $this->assertDatabaseHas('audit_events', ['event' => 'location.updated', 'subject_id' => $room->id, 'actor_id' => $this->operator->id]);
    }

    public function test_equal_room_names_across_buildings_have_distinct_codes_and_clear_display_names(): void
    {
        $first = Building::create(['name' => 'ВВЕ']);
        $second = Building::create(['name' => 'Центр единоборств']);
        $one = app(LocationService::class)->create($this->operator, ['name' => '101', 'building_id' => $first->id]);
        $two = app(LocationService::class)->create($this->operator, ['name' => '101', 'building_id' => $second->id]);
        $this->assertNotSame($one->code, $two->code);
        $this->assertSame('ВВЕ · 101', $one->fresh()->display_name);
        $this->assertSame('Центр единоборств · 101', $two->fresh()->display_name);
        $this->get(route('locations.index'))->assertOk()->assertSee('ВВЕ')->assertSee('Центр единоборств');
        $this->get(route('locations.show', $two))->assertOk()->assertSee('Центр единоборств · 101');
    }

    public function test_location_update_without_new_field_preserves_assignment_and_explicit_null_clears_it(): void
    {
        $building = Building::create(['name' => 'ВВЕ']);
        $room = app(LocationService::class)->create($this->operator, ['name' => '101', 'building_id' => $building->id]);
        $this->patch(route('locations.update', $room), ['name' => '102', 'is_public' => true, 'expected_version' => 1])->assertRedirect();
        $this->assertSame($building->id, $room->fresh()->building_id);
        $this->patch(route('locations.update', $room), ['name' => '102', 'is_public' => true, 'expected_version' => 2, 'building_id' => null])->assertRedirect();
        $this->assertNull($room->fresh()->building_id);
        $this->assertSame('102', $room->fresh()->display_name);
    }

    public function test_rooms_can_be_filtered_by_building_or_unassigned_without_changing_records(): void
    {
        $building = Building::create(['name' => 'ВВЕ']);
        Location::create(['code' => 'ROOM-ASSIGNED', 'name' => 'Assigned fixture', 'building_id' => $building->id]);
        Location::create(['code' => 'ROOM-UNASSIGNED', 'name' => 'Unassigned fixture']);
        Location::create(['code' => 'ROOM-ARCHIVED', 'name' => 'Archived fixture', 'building_id' => $building->id, 'archived_at' => now()]);
        $this->get(route('locations.index', ['building_id' => $building->id]))->assertOk()->assertSee('Assigned fixture')->assertDontSee('Unassigned fixture')->assertDontSee('Archived fixture');
        $this->get(route('locations.index', ['building_id' => 'unassigned']))->assertOk()->assertSee('Unassigned fixture')->assertDontSee('Assigned fixture');
        $this->assertSame(1, Location::whereNull('building_id')->count());
        $this->getJson(route('locations.index', ['building_id' => ['bad']]))->assertUnprocessable();
    }

    public function test_archived_building_is_rejected_at_validation_and_service_checks_current_state(): void
    {
        $building = Building::create(['name' => 'ВВЕ']);
        app(BuildingService::class)->archive($this->operator, $building, 1);
        $this->postJson(route('locations.store'), ['name' => '101', 'building_id' => $building->id])->assertUnprocessable();
        $this->assertRejected(fn () => app(LocationService::class)->create($this->operator, ['name' => '101', 'building_id' => $building->id]), 422);
        $room = Location::create(['name' => '102', 'code' => 'ROOM-ACTIVE']);
        $this->assertRejected(fn () => app(LocationService::class)->update($this->operator, $room, ['name' => 'Changed', 'is_public' => true, 'expected_version' => 1, 'building_id' => $building->id]), 422);
        $this->assertSame('102', $room->fresh()->name);
        $this->assertDatabaseCount('locations', 1);
    }

    public function test_stale_room_version_cannot_move_it_to_another_building_or_restore_archived_room(): void
    {
        $first = Building::create(['name' => 'ВВЕ']);
        $second = Building::create(['name' => 'Центр единоборств']);
        $room = app(LocationService::class)->create($this->operator, ['name' => '101', 'building_id' => $first->id]);
        $this->patchJson(route('locations.update', $room), ['name' => 'Changed', 'is_public' => true, 'expected_version' => 99, 'building_id' => $second->id])->assertConflict();
        $this->assertSame($first->id, $room->fresh()->building_id);
        app(LocationService::class)->archive($this->operator, $room, 1);
        $this->patchJson(route('locations.update', $room), ['name' => 'Restore', 'is_public' => true, 'expected_version' => 2, 'building_id' => $second->id])->assertNotFound();
        $this->assertSame($first->id, $room->fresh()->building_id);
        $this->assertNotNull($room->fresh()->archived_at);
    }

    public function test_api_room_building_is_only_a_basic_projection(): void
    {
        $building = Building::create(['name' => 'ВВЕ', 'address' => 'INTERNAL ADDRESS', 'notes' => 'PRIVATE BUILDING NOTE']);
        $room = Location::create(['name' => '101', 'code' => 'ROOM-API', 'building_id' => $building->id, 'notes' => 'PRIVATE ROOM NOTE']);
        $this->app['auth']->forgetGuards();
        $token = $this->operator->createToken('room-read fixture', ['read'])->plainTextToken;
        $response = $this->getJson('/api/v1/locations/'.$room->uuid, ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertSame(['id', 'uuid', 'name'], array_keys($response->json('data.building')));
        $response->assertJsonPath('data.building.name', 'ВВЕ')->assertDontSee('INTERNAL ADDRESS')->assertDontSee('PRIVATE BUILDING NOTE')->assertDontSee('PRIVATE ROOM NOTE');
    }

    public function test_viewer_cannot_assign_a_room_or_see_edit_forms_but_can_read_buildings(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $building = Building::create(['name' => 'ВВЕ']);
        $room = Location::create(['name' => '101', 'code' => 'ROOM-VIEWER']);
        $this->actingAs($viewer);
        $this->get(route('buildings.index'))->assertOk()->assertSee('ВВЕ')->assertDontSee('Создать здание');
        $this->get(route('locations.show', $room))->assertOk()->assertDontSee('Изменить кабинет');
        $this->patchJson(route('locations.update', $room), ['name' => '101', 'is_public' => true, 'expected_version' => 1, 'building_id' => $building->id])->assertForbidden();
        $this->assertNull($room->fresh()->building_id);
    }

    private function assertRejected(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Operation unexpectedly succeeded.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
