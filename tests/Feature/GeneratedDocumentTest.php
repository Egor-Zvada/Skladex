<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Building;
use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use App\Services\GeneratedDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class GeneratedDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_is_idempotent_and_preserves_selected_order_and_exact_numbers(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $first = $this->asset(['inventory_no' => '0000-42', 'serial_no' => '000SERIAL']);
        $second = $this->asset(['inventory_no' => '0000-42']);
        $data = $this->input([$second, $first]) + ['uuid' => (string) Str::uuid(), 'status' => 'final', 'version' => 99, 'template_version' => 99, 'template_snapshot' => ['name' => 'Injected']];
        $headers = $this->headers($actor, 'document-create-command-0001');
        $response = $this->postJson('/api/v1/document-records', $data, $headers)->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.template_version', 1)->assertJsonPath('data.items.0.snapshot.uuid', $second->uuid)
            ->assertJsonPath('data.items.1.snapshot.inventory_no', '0000-42')
            ->assertJsonPath('data.items.1.snapshot.serial_no', '000SERIAL');
        $this->assertNotSame($data['uuid'], $response->json('data.uuid'));
        $this->assertNotSame('Injected', $response->json('data.template_snapshot.name'));
        $this->postJson('/api/v1/document-records', $data, $headers)->assertCreated()->assertJsonPath('data.uuid', $response->json('data.uuid'));
        $this->assertDatabaseCount('generated_document_records', 1);
        $this->assertDatabaseCount('generated_document_items', 2);
        $this->postJson('/api/v1/document-records', array_replace($data, ['body' => 'Another command']), $headers)->assertConflict();
        $this->assertDatabaseHas('audit_events', ['event' => 'generated_document.created', 'actor_id' => $actor->id]);
        $this->assertSame(1, $first->fresh()->version);
        $this->assertSame('stock', $first->fresh()->status);
    }

    public function test_snapshots_remain_after_asset_room_and_building_changes_and_asset_purge(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $building = Building::create(['name' => 'Исходное здание']);
        $room = Location::create(['name' => 'Кабинет 007', 'code' => 'ROOM-DOC-007', 'building_id' => $building->id]);
        $asset = $this->asset(['name' => 'Исходный сервер', 'location_id' => $room->id, 'private_notes' => 'DO NOT COPY PRIVATE NOTES', 'responsible_name' => 'DO NOT COPY RESPONSIBLE']);
        $this->actingAs($actor);
        $record = app(GeneratedDocumentService::class)->create($actor, $this->input([$asset]));
        $asset->update(['name' => 'Изменённый сервер', 'inventory_no' => 'CHANGED', 'version' => 2]);
        $building->update(['name' => 'Новое здание']);
        $room->update(['name' => 'Другой кабинет']);
        $asset->delete();
        $item = $record->items()->firstOrFail();
        $this->assertNull($item->asset_id);
        $this->assertSame('Исходный сервер', $item->snapshot['name']);
        $this->assertSame('Кабинет 007', $item->snapshot['location_name']);
        $this->assertSame('Исходное здание', $item->snapshot['building_name']);
        $this->assertSame(1, $item->snapshot['asset_version']);
        $this->assertArrayNotHasKey('private_notes', $item->snapshot);
        $this->assertArrayNotHasKey('responsible_name', $item->snapshot);
        $this->get(route('document-records.show', $record))->assertOk()->assertSee('Исходный сервер')->assertSee('Исходное здание')->assertDontSee('Изменённый сервер')->assertDontSee('DO NOT COPY PRIVATE NOTES')->assertDontSee('DO NOT COPY RESPONSIBLE');
    }

    public function test_concurrent_asset_change_rolls_back_whole_batch_and_wrong_domain_is_rejected(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $first = $this->asset();
        $second = $this->asset();
        $data = $this->input([$first, $second]);
        $second->update(['version' => 2]);
        $headers = $this->headers($actor, 'document-stale-batch-0001');
        $this->postJson('/api/v1/document-records', $data, $headers)->assertConflict();
        $this->assertDatabaseCount('generated_document_records', 0);
        $this->assertDatabaseCount('generated_document_items', 0);
        $room = Location::create(['name' => 'Room', 'code' => 'ROOM-DOC-WRONG']);
        $wrong = array_replace($this->input([$first]), ['asset_ids' => [$room->uuid], 'expected_assets' => [$room->uuid => 1]]);
        $this->postJson('/api/v1/document-records', $wrong, $this->headers($actor, 'document-wrong-domain-0001'))->assertConflict();
        $this->assertDatabaseCount('generated_document_records', 0);
    }

    public function test_text_update_checks_version_and_finalization_is_immutable_and_does_not_write_off_assets(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $asset = $this->asset();
        $this->actingAs($actor);
        $service = app(GeneratedDocumentService::class);
        $record = $service->create($actor, array_replace($this->input([$asset]), ['template_key' => 'writeoff']));
        $data = array_replace($this->input([$asset]), ['title' => 'Исправленный текст', 'expected_version' => 1, 'asset_ids' => [], 'template_key' => 'memo']);
        $this->patchJson(route('document-records.update', $record), $data)->assertRedirect();
        $this->assertSame(2, $record->fresh()->version);
        $this->assertSame('writeoff', $record->fresh()->template_key);
        $this->assertSame(1, $record->items()->count());
        $this->patchJson(route('document-records.update', $record), array_replace($data, ['title' => 'Stale edit']))->assertConflict();
        $this->postJson(route('document-records.finalize', $record), ['expected_version' => 1])->assertConflict();
        $this->postJson(route('document-records.finalize', $record), ['expected_version' => 2])->assertRedirect();
        $this->assertSame('final', $record->fresh()->status);
        $this->assertSame(3, $record->fresh()->version);
        $this->assertNotNull($record->fresh()->finalized_at);
        $this->patchJson(route('document-records.update', $record), array_replace($data, ['expected_version' => 3]))->assertConflict();
        $this->assertSame('Исправленный текст', $record->fresh()->title);
        $this->assertSame('stock', $asset->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['event' => 'generated_document.finalized', 'subject_uuid' => $record->uuid]);
    }

    public function test_guest_and_viewer_cannot_write_and_api_requires_scoped_token_and_key(): void
    {
        $asset = $this->asset();
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->get(route('document-records.index'))->assertRedirect(route('login'));
        $this->postJson('/api/v1/document-records', $this->input([$asset]))->assertUnauthorized();
        $this->postJson('/api/v1/document-records', $this->input([$asset]), $this->headers($viewer, 'document-viewer-denied-0001'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $readHeaders = $this->headers($operator, 'document-readonly-denied-01', ['read']);
        $this->postJson('/api/v1/document-records', $this->input([$asset]), $readHeaders)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $full = $this->headers($operator, 'document-token-no-key-0001');
        unset($full['Idempotency-Key']);
        $this->postJson('/api/v1/document-records', $this->input([$asset]), $full)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $operator->update(['is_active' => false]);
        $this->postJson('/api/v1/document-records', $this->input([$asset]), $this->headers($operator, 'document-inactive-denied-01'))->assertForbidden();
        $this->assertDatabaseCount('generated_document_records', 0);
    }

    public function test_user_text_and_asset_names_are_escaped_and_pdf_is_local_private_and_cleans_workspace(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $asset = $this->asset(['name' => '<img src="http://127.0.0.1:9/x">', 'inventory_no' => '<script>badInventory()</script>']);
        $this->actingAs($operator);
        $record = app(GeneratedDocumentService::class)->create($operator, array_replace($this->input([$asset]), ['body' => '<script>badDocument()</script>'."\n".'<?php echo "badPhp"; ?>', 'organization' => '<b>Организация</b>']));
        $this->get(route('document-records.show', $record))->assertOk()->assertSee('&lt;script&gt;badDocument()&lt;/script&gt;', false)->assertDontSee('<script>badDocument()</script>', false)->assertDontSee('<img src="http://127.0.0.1:9/x">', false);
        $html = view('document-records.pdf', compact('record'))->render();
        $this->assertStringNotContainsString('<img src="http://127.0.0.1:9/x">', $html);
        $this->assertStringNotContainsString('<?php echo', $html);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->actingAs($viewer);
        $response = $this->get(route('document-records.pdf', $record))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertSame([], File::directories(storage_path('app/private/document-pdf')));
        $this->get(route('document-records.show', $record))->assertOk()->assertDontSee('Сохранить текст')->assertDontSee('Зафиксировать редакцию</button>', false);
        $this->postJson(route('document-records.finalize', $record), ['expected_version' => 1])->assertForbidden();
    }

    public function test_selection_must_be_nonempty_distinct_bounded_and_versioned(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $asset = $this->asset();
        $this->actingAs($actor);
        $base = $this->input([$asset]);
        foreach ([['asset_ids' => []], ['asset_ids' => [$asset->uuid, strtoupper($asset->uuid)]], ['asset_ids' => range(1, 101)], ['expected_assets' => []], ['body' => str_repeat('A', 12001)], ['template_key' => '../templates/unsafe']] as $invalid) {
            $this->postJson(route('document-records.store'), array_replace($base, $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('generated_document_records', 0);
    }

    public function test_archived_assets_are_excluded_but_written_off_equipment_can_have_documents(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $archived = $this->asset(['name' => 'Archived ordinary asset', 'archived_at' => now()]);
        $writtenOff = $this->asset(['name' => 'Written off equipment', 'status' => 'written_off', 'archived_at' => now()]);
        $this->actingAs($actor);
        $this->get(route('document-records.create', ['asset_ids' => [$writtenOff->uuid]]))->assertOk()->assertSee('Written off equipment')->assertDontSee('Archived ordinary asset')->assertSee('data-document-create', false);
        $this->postJson(route('document-records.store'), $this->input([$archived]))->assertConflict();
        $this->postJson(route('document-records.store'), array_replace($this->input([$writtenOff]), ['template_key' => 'writeoff']))->assertRedirect();
        $this->assertDatabaseCount('generated_document_records', 1);
        $this->assertDatabaseCount('generated_document_items', 1);
    }

    public function test_invalid_old_input_does_not_break_create_form(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->asset();
        $this->actingAs($actor)->withSession(['_old_input' => ['asset_ids' => [['nested']], 'title' => ['nested'], 'body' => ['nested'], 'organization' => ['nested'], 'document_date' => ['nested']]])
            ->get(route('document-records.create'))->assertOk()->assertSee('Новый документ');
    }

    public function test_invalid_date_old_input_does_not_break_document_editing_form(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $record = app(GeneratedDocumentService::class)->create($actor, $this->input([$this->asset()]));
        $this->actingAs($actor)->withSession(['_old_input' => ['document_date' => ['nested'], 'body' => ['nested']]])
            ->get(route('document-records.show', $record))->assertOk()->assertSee('Редактировать текст');
    }

    private function asset(array $overrides = []): Asset
    {
        $category = Category::firstOrCreate(['slug' => 'document-test-computers'], ['name' => 'Компьютеры', 'domain' => 'it', 'kind' => 'computer']);

        return Asset::create(array_replace(['name' => 'Тестовый компьютер', 'code' => 'DOC-'.Str::random(12), 'category_id' => $category->id], $overrides));
    }

    private function input(array $assets): array
    {
        return ['template_key' => 'transfer', 'title' => 'Тестовый акт', 'document_date' => '2026-10-16', 'body' => 'Передать оборудование для использования в кабинете.', 'asset_ids' => array_map(fn ($asset) => $asset->uuid, $assets), 'expected_assets' => collect($assets)->mapWithKeys(fn ($asset) => [$asset->uuid => $asset->version])->all()];
    }

    private function headers(User $user, string $key, array $abilities = ['read', 'write']): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('document test fixture', $abilities)->plainTextToken, 'Idempotency-Key' => $key];
    }
}
