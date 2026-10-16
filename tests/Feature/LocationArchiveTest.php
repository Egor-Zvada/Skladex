<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LocationArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_with_active_equipment_cannot_be_archived_then_history_is_retained(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($actor);
        $room = Location::create(['code' => 'SKX-R-ARCHIVE', 'name' => 'Fixture room']);
        $category = Category::create(['name' => 'Fixture', 'slug' => 'archive-fixture', 'domain' => 'it', 'kind' => 'standard']);
        $asset = Asset::create(['name' => 'Fixture server', 'code' => 'SKX-A-ARCHIVE', 'category_id' => $category->id, 'location_id' => $room->id]);
        $this->assertRejected(fn () => app(LocationService::class)->archive($actor, $room, 1), 422);
        $this->assertNull($room->fresh()->archived_at);
        $asset->update(['archived_at' => now()]);
        app(LocationService::class)->archive($actor, $room, 1);
        $this->assertNotNull($room->fresh()->archived_at);
        $this->assertSame(2, $room->fresh()->version);
        $this->assertSame($room->id, $asset->fresh()->location_id);
        $this->assertDatabaseHas('audit_events', ['event' => 'location.archived', 'subject_id' => $room->id, 'actor_id' => $actor->id]);
        $this->get(route('locations.show', $room))->assertNotFound();
        $this->get(route('public.location', $room))->assertNotFound();
    }

    public function test_viewer_stale_version_and_active_child_room_are_rejected(): void
    {
        $room = Location::create(['code' => 'SKX-R-PARENT', 'name' => 'Fixture parent']);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->assertRejected(fn () => app(LocationService::class)->archive($viewer, $room, 1), 403);
        $this->assertRejected(fn () => app(LocationService::class)->archive($operator, $room, 99), 409);
        Location::create(['code' => 'SKX-R-CHILD', 'name' => 'Fixture child', 'parent_id' => $room->id]);
        $this->assertRejected(fn () => app(LocationService::class)->archive($operator, $room, 1), 422);
        $this->assertNull($room->fresh()->archived_at);
    }

    private function assertRejected(callable $action, int $expected): void
    {
        try {
            $action();
            $this->fail('Archive unexpectedly succeeded');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($expected, $e->getStatusCode());
        }
    }
}
