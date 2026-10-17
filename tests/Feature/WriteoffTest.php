<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\AssetMovement;
use App\Models\AssetWriteoff;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\InventoryCampaign;
use App\Models\LicenseAssignment;
use App\Models\LicenseInstance;
use App\Models\Location;
use App\Models\MaintenancePlan;
use App\Models\PhysicalPlacement;
use App\Models\QrAlias;
use App\Models\SoftwareProduct;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AssetService;
use App\Services\QrResolver;
use App\Services\WriteoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WriteoffTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $admin;

    private Category $category;

    private Location $room;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->category = Category::create(['name' => 'ПК', 'slug' => 'writeoff-computers', 'domain' => 'it', 'kind' => 'computer']);
        $this->room = Location::create(['name' => 'Кабинет', 'code' => 'WO-ROOM']);
    }

    private function asset(array $attributes = []): Asset
    {
        return app(AssetService::class)->save($this->operator, $attributes + ['name' => 'Компьютер', 'category_id' => $this->category->id, 'location_id' => $this->room->id, 'status' => 'in_use', 'condition' => 'faulty', 'maintenance_policy' => 'none']);
    }

    private function writeOff(Asset $asset): Asset
    {
        return app(WriteoffService::class)->writeOff($this->operator, $asset, ['expected_version' => $asset->version, 'reason' => 'Невосстановимая неисправность']);
    }

    public function test_writeoff_moves_out_of_main_registry_preserves_snapshot_and_can_be_restored_explicitly(): void
    {
        $asset = $this->asset(['inventory_no' => 'INV-001', 'private_notes' => 'Внутренняя информация']);
        $this->actingAs($this->operator)->post(route('writeoffs.store', $asset), ['expected_version' => 1, 'reason' => 'Неисправен'])->assertRedirect(route('writeoffs.show', $asset));
        $this->assertSame('written_off', $asset->fresh()->status);
        $this->assertSame('INV-001', AssetWriteoff::firstOrFail()->snapshot['inventory_no']);
        $this->assertDatabaseHas('asset_movements', ['asset_id' => $asset->id, 'from_status' => 'in_use', 'to_status' => 'written_off', 'actor_id' => $this->operator->id]);
        $this->get(route('assets.index'))->assertOk()->assertViewHas('assets', fn ($rows) => $rows->total() === 0);
        $this->get(route('writeoffs.index'))->assertOk()->assertSee($asset->code);
        $this->get(route('writeoffs.show', $asset))->assertOk()->assertSee('Неисправен');
        $this->post(route('writeoffs.restore', $asset), ['expected_version' => 2, 'reason' => 'Ремонт выполнен', 'status' => 'stock', 'location_id' => $this->room->id])->assertRedirect(route('assets.show', $asset));
        $this->assertSame('stock', $asset->fresh()->status);
        $this->assertSame(3, $asset->fresh()->version);
        $this->assertNotNull(AssetWriteoff::firstOrFail()->restored_at);
        $this->get(route('assets.index'))->assertOk()->assertViewHas('assets', fn ($rows) => $rows->total() === 1);
        $this->get(route('writeoffs.show', $asset))->assertNotFound();
    }

    public function test_stale_writeoff_and_empty_reason_leave_asset_untouched(): void
    {
        $asset = $this->asset();
        $this->actingAs($this->operator)->postJson(route('writeoffs.store', $asset), ['expected_version' => 1, 'reason' => ''])->assertUnprocessable();
        $this->postJson(route('writeoffs.store', $asset), ['expected_version' => 2, 'reason' => 'Причина'])->assertStatus(409);
        $this->assertSame('in_use', $asset->fresh()->status);
        $this->assertSame(0, AssetWriteoff::count());
    }

    public function test_old_move_action_cannot_bypass_dependency_checks(): void
    {
        $asset = $this->asset();
        WorkOrder::create(['asset_id' => $asset->id, 'title' => 'Ремонт', 'created_by' => $this->operator->id, 'status' => 'open']);
        $this->actingAs($this->operator)->postJson(route('assets.move', $asset), ['expected_version' => 1, 'reason' => 'Обход старой формы', 'location_id' => $asset->location_id, 'status' => 'written_off'])->assertUnprocessable();
        $this->assertSame('in_use', $asset->fresh()->status);
        $this->assertSame(0, AssetWriteoff::count());
    }

    public function test_physical_and_descriptive_components_block_writeoff(): void
    {
        $parent = $this->asset();
        $child = $this->asset();
        PhysicalPlacement::create(['asset_id' => $child->id, 'parent_asset_id' => $parent->id, 'kind' => 'rack']);
        $this->actingAs($this->operator)->postJson(route('writeoffs.store', $parent), ['expected_version' => 1, 'reason' => 'Причина'])->assertUnprocessable();
        $this->postJson(route('writeoffs.store', $child), ['expected_version' => 1, 'reason' => 'Причина'])->assertUnprocessable();
        PhysicalPlacement::query()->delete();
        AssetComponent::create(['parent_asset_id' => $parent->id, 'component_type' => 'Память', 'description' => '16GB', 'quantity' => 2]);
        $this->postJson(route('writeoffs.store', $parent), ['expected_version' => 1, 'reason' => 'Причина'])->assertUnprocessable();
        $this->assertSame(0, AssetWriteoff::count());
    }

    public function test_active_license_plan_and_inventory_are_checked_before_writeoff(): void
    {
        $asset = $this->asset();
        $product = SoftwareProduct::create(['name' => 'Программа']);
        $license = LicenseInstance::create(['name' => 'Лицензия', 'code' => 'LIC-WO', 'software_product_id' => $product->id]);
        $assignment = LicenseAssignment::create(['asset_id' => $asset->id, 'license_instance_id' => $license->id, 'actor_id' => $this->operator->id, 'assigned_at' => now()->subSecond()]);
        $this->actingAs($this->operator)->postJson(route('writeoffs.store', $asset), ['expected_version' => 1, 'reason' => 'Причина'])->assertUnprocessable();
        $assignment->update(['released_at' => now()]);
        $plan = MaintenancePlan::create(['asset_id' => $asset->id, 'name' => 'Осмотр', 'mode' => 'calendar', 'interval_unit' => 'month', 'interval_count' => 6, 'status' => 'active', 'created_by' => $this->operator->id]);
        $campaign = InventoryCampaign::create(['name' => 'Обход', 'status' => 'active', 'scope' => [], 'created_by' => $this->operator->id]);
        DB::table('inventory_asset_reservations')->insert(['asset_id' => $asset->id, 'inventory_campaign_id' => $campaign->id]);
        $this->postJson(route('writeoffs.store', $asset), ['expected_version' => 1, 'reason' => 'Причина'])->assertUnprocessable();
        DB::table('inventory_asset_reservations')->delete();
        $this->post(route('writeoffs.store', $asset), ['expected_version' => 1, 'reason' => 'Причина'])->assertRedirect();
        $this->assertSame('written_off', $asset->fresh()->status);
        $this->assertSame('paused', $plan->fresh()->status);
        $this->assertSame('active', AssetWriteoff::firstOrFail()->snapshot['paused_maintenance_plans'][0]['status']);
        $this->post(route('writeoffs.restore', $asset), ['expected_version' => 2, 'reason' => 'Возврат', 'status' => 'stock', 'location_id' => null])->assertRedirect();
        $this->assertSame('paused', $plan->fresh()->status);
    }

    public function test_regular_edits_and_moves_cannot_restore_written_off_asset(): void
    {
        $asset = $this->writeOff($this->asset());
        $this->actingAs($this->operator)->postJson(route('assets.move', $asset), ['expected_version' => 2, 'location_id' => $asset->location_id, 'status' => 'stock', 'reason' => 'Обход восстановления'])->assertStatus(409);
        $this->patchJson(route('assets.update', $asset), ['expected_version' => 2, 'name' => 'Изменить', 'category_id' => $this->category->id, 'condition' => 'faulty', 'maintenance_policy' => 'none'])->assertStatus(409);
        $this->assertSame('written_off', $asset->fresh()->status);
        $this->assertSame(2, $asset->fresh()->version);
    }

    public function test_readonly_user_cannot_writeoff_and_operator_cannot_purge(): void
    {
        $asset = $this->asset();
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer)->postJson(route('writeoffs.store', $asset), ['expected_version' => 1, 'reason' => 'Причина'])->assertForbidden();
        $asset = $this->writeOff($asset);
        $this->actingAs($this->operator)->postJson(route('writeoffs.purge', $asset), ['expected_version' => 2, 'reason' => 'Удалить', 'confirmation_code' => $asset->code])->assertForbidden();
        $this->assertNotNull($asset->fresh());
        $this->assertSame([], Storage::disk('local')->allFiles('deletion-backups'));
    }

    public function test_purge_requires_exact_confirmation_current_version_and_writtenoff_status(): void
    {
        $asset = $this->asset();
        $this->actingAs($this->admin)->postJson(route('writeoffs.purge', $asset), ['expected_version' => 1, 'reason' => 'Удалить', 'confirmation_code' => $asset->code])->assertStatus(409);
        $asset = $this->writeOff($asset);
        $this->postJson(route('writeoffs.purge', $asset), ['expected_version' => 1, 'reason' => 'Удалить', 'confirmation_code' => $asset->code])->assertStatus(409);
        $this->postJson(route('writeoffs.purge', $asset), ['expected_version' => 2, 'reason' => 'Удалить', 'confirmation_code' => strtolower($asset->code)])->assertUnprocessable();
        $this->assertNotNull($asset->fresh());
        $this->assertSame([], Storage::disk('local')->allFiles('deletion-backups'));
    }

    public function test_manual_purge_keeps_backup_original_movement_history_and_reserved_identifiers(): void
    {
        $asset = $this->asset(['private_notes' => 'PRIVATE SNAPSHOT']);
        QrAlias::create(['alias' => 'asset-code:EQ-008888', 'target_type' => 'asset', 'target_id' => $asset->id]);
        AssetMovement::create(['asset_id' => $asset->id, 'from_location_id' => null, 'to_location_id' => $asset->location_id, 'from_status' => 'stock', 'to_status' => 'in_use', 'reason' => 'Исторический перенос', 'actor_id' => $this->operator->id, 'asset_version' => 1, 'occurred_at' => '2025-01-02 03:04:05']);
        $asset = $this->writeOff($asset);
        $this->actingAs($this->admin)->post(route('writeoffs.purge', $asset), ['expected_version' => 2, 'reason' => 'Утилизирован', 'confirmation_code' => $asset->code])->assertRedirect(route('writeoffs.index'));
        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('retired_asset_codes', ['code' => $asset->code, 'asset_uuid' => $asset->uuid, 'former_asset_id' => $asset->id]);
        $this->assertDatabaseHas('qr_aliases', ['alias' => 'asset-code:EQ-008888', 'target_id' => $asset->id]);
        $this->assertNull(app(QrResolver::class)->resolve('EQ-008888'));
        $this->get('/eq/EQ-008888')->assertNotFound();
        $this->get(route('public.asset', $asset))->assertNotFound();
        $record = AssetWriteoff::firstOrFail();
        $this->assertNull($record->asset_id);
        $this->assertNotNull($record->purged_at);
        Storage::disk('local')->assertExists($record->purge_backup_path);
        $encrypted = Storage::disk('local')->get($record->purge_backup_path);
        $this->assertStringNotContainsString('PRIVATE SNAPSHOT', $encrypted);
        $snapshot = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('PRIVATE SNAPSHOT', $snapshot['asset']['private_notes']);
        $this->assertSame('2025-01-02 03:04:05.000000', $snapshot['movements'][0]['occurred_at']);
        $audit = AuditEvent::where('event', 'asset.purged')->firstOrFail();
        $this->assertSame('Исторический перенос', $audit->data['movements_snapshot'][0]['reason']);
        $this->assertSame($this->operator->id, $audit->data['movements_snapshot'][0]['actor_id']);
        $this->assertSame(0, AssetMovement::where('asset_id', $asset->id)->count());
        DB::table('code_counters')->where('prefix', 'SKX-A')->update(['next_value' => 1]);
        $next = $this->asset();
        $this->assertNotSame($asset->code, $next->code);
        $this->assertNotSame($asset->id, $next->id);
    }

    public function test_historical_operations_block_hard_delete_without_destroying_their_history(): void
    {
        $asset = $this->asset();
        $work = WorkOrder::create(['asset_id' => $asset->id, 'title' => 'Завершённый ремонт', 'status' => 'closed', 'created_by' => $this->operator->id]);
        $asset = $this->writeOff($asset);
        $response = $this->actingAs($this->admin)->postJson(route('writeoffs.purge', $asset), ['expected_version' => 2, 'reason' => 'Удалить', 'confirmation_code' => $asset->code])->assertUnprocessable();
        $this->assertStringContainsString('обслуживанию', $response->json('message'));
        $this->assertNotNull($asset->fresh());
        $this->assertNotNull($work->fresh());
        $this->assertSame([], Storage::disk('local')->allFiles('deletion-backups'));
    }

    public function test_backup_failure_prevents_database_deletion(): void
    {
        $asset = $this->writeOff($this->asset());
        Storage::shouldReceive('disk')->with('local')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andThrow(new \RuntimeException('Backup unavailable'));
        Storage::shouldReceive('delete')->once()->andReturn(true);
        try {
            app(WriteoffService::class)->purge($this->admin, $asset, ['expected_version' => 2, 'reason' => 'Удалить', 'confirmation_code' => $asset->code]);
            $this->fail('Purge must fail without a private backup.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Backup unavailable', $error->getMessage());
        }
        $this->assertNotNull($asset->fresh());
        $this->assertDatabaseMissing('retired_asset_codes', ['code' => $asset->code]);
        $this->assertSame(0, AuditEvent::where('event', 'asset.purged')->count());
    }

    public function test_api_purge_replays_after_deletion_and_checks_current_scope_and_role_before_replay(): void
    {
        $asset = $this->writeOff($this->asset());
        $limited = $this->admin->createToken('Write only', ['read', 'write']);
        $full = $this->admin->createToken('Purge', ['read', 'write', 'purge-assets']);
        $payload = ['expected_version' => 2, 'reason' => 'Утилизирован', 'confirmation_code' => $asset->code];
        $url = '/api/v1/writeoffs/'.$asset->uuid.'/purge';
        $this->withToken($limited->plainTextToken)->withHeader('Idempotency-Key', 'writeoff-delete-replay-01')->postJson($url, $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $first = $this->withToken($full->plainTextToken)->postJson($url, $payload)->assertOk()->assertJsonPath('ok', true);
        $this->postJson($url, $payload)->assertOk()->assertExactJson($first->json());
        $this->admin->update(['role' => 'operator']);
        $this->app['auth']->forgetGuards();
        $this->postJson($url, $payload)->assertForbidden();
        $this->assertSame(1, AuditEvent::where('event', 'asset.purged')->count());
    }

    public function test_unused_plan_is_paused_then_backed_up_and_deleted_with_asset(): void
    {
        $asset = $this->asset();
        $plan = MaintenancePlan::create(['asset_id' => $asset->id, 'name' => 'Будущий осмотр', 'mode' => 'calendar', 'interval_unit' => 'month', 'interval_count' => 6, 'status' => 'active', 'next_due_on' => null, 'created_by' => $this->operator->id]);
        DB::table('maintenance_preset_applications')->insert(['asset_id' => $asset->id, 'preset_key' => 'test-preset', 'maintenance_plan_id' => $plan->id, 'preset_snapshot' => json_encode(['label' => 'Осмотр']), 'created_by' => $this->operator->id, 'created_at' => now()]);
        $asset = $this->writeOff($asset);
        $this->assertSame('paused', $plan->fresh()->status);
        $this->actingAs($this->admin)->post(route('writeoffs.purge', $asset), ['expected_version' => 2, 'reason' => 'Удалить', 'confirmation_code' => $asset->code])->assertRedirect();
        $this->assertDatabaseMissing('maintenance_plans', ['id' => $plan->id]);
        $this->assertDatabaseMissing('maintenance_preset_applications', ['asset_id' => $asset->id]);
        $audit = AuditEvent::where('event', 'asset.purged')->firstOrFail();
        $this->assertSame('Будущий осмотр', $audit->data['unused_maintenance_plans'][0]['name']);
        $this->assertNull($audit->data['unused_maintenance_plans'][0]['last_completed_on']);
        $this->assertSame('test-preset', $audit->data['maintenance_preset_applications'][0]['preset_key']);
    }
}
