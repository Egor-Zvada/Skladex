<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Building;
use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use App\Services\OperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingAssetContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_filter_by_building_or_unassigned_without_confusing_equal_room_names(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $first = Building::create(['name' => 'Первое здание']);
        $second = Building::create(['name' => 'Второе здание']);
        $one = Location::create(['name' => '101', 'code' => 'ROOM-B1', 'building_id' => $first->id]);
        $two = Location::create(['name' => '101', 'code' => 'ROOM-B2', 'building_id' => $second->id]);
        $legacy = Location::create(['name' => 'Без привязки', 'code' => 'ROOM-OLD']);
        $a = $this->asset('ASSET-B1', $one);
        $b = $this->asset('ASSET-B2', $two);
        $c = $this->asset('ASSET-OLD', $legacy);
        $d = $this->asset('ASSET-NO-ROOM');
        $this->actingAs($viewer);
        $this->get(route('assets.index', ['building_id' => $first->id]))->assertOk()->assertViewHas('assets', fn ($assets) => $assets->pluck('uuid')->all() === [$a->uuid]);
        $this->get(route('assets.index', ['building_id' => $second->id]))->assertOk()->assertViewHas('assets', fn ($assets) => $assets->pluck('uuid')->all() === [$b->uuid]);
        $this->get(route('assets.index', ['building_id' => 'unassigned']))->assertOk()->assertViewHas('assets', fn ($assets) => $assets->total() === 2 && $assets->pluck('uuid')->contains($c->uuid) && $assets->pluck('uuid')->contains($d->uuid));
        $this->getJson(route('assets.index', ['building_id' => 'invalid']))->assertUnprocessable();
    }

    public function test_guest_building_context_is_allowlisted_and_follows_room_assignment(): void
    {
        $first = Building::create(['name' => 'Первое здание', 'address' => 'PRIVATE-ADDRESS', 'notes' => 'PRIVATE-BUILDING-NOTES']);
        $second = Building::create(['name' => 'Второе здание']);
        $room = Location::create(['name' => '101', 'code' => 'ROOM-PUBLIC', 'building_id' => $first->id]);
        $asset = $this->asset('ASSET-PUBLIC', $room);
        $response = $this->getJson(route('public.asset', $asset))->assertOk()->assertJsonPath('data.location.building.name', $first->name);
        $this->assertSame(['id', 'name'], array_keys($response->json('data.location.building')));
        $response->assertDontSee('PRIVATE-ADDRESS')->assertDontSee('PRIVATE-BUILDING-NOTES');
        $this->getJson(route('public.location', $room))->assertOk()->assertJsonPath('data.building.id', $first->uuid)->assertDontSee('PRIVATE-ADDRESS');
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($actor)->patch(route('locations.update', $room), ['name' => '101', 'is_public' => true, 'expected_version' => 1, 'building_id' => $second->id])->assertRedirect();
        $this->getJson(route('public.asset', $asset))->assertOk()->assertJsonPath('data.id', $asset->uuid)->assertJsonPath('data.location.building.name', $second->name);
        $this->assertSame('ASSET-PUBLIC', $asset->fresh()->code);
    }

    public function test_inventory_keeps_full_building_and_room_labels_as_immutable_facts(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->actingAs($actor);
        $name = str_repeat('З', 250);
        $building = Building::create(['name' => $name]);
        $room = Location::create(['name' => str_repeat('К', 240), 'code' => 'ROOM-SNAPSHOT', 'building_id' => $building->id]);
        $asset = $this->asset('ASSET-SNAPSHOT', $room);
        $operations = app(OperationsService::class);
        $campaign = $operations->createCampaign($actor, ['name' => 'Fixture campaign', 'asset_uuids' => [$asset->uuid]]);
        $campaign = $operations->startCampaign($actor, $campaign, $campaign->version);
        $observation = $operations->observe($actor, $campaign, ['expected_version' => $campaign->version, 'code' => $asset->code, 'location_uuid' => $room->uuid, 'client_key' => 'building-snapshot-once', 'method' => 'qr']);
        $label = $name.' · '.$room->name;
        $this->assertSame($label, $campaign->snapshotItems()->firstOrFail()->location_name);
        $this->assertSame($label, $observation->location_name);
        $building->update(['name' => 'Уточнённое название']);
        $this->assertSame($label, $campaign->snapshotItems()->firstOrFail()->location_name);
        $this->assertSame($label, $observation->fresh()->location_name);
    }

    private function asset(string $code, ?Location $room = null): Asset
    {
        $category = Category::firstOrCreate(['slug' => 'building-context'], ['name' => 'Fixture category', 'domain' => 'it', 'kind' => 'standard']);

        return Asset::create(['code' => $code, 'name' => $code, 'category_id' => $category->id, 'location_id' => $room?->id]);
    }
}
