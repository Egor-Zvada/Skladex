<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Category;
use App\Models\Document;
use App\Models\Location;
use App\Models\PhysicalPlacement;
use App\Models\QrAlias;
use App\Models\User;
use App\Services\AssetService;
use App\Services\QrResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoreWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Category $computer;

    private Category $rack;

    private Location $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->computer = Category::create(['name' => 'Компьютеры', 'slug' => 'test-computers', 'domain' => 'it', 'kind' => 'computer', 'maintenance_policy' => 'unspecified']);
        $this->rack = Category::create(['name' => 'Шкафы IT', 'slug' => 'test-racks', 'domain' => 'it', 'kind' => 'rack', 'maintenance_policy' => 'unspecified']);
        $this->room = Location::create(['code' => 'SKX-R-T1', 'name' => 'Кабинет 1']);
    }

    private function asset(array $extra = []): Asset
    {
        return app(AssetService::class)->save($this->operator, array_merge(['name' => 'Тестовый ПК', 'category_id' => $this->computer->id, 'location_id' => $this->room->id, 'status' => 'stock', 'condition' => 'unknown', 'maintenance_policy' => 'unspecified'], $extra));
    }

    public function test_legacy_code_and_numeric_id_resolve_to_different_explicit_targets(): void
    {
        $a = $this->asset(['name' => 'Фактический EQ-000497']);
        $b = $this->asset(['name' => 'Фактический старый ID 497']);
        QrAlias::create(['alias' => 'asset-code:EQ-000497', 'target_type' => 'asset', 'target_id' => $a->id]);
        QrAlias::create(['alias' => 'asset-id:497', 'target_type' => 'asset', 'target_id' => $b->id]);
        $this->get('/eq/EQ-000497')->assertRedirect(route('public.asset', $a));
        $this->get('/equipment_card.php?id=497')->assertRedirect(route('public.asset', $b));
        $this->assertSame($a->id, app(QrResolver::class)->resolve('http://skladex.vve.local/eq/EQ-000497')->id);
        $this->assertNull(app(QrResolver::class)->resolve('http://evil.invalid/eq/EQ-000497'));
        $this->get('/eq/EQ-'.str_pad((string) $b->id, 6, '0', STR_PAD_LEFT))->assertNotFound();
    }

    public function test_unicode_room_alias_survives_url_encoding_and_has_no_numeric_fallback(): void
    {
        QrAlias::create(['alias' => 'location-id:каб-01/Б', 'target_type' => 'location', 'target_id' => $this->room->id]);
        $this->get('/cabinet.php?id='.rawurlencode('каб-01/Б'))->assertRedirect(route('public.location', $this->room));
        $this->get('/cabinet.php?id='.$this->room->id)->assertNotFound();
    }

    public function test_public_payload_is_allowlisted_and_private_room_blocks_all_public_paths(): void
    {
        $asset = $this->asset(['responsible_name' => 'SECRET PERSON', 'private_notes' => 'SECRET NOTE', 'inventory_no' => 'SECRET INVENTORY', 'serial_no' => 'SECRET SERIAL', 'specifications' => ['ip' => '10.1.2.3']]);
        $response = $this->getJson(route('public.asset', $asset))->assertOk();
        $this->assertSame(['id', 'code', 'name', 'category', 'location', 'status', 'condition'], array_keys($response->json('data')));
        foreach (['SECRET', '10.1.2.3', 'private_notes', 'responsible_name', 'serial_no'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->get(route('public.asset.qr', $asset))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->room->update(['is_public' => false]);
        $this->getJson(route('public.asset', $asset))->assertNotFound();
        $this->get(route('public.asset.qr', $asset))->assertNotFound();
        $this->getJson(route('public.location', $this->room))->assertNotFound();
    }

    public function test_bearer_scope_and_idempotency_are_enforced_before_replay(): void
    {
        $read = $this->operator->createToken('Read', ['read'], now()->addDay());
        $write = $this->operator->createToken('Write', ['read', 'write'], now()->addDay());
        $input = ['name' => 'API ПК', 'category_id' => $this->computer->id, 'condition' => 'unknown', 'maintenance_policy' => 'unspecified'];
        $this->withToken($read->plainTextToken)->withHeader('Idempotency-Key', 'asset-create-test-0001')->postJson('/api/v1/assets', $input)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $first = $this->withToken($write->plainTextToken)->postJson('/api/v1/assets', $input)->assertCreated();
        $this->postJson('/api/v1/assets', $input)->assertCreated()->assertExactJson($first->json());
        $this->postJson('/api/v1/assets', array_merge($input, ['name' => 'Другой']))->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->withToken($read->plainTextToken)->postJson('/api/v1/assets', $input)->assertForbidden();
        $this->assertSame(1, Asset::count());
        $this->assertStringStartsWith('SKX-A-', $first->json('data.code'));
    }

    public function test_edit_cannot_silently_move_or_change_status(): void
    {
        $asset = $this->asset();
        $this->actingAs($this->operator)->patchJson(route('assets.update', $asset), ['name' => 'Новое имя', 'category_id' => $this->computer->id, 'condition' => 'working', 'maintenance_policy' => 'none', 'location_id' => null, 'status' => 'repair', 'expected_version' => 1])->assertUnprocessable();
        $this->assertSame('stock', $asset->fresh()->status);
        $this->assertSame($this->room->id, $asset->fresh()->location_id);
    }

    public function test_component_needs_no_card_and_existing_component_cannot_have_two_parents(): void
    {
        $pc = $this->asset();
        $other = $this->asset();
        $child = $this->asset(['name' => 'Накопитель']);
        $this->actingAs($this->operator)->postJson(route('assets.components.store', $pc), ['expected_version' => 1, 'component_type' => 'Память', 'description' => '16GB DDR4', 'quantity' => 2])->assertRedirect();
        $this->assertSame(3, Asset::count());
        $this->postJson(route('assets.components.store', $pc), ['expected_version' => 2, 'component_type' => 'SSD', 'child_asset_id' => $child->id, 'expected_child_version' => 1, 'quantity' => 1])->assertRedirect();
        $this->postJson(route('assets.components.store', $other), ['expected_version' => 1, 'component_type' => 'SSD', 'child_asset_id' => $child->id, 'expected_child_version' => 2, 'quantity' => 1])->assertStatus(409);
        $this->assertSame(1, PhysicalPlacement::where('asset_id', $child->id)->count());
        $this->get(route('assets.show', $pc))->assertOk()->assertSee('16GB DDR4');
    }

    public function test_move_requires_fresh_complete_subtree_and_writes_history_for_every_item(): void
    {
        $rack = $this->asset(['category_id' => $this->rack->id, 'name' => 'Стойка']);
        $pc = $this->asset();
        $child = $this->asset(['name' => 'Компонент']);
        $service = app(AssetService::class);
        $service->component($this->operator, $pc, ['expected_version' => 1, 'child_asset_id' => $child->id, 'expected_child_version' => 1, 'component_type' => 'SSD', 'quantity' => 1]);
        $service->rack($this->operator, $pc->fresh(), $rack, 2, '2U', 1);
        $room2 = Location::create(['code' => 'SKX-R-T2', 'name' => 'Кабинет 2']);
        $input = ['expected_version' => 2, 'location_id' => $room2->id, 'reason' => 'Перенос стойки', 'include_children' => true, 'expected_contents' => [$pc->uuid => 3, $child->uuid => 1]];
        $this->actingAs($this->operator)->postJson(route('assets.move', $rack), $input)->assertStatus(409);
        $this->assertSame($this->room->id, $rack->fresh()->location_id);
        $input['expected_contents'][$child->uuid] = 2;
        $this->postJson(route('assets.move', $rack), $input)->assertRedirect();
        $this->assertSame(3, Asset::where('location_id', $room2->id)->count());
        $this->assertSame(3, AssetMovement::where('reason', 'Перенос стойки')->count());
        $this->assertSame(4, $pc->fresh()->version);
    }

    public function test_stale_rack_and_component_selections_are_rejected(): void
    {
        $rack = $this->asset(['category_id' => $this->rack->id]);
        $pc = $this->asset();
        $child = $this->asset();
        $rack->increment('version');
        $child->increment('version');
        $this->actingAs($this->operator)->postJson(route('assets.rack', $pc), ['expected_version' => 1, 'rack_id' => $rack->id, 'expected_rack_version' => 1])->assertStatus(409);
        $this->postJson(route('assets.components.store', $pc), ['expected_version' => 1, 'child_asset_id' => $child->id, 'expected_child_version' => 1, 'quantity' => 1, 'component_type' => 'SSD'])->assertStatus(409);
        $this->assertSame(0, PhysicalPlacement::count());
    }

    public function test_private_document_is_downloadable_only_after_login_and_not_publicly_served(): void
    {
        Storage::fake('local');
        $asset = $this->asset();
        $this->actingAs($this->operator)->post(route('documents.store'), ['kind' => 'asset', 'target' => $asset->uuid, 'file' => UploadedFile::fake()->createWithContent('акт.txt', 'Тестовый акт')])->assertRedirect();
        $doc = Document::firstOrFail();
        $this->get(route('documents.download', $doc))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/storage/'.$doc->path)->assertNotFound();
        auth()->logout();
        $this->get(route('documents.download', $doc))->assertRedirect(route('login'));
    }

    public function test_all_main_screens_render_for_writer_and_viewer(): void
    {
        $asset = $this->asset();
        foreach (['operator', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            $this->actingAs($user);
            foreach (['dashboard', 'assets.index', 'locations.index', 'kits.index', 'inventory.index', 'maintenance.index', 'software.index', 'licenses.index', 'labels.index', 'settings.index', 'api.reference'] as $route) {
                $this->get(route($route))->assertOk();
            }
            $this->get(route('assets.show', $asset))->assertOk();
            $this->get(route('locations.show', $this->room))->assertOk();
        }
    }
}
