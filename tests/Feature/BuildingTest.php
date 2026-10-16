<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Location;
use App\Models\User;
use App\Services\BuildingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildingTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_create_is_idempotent_and_notes_are_not_serialized(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $token = $operator->createToken('fixture', ['read', 'write'])->plainTextToken;
        $command = ['name' => 'ВВЕ', 'address' => 'Тестовый адрес', 'notes' => 'PRIVATE BUILDING NOTE', 'uuid' => (string) Str::uuid(), 'version' => 777];
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'building-create-command-01'];
        $first = $this->postJson('/api/v1/buildings', $command, $headers)->assertCreated();
        $this->assertNotSame($command['uuid'], $first->json('data.uuid'));
        $first->assertJsonPath('data.version', 1)->assertJsonPath('data.name', 'ВВЕ')->assertJsonMissingPath('data.notes')->assertDontSee('PRIVATE BUILDING NOTE');
        $second = $this->postJson('/api/v1/buildings', $command, $headers)->assertCreated();
        $this->assertSame($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertDatabaseCount('buildings', 1);
        $this->assertDatabaseHas('buildings', ['notes' => 'PRIVATE BUILDING NOTE']);
        $this->assertDatabaseHas('audit_events', ['event' => 'building.created', 'actor_id' => $operator->id]);
        $this->postJson('/api/v1/buildings', ['name' => 'Другое имя'], $headers)->assertConflict();
        $this->assertDatabaseCount('buildings', 1);
    }

    public function test_writes_require_active_writer_and_scoped_pat_and_key(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $headers = ['Idempotency-Key' => 'building-permission-command'];
        $viewerToken = $viewer->createToken('fixture', ['read', 'write'])->plainTextToken;
        $readToken = $operator->createToken('read-only fixture', ['read'])->plainTextToken;
        $writeToken = $operator->createToken('writer fixture', ['read', 'write'])->plainTextToken;
        $this->postJson('/api/v1/buildings', ['name' => 'Fixture'], $headers + ['Authorization' => 'Bearer '.$viewerToken])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/buildings', ['name' => 'Fixture'], $headers + ['Authorization' => 'Bearer '.$readToken])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/buildings', ['name' => 'Fixture'], ['Authorization' => 'Bearer '.$writeToken])->assertUnprocessable();
        $operator->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/buildings', ['name' => 'Fixture'], $headers + ['Authorization' => 'Bearer '.$writeToken])->assertForbidden();
        $this->assertDatabaseCount('buildings', 0);
    }

    public function test_web_writer_can_edit_and_stale_update_does_not_overwrite(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator);
        $building = app(BuildingService::class)->create($operator, ['name' => 'Исходное имя']);
        $this->patch(route('buildings.update', $building), ['name' => 'Центр единоборств', 'expected_version' => 1])->assertRedirect();
        $this->assertSame('Центр единоборств', $building->fresh()->name);
        $this->assertSame(2, $building->fresh()->version);
        $this->patchJson(route('buildings.update', $building), ['name' => 'Устаревшая правка', 'expected_version' => 1])->assertConflict();
        $this->assertSame('Центр единоборств', $building->fresh()->name);
        $this->assertDatabaseHas('audit_events', ['event' => 'building.updated', 'subject_id' => $building->id]);
    }

    public function test_even_empty_active_room_blocks_building_archive_and_archived_links_remain(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($operator);
        $building = Building::create(['name' => 'Fixture building']);
        $room = Location::create(['code' => 'SKX-R-BUILDING', 'name' => 'Пустой кабинет', 'building_id' => $building->id]);
        $this->postJson(route('buildings.archive', $building), ['expected_version' => 1])->assertUnprocessable();
        $this->assertNull($building->fresh()->archived_at);
        $room->update(['archived_at' => now()]);
        $this->postJson(route('buildings.archive', $building), ['expected_version' => 99])->assertConflict();
        $this->post(route('buildings.archive', $building), ['expected_version' => 1])->assertRedirect(route('buildings.index'));
        $this->assertNotNull($building->fresh()->archived_at);
        $this->assertSame(2, $building->fresh()->version);
        $this->assertSame($building->id, $room->fresh()->building_id);
        $this->assertDatabaseHas('audit_events', ['event' => 'building.archived', 'subject_id' => $building->id]);
        $this->getJson(route('buildings.show', $building))->assertNotFound();
        $this->patchJson(route('buildings.update', $building), ['name' => 'Cannot resurrect', 'expected_version' => 2])->assertConflict();
    }

    public function test_existing_rooms_remain_unassigned_and_read_api_counts_only_active_rooms(): void
    {
        $building = Building::create(['name' => 'Fixture building', 'notes' => 'PRIVATE NOTE']);
        $other = Building::create(['name' => 'Archived building', 'archived_at' => now()]);
        $legacy = Location::create(['name' => 'Старый кабинет', 'code' => 'OLD-ROOM']);
        Location::create(['name' => 'Активный кабинет', 'code' => 'SKX-R-ACTIVE', 'building_id' => $building->id]);
        Location::create(['name' => 'Архивный кабинет', 'code' => 'SKX-R-ARCHIVED', 'building_id' => $building->id, 'archived_at' => now()]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $headers = ['Authorization' => 'Bearer '.$viewer->createToken('fixture', ['read'])->plainTextToken];
        $this->getJson('/api/v1/buildings', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.locations_count', 1)->assertJsonPath('meta.total', 1)->assertDontSee('PRIVATE NOTE');
        $this->getJson('/api/v1/buildings/'.$building->uuid, $headers)->assertOk()->assertJsonCount(1, 'locations')->assertJsonPath('meta.total', 1)->assertJsonPath('locations.0.name', 'Активный кабинет')->assertJsonMissingPath('data.notes');
        $this->getJson('/api/v1/buildings/'.$other->uuid, $headers)->assertNotFound();
        $this->getJson('/api/v1/buildings/'.$building->id, $headers)->assertNotFound();
        $this->assertNull($legacy->fresh()->building_id);
    }

    public function test_web_viewer_cannot_modify_building_and_guest_cannot_browse(): void
    {
        $building = Building::create(['name' => 'Fixture building']);
        $this->get(route('buildings.index'))->assertRedirect(route('login'));
        $this->get(route('buildings.show', $building))->assertRedirect(route('login'));
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer);
        $this->post(route('buildings.store'), ['name' => 'No permission'])->assertForbidden();
        $this->patch(route('buildings.update', $building), ['name' => 'No permission', 'expected_version' => 1])->assertForbidden();
        $this->post(route('buildings.archive', $building), ['expected_version' => 1])->assertForbidden();
        $this->assertSame('Fixture building', $building->fresh()->name);
        $this->assertNull($building->fresh()->archived_at);
    }
}
