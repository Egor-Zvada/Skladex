<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\LegacyImport;
use App\Models\LegacyRecord;
use App\Models\Location;
use App\Models\QrAlias;
use App\Models\User;
use App\Services\LegacyImportService;
use App\Services\QrResolver;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Asset $newAsset;

    private LegacyImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::factory()->create(['username' => 'admin', 'name' => 'Current administrator', 'email' => 'current@example.test', 'password' => 'CurrentTestPassword-123', 'role' => 'admin', 'is_active' => true]);
        $this->newAsset = Asset::create(['code' => 'SKX-A-000001', 'name' => 'Already created object', 'inventory_no' => 'SHARED-INV', 'category_id' => Category::where('slug', 'computers')->firstOrFail()->id, 'private_notes' => 'Keep new notes', 'specifications' => ['memory' => 'fixture'], 'version' => 3]);
        DB::table('code_counters')->insert(['prefix' => 'SKX-A', 'next_value' => 2]);
        $this->service = app(LegacyImportService::class);
    }

    private function dataset(): array
    {
        $date = '2025-09-15 10:20:30';
        $equipment = fn (int $id, string $code, ?int $category, string $status) => ['id' => $id, 'code' => $code, 'name' => 'Fixture equipment '.$id, 'inventory_no' => 'SHARED-INV', 'description' => $id === 29 ? str_repeat('Заметка. ', 1500) : 'Fixture note', 'status' => $status, 'cabinet_id' => 'Сe-Cab-009', 'category_id' => $category, 'created_by' => null, 'updated_by' => null, 'created_at' => $date, 'updated_at' => $date];

        return [
            'format' => 'skladex-legacy-v1',
            'source' => ['system' => 'skladex-v1', 'host' => '192.168.110.200', 'exported_at' => '2026-10-16T00:00:00Z', 'timezone' => 'UTC'],
            'tables' => [
                'users' => [['id' => 1, 'login' => 'admin', 'role' => 'admin', 'is_active' => 1, 'created_at' => $date, 'updated_at' => $date]],
                'cabinets' => [['id' => 'Сe-Cab-009', 'title' => 'Fixture room one', 'created_at' => $date, 'updated_at' => $date], ['id' => 'Ce-Cab-009', 'title' => null, 'created_at' => $date, 'updated_at' => $date]],
                'categories' => [['id' => 1, 'name' => 'Моноблоки'], ['id' => 11, 'name' => 'Звуковое оборудование'], ['id' => 12, 'name' => 'Световое оборудование']],
                'equipment' => [$equipment(29, 'EQ-000001', 1, 'in_use'), $equipment(30, 'EQ-000900', 12, 'reserve'), $equipment(31, 'EQ-000031', null, 'repair'), $equipment(32, 'EQ-000032', 11, 'decommissioned')],
                'equipment_moves' => [['id' => 7, 'equipment_id' => 29, 'from_cabinet_id' => 'Сe-Cab-009', 'to_cabinet_id' => 'Ce-Cab-009', 'moved_by' => 1, 'note' => 'Original movement note', 'moved_at' => $date]],
                'equipment_status_history' => [], 'attachments' => [],
                'audit_log' => [
                    ['id' => 10, 'entity' => 'equipment', 'entity_id' => '29', 'action' => 'insert', 'snapshot' => ['id' => 29, 'code' => 'EQ-000001', 'name' => 'Original name', 'cabinet_id' => 'Сe-Cab-009'], 'created_at' => $date],
                    ['id' => 11, 'entity' => 'equipment', 'entity_id' => '29', 'action' => 'update', 'snapshot' => ['id' => 29, 'code' => 'EQ-000001', 'name' => 'Updated name'], 'created_at' => $date],
                    ['id' => 12, 'entity' => 'equipment', 'entity_id' => '29', 'action' => 'update', 'snapshot' => ['id' => 29, 'code' => 'EQ-000001', 'name' => 'Updated name'], 'created_at' => $date],
                    ['id' => 13, 'entity' => 'equipment', 'entity_id' => '999', 'action' => 'delete', 'snapshot' => ['id' => 999, 'code' => 'EQ-000999', 'name' => 'Deleted fixture'], 'created_at' => $date],
                ],
                'qr_codes' => [
                    ['id' => 1, 'target_type' => 'cabinet', 'target_code' => 'CAB-001', 'purpose' => 'card', 'content' => 'http://skaladex.vve.local/cabinet/CAB-001', 'image_path' => null, 'is_active' => 1, 'created_by' => 1, 'created_at' => $date],
                    ['id' => 2, 'target_type' => 'equipment', 'target_code' => 'EQ-000001', 'purpose' => 'card', 'content' => 'http://skaladex.vve.local/admin/equipment.php?code=EQ-000001', 'image_path' => null, 'is_active' => 1, 'created_by' => 1, 'created_at' => $date],
                ],
            ],
        ];
    }

    private function fails(array $dataset, int $status): void
    {
        try {
            $this->service->apply($this->admin, $dataset);
            $this->fail('Expected an import failure.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_plan_is_read_only_and_reports_dangling_qr_without_creating_its_room(): void
    {
        $beforeCounters = DB::table('code_counters')->count();
        $plan = $this->service->plan($this->dataset());
        $this->assertTrue($plan['can_apply']);
        $this->assertSame([], $plan['conflicts']);
        $this->assertSame(4, $plan['source_counts']['equipment']);
        $this->assertNotEmpty($plan['warnings']);
        $this->assertSame(0, LegacyImport::count());
        $this->assertSame(0, LegacyRecord::count());
        $this->assertSame(0, Location::count());
        $this->assertSame(1, Asset::count());
        $this->assertSame($beforeCounters, DB::table('code_counters')->count());
    }

    public function test_all_data_is_mapped_without_replacing_new_asset_or_admin(): void
    {
        $adminBefore = $this->admin->fresh()->getAttributes();
        $assetBefore = $this->newAsset->fresh()->getAttributes();
        $data = $this->dataset();
        $result = $this->service->apply($this->admin, $data);
        $this->assertSame('applied', $result['status']);
        $this->assertSame($adminBefore, $this->admin->fresh()->getAttributes());
        $this->assertSame($assetBefore, $this->newAsset->fresh()->getAttributes());
        $this->assertSame(5, Asset::count());
        $this->assertSame(1, User::count());
        $this->assertSame(17, Category::count());
        $this->assertSame(2, Location::count());
        $this->assertSame(2, (int) DB::table('code_counters')->where('prefix', 'SKX-A')->value('next_value'));
        $asset = Asset::where('code', 'EQ-000001')->firstOrFail();
        $this->assertNotSame(29, $asset->id);
        $this->assertSame($data['tables']['equipment'][0]['description'], $asset->private_notes);
        $this->assertSame('unknown', $asset->condition);
        $this->assertSame('unspecified', $asset->maintenance_policy);
        $this->assertSame('2025-09-15 10:20:30.000000', $asset->getRawOriginal('created_at'));
        $this->assertSame(5, Asset::where('inventory_no', 'SHARED-INV')->count());
        $this->assertSame('stage', Asset::where('code', 'EQ-000900')->firstOrFail()->category->domain);
        $this->assertSame('audio', Asset::where('code', 'EQ-000032')->firstOrFail()->category->domain);
        $this->assertSame('unknown', Asset::where('code', 'EQ-000031')->firstOrFail()->category->domain);
        $this->assertSame('stock', Asset::where('code', 'EQ-000900')->firstOrFail()->status);
        $writtenOff = Asset::where('code', 'EQ-000032')->firstOrFail();
        $this->assertSame('written_off', $writtenOff->status);
        $this->assertNull($writtenOff->archived_at);
        $this->assertSame(array_sum($result['source_counts']), LegacyRecord::count());
        $this->assertSame($asset->id, LegacyRecord::where('table_name', 'equipment')->where('source_key', '29')->firstOrFail()->target_id);
    }

    public function test_exact_aliases_preserve_code_id_mismatch_unicode_and_verified_old_url(): void
    {
        $this->service->apply($this->admin, $this->dataset());
        $resolver = app(QrResolver::class);
        $asset = Asset::where('code', 'EQ-000001')->firstOrFail();
        $this->assertSame($asset->id, $resolver->resolve('EQ-000001')?->id);
        $this->assertSame($asset->id, $resolver->resolve('/equipment_card.php?id=29')?->id);
        $this->assertSame($asset->id, $resolver->resolve('http://skaladex.vve.local/admin/equipment.php?code=EQ-000001')?->id);
        $this->assertNull($resolver->resolve('http://foreign.example.test/admin/equipment.php?code=EQ-000001'));
        $cyrillic = Location::where('code', 'Сe-Cab-009')->firstOrFail();
        $latin = Location::where('code', 'Ce-Cab-009')->firstOrFail();
        $this->assertNotSame($cyrillic->id, $latin->id);
        $this->assertSame($cyrillic->id, $resolver->resolve('/cabinet.php?id='.rawurlencode('Сe-Cab-009'))?->id);
        $this->assertSame($latin->id, $resolver->resolve('/cabinet.php?id=Ce-Cab-009')?->id);
        $this->assertSame('Ce-Cab-009', $latin->name);
        $this->assertDatabaseMissing('locations', ['code' => 'CAB-001']);
        $this->assertDatabaseMissing('qr_aliases', ['alias' => 'url:http://skaladex.vve.local/cabinet/CAB-001']);
        $stale = LegacyRecord::where('table_name', 'qr_codes')->where('source_key', '1')->firstOrFail();
        $this->assertSame('History', $stale->target_type);
        $this->assertNull($stale->target_id);
        $this->assertSame('CAB-001', $stale->payload['target_code']);
    }

    public function test_history_retains_deleted_subjects_duplicate_snapshots_and_unknown_versions(): void
    {
        $data = $this->dataset();
        $this->service->apply($this->admin, $data);
        $asset = Asset::where('code', 'EQ-000001')->firstOrFail();
        $movement = AssetMovement::firstOrFail();
        $this->assertSame($asset->id, $movement->asset_id);
        $this->assertSame($this->admin->id, $movement->actor_id);
        $this->assertNull($movement->asset_version);
        $this->assertNull($movement->from_status);
        $this->assertNull($movement->to_status);
        $this->assertSame('Original movement note', $movement->reason);
        $this->assertSame('Сe-Cab-009', $movement->fromLocation->code);
        $this->assertSame('Ce-Cab-009', $movement->toLocation->code);
        $this->assertSame('Сe-Cab-009', $asset->location->code, 'History must not replay a move against current state.');
        $this->assertSame('2025-09-15T10:20:30+00:00', $movement->occurred_at->toIso8601String());
        $this->assertSame(4, AuditEvent::count());
        $this->assertSame(2, AuditEvent::where('event', 'legacy.equipment.update')->count());
        $this->assertSame(4, AuditEvent::whereNull('actor_id')->count());
        $deleted = AuditEvent::where('event', 'legacy.equipment.delete')->firstOrFail();
        $this->assertSame('Asset', $deleted->subject_type);
        $this->assertNull($deleted->subject_id);
        $this->assertNull($deleted->subject_uuid);
        $this->assertSame('999', $deleted->data['legacy_entity_id']);
        $this->assertSame($data['tables']['audit_log'][3]['snapshot'], $deleted->data['original_snapshot']);
        $this->assertDatabaseMissing('assets', ['code' => 'EQ-000999']);
    }

    public function test_padded_numeric_equipment_qr_is_verified_without_normalizing_cabinet_ids(): void
    {
        $data = $this->dataset();
        $data['tables']['cabinets'][] = ['id' => '00029', 'title' => 'Numeric fixture cabinet', 'created_at' => null, 'updated_at' => null];
        $data['tables']['qr_codes'][0]['target_code'] = '00029';
        $data['tables']['qr_codes'][0]['content'] = 'http://skaladex.vve.local/cabinet.php?id=00029';
        $url = 'http://skaladex.vve.local/equipment_card.php?id=%2000029%20';
        $data['tables']['qr_codes'][1]['content'] = $url;
        $this->service->apply($this->admin, $data);
        $asset = Asset::where('code', 'EQ-000001')->firstOrFail();
        $room = Location::where('code', '00029')->firstOrFail();
        $this->assertSame($asset->id, app(QrResolver::class)->resolve($url)?->id);
        $this->assertSame($asset->id, app(QrResolver::class)->resolve('/eq/000029')?->id);
        $this->assertSame($room->id, app(QrResolver::class)->resolve('/cabinet.php?id=00029')?->id);
        $this->assertDatabaseHas('qr_aliases', ['alias' => 'url:'.$url, 'target_type' => 'asset', 'target_id' => $asset->id]);
        $this->assertDatabaseHas('qr_aliases', ['alias' => 'location-id:00029', 'target_type' => 'location', 'target_id' => $room->id]);
        $this->assertDatabaseMissing('qr_aliases', ['alias' => 'location-id:29']);
    }

    public function test_canonical_replay_is_noop_and_never_overwrites_user_edits(): void
    {
        $data = $this->dataset();
        $first = $this->service->apply($this->admin, $data);
        $asset = Asset::where('code', 'EQ-000001')->firstOrFail();
        $asset->name = 'Edited after migration';
        $asset->version++;
        $asset->save();
        foreach ($data['tables'] as $table => $rows) {
            $data['tables'][$table] = array_map(fn ($row) => array_reverse($row, true), array_reverse($rows));
        }
        $data['source']['exported_at'] = '2026-10-17T00:00:00Z';
        $again = $this->service->apply($this->admin, $data);
        $this->assertSame('noop', $again['status']);
        $this->assertSame($first['import_id'], $again['import_id']);
        $this->assertSame($first['dataset_sha256'], $again['dataset_sha256']);
        $this->assertSame('Edited after migration', $asset->fresh()->name);
        $this->assertSame(1, LegacyImport::count());
        $this->assertSame(5, Asset::count());
        $this->assertSame(4, AuditEvent::count());
        $this->assertSame(1, AssetMovement::count());
    }

    public function test_changed_source_row_blocks_the_entire_new_batch_without_overwriting_edits(): void
    {
        $data = $this->dataset();
        $this->service->apply($this->admin, $data);
        $asset = Asset::where('code', 'EQ-000001')->firstOrFail();
        $asset->update(['name' => 'Preserve target edit']);
        $records = LegacyRecord::count();
        $data['tables']['equipment'][0]['description'] = 'Changed in source';
        $new = $data['tables']['equipment'][1];
        $new['id'] = 88;
        $new['code'] = 'EQ-000088';
        $data['tables']['equipment'][] = $new;
        $this->assertFalse($this->service->plan($data)['can_apply']);
        $this->fails($data, 409);
        $this->assertSame('Preserve target edit', $asset->fresh()->name);
        $this->assertSame($records, LegacyRecord::count());
        $this->assertSame(1, LegacyImport::count());
        $this->assertDatabaseMissing('assets', ['code' => 'EQ-000088']);
    }

    public function test_mid_import_failure_rolls_back_objects_aliases_history_and_ledger(): void
    {
        LegacyRecord::creating(function (LegacyRecord $record): void {
            if ($record->table_name === 'audit_log') {
                throw new RuntimeException('Fixture write failure');
            }
        });
        try {
            $this->service->apply($this->admin, $this->dataset());
            $this->fail('Expected fixture failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fixture write failure', $e->getMessage());
        } finally {
            LegacyRecord::flushEventListeners();
        }
        $this->assertSame(1, Asset::count());
        $this->assertSame(13, Category::count());
        foreach ([Location::class, QrAlias::class, AssetMovement::class, AuditEvent::class, LegacyImport::class, LegacyRecord::class] as $class) {
            $this->assertSame(0, $class::count(), $class.' must roll back.');
        }
        $this->assertDatabaseMissing('code_counters', ['prefix' => 'IMPORT-SKLADEX-V1']);
    }

    public function test_existing_code_and_alias_conflicts_cannot_take_over_new_records(): void
    {
        $this->newAsset->update(['code' => 'EQ-000001']);
        $this->fails($this->dataset(), 409);
        $this->assertSame('Already created object', $this->newAsset->fresh()->name);
        $this->newAsset->update(['code' => 'SKX-A-000001']);
        $alias = QrAlias::create(['alias' => 'asset-code:EQ-000001', 'target_type' => 'asset', 'target_id' => $this->newAsset->id]);
        $this->fails($this->dataset(), 409);
        $this->assertSame($this->newAsset->id, $alias->fresh()->target_id);
        $this->assertSame(0, LegacyRecord::count());
        $this->assertSame(0, LegacyImport::count());
    }

    public function test_missing_source_reference_and_nonempty_attachments_fail_before_any_write(): void
    {
        $data = $this->dataset();
        $data['tables']['equipment'][0]['cabinet_id'] = 'MISSING';
        $this->fails($data, 422);
        $data = $this->dataset();
        $data['tables']['attachments'][] = ['id' => 1, 'equipment_id' => 29, 'path' => 'fixture.pdf'];
        $plan = $this->service->plan($data);
        $this->assertFalse($plan['can_apply']);
        $this->assertStringContainsString('attachments', implode(' ', $plan['conflicts']));
        $this->fails($data, 422);
        $this->assertSame(0, LegacyImport::count());
        $this->assertSame(1, Asset::count());
    }

    public function test_uncategorized_fallback_cannot_reuse_an_unowned_existing_category(): void
    {
        $existing = Category::create(['name' => 'Keep manually created category', 'slug' => 'legacy-v1-uncategorized', 'domain' => 'it', 'kind' => 'standard', 'maintenance_policy' => 'none']);
        $before = $existing->fresh()->getAttributes();
        $plan = $this->service->plan($this->dataset());
        $this->assertFalse($plan['can_apply']);
        $this->fails($this->dataset(), 409);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertSame(0, LegacyImport::count());
        $this->assertSame(0, LegacyRecord::count());
        $this->assertSame(1, Asset::count());
    }

    public function test_unknown_legacy_user_is_disabled_and_password_material_is_not_retained(): void
    {
        User::factory()->create(['username' => 'new.only', 'role' => 'operator']);
        $data = $this->dataset();
        $data['tables']['users'][0]['password_hash'] = 'discard-source-hash-fixture';
        $data['tables']['users'][] = ['id' => 2, 'login' => 'old.operator', 'password_hash' => 'discard-other-hash-fixture', 'role' => 'operator', 'is_active' => 1, 'created_at' => null, 'updated_at' => null];
        $data['tables']['equipment_moves'][0]['moved_by'] = 2;
        $this->service->apply($this->admin, $data);
        $user = User::where('username', 'old.operator')->firstOrFail();
        $this->assertFalse($user->is_active);
        $this->assertNotSame(2, $user->id);
        $this->assertSame('legacy-user-2@skladex.invalid', $user->email);
        $this->assertSame($user->id, AssetMovement::firstOrFail()->actor_id);
        $payloads = implode(' ', DB::table('legacy_records')->pluck('payload')->all());
        $this->assertStringNotContainsString('password_hash', $payloads);
        $this->assertStringNotContainsString('discard-source-hash-fixture', $payloads);
        $this->assertStringNotContainsString('discard-other-hash-fixture', $payloads);
    }

    public function test_nullable_movement_facts_and_optional_status_history_are_not_invented(): void
    {
        $data = $this->dataset();
        $data['tables']['equipment_moves'][0] = array_merge($data['tables']['equipment_moves'][0], ['from_cabinet_id' => null, 'moved_by' => null, 'note' => null, 'moved_at' => null]);
        $data['tables']['equipment_status_history'][] = ['id' => 1, 'equipment_id' => 29, 'old_status' => 'reserve', 'new_status' => 'in_use', 'changed_by' => null, 'note' => 'Original status reason', 'changed_at' => '2025-09-15 10:20:30'];
        $this->service->apply($this->admin, $data);
        $movement = AssetMovement::firstOrFail();
        $this->assertNull($movement->from_location_id);
        $this->assertNull($movement->actor_id);
        $this->assertNull($movement->asset_version);
        $this->assertNull($movement->occurred_at);
        $record = LegacyRecord::where('table_name', 'equipment_moves')->firstOrFail();
        $this->assertNull($record->payload['note']);
        $status = AuditEvent::where('event', 'legacy.status')->firstOrFail();
        $this->assertSame('reserve', $status->data['original_snapshot']['old_status']);
        $this->assertNull($status->actor_id);
    }

    public function test_operator_cannot_apply_an_import(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        try {
            $this->service->apply($operator, $this->dataset());
            $this->fail('Expected forbidden import.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, LegacyImport::count());
    }
}
