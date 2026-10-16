<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class HistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('History feature suite requires MariaDB.');
        }
        if (! preg_match('/(?:^|_)(?:tests?|testing)(?:_|$)/i', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('HistoryTest requires a dedicated *_test or *_testing database.');
        }
    }

    public function test_history_requires_login_and_an_active_internal_role(): void
    {
        $this->getJson(route('history.index'))->assertUnauthorized();
        $this->actingAs($this->user('viewer', false))->getJson(route('history.index'))->assertForbidden();
        foreach (['viewer', 'operator', 'admin'] as $role) {
            $this->actingAs($this->user($role))->get(route('history.index'))->assertOk()->assertSee('История');
        }
    }

    public function test_legacy_and_current_events_are_visible_without_inventing_legacy_authors(): void
    {
        $asset = $this->asset();
        $actor = $this->user('operator');
        $actor->update(['name' => 'SYNTHETIC-IMPORTER-NOT-HISTORICAL-AUTHOR']);
        $this->legacy($asset, ['actor_id' => $actor->id]);
        $this->event($asset, 'asset.saved', ['reason' => 'CURRENT-SYNTHETIC-CHANGE'], $actor);
        $response = $this->actingAs($this->user('viewer'))->get(route('history.index'));
        $response->assertOk()->assertSee('Создание в прежней системе')->assertSee('legacy.equipment.insert')->assertSee('CURRENT-SYNTHETIC-CHANGE')->assertSee('в исходном журнале не указан');
        // The real actor remains visible for a current event, never copied to legacy.
        $response->assertSee($actor->name);
        $this->get(route('history.index', ['source' => 'legacy']))->assertOk()->assertDontSee($actor->name)->assertDontSee('CURRENT-SYNTHETIC-CHANGE');
    }

    public function test_deleted_legacy_subject_remains_visible_with_original_identity(): void
    {
        AuditEvent::create([
            'event' => 'legacy.equipment.delete', 'subject_type' => 'Asset', 'subject_id' => null, 'subject_uuid' => null, 'actor_id' => null,
            'data' => ['legacy_audit_id' => 7001, 'legacy_entity_id' => '90001', 'source_system' => 'synthetic-source', 'original_snapshot' => ['id' => 90001, 'code' => 'SYN-DELETED', 'name' => 'SYNTHETIC-DELETED-ASSET']],
            'created_at' => '2024-01-02 03:04:05',
        ]);
        $this->actingAs($this->user('viewer'))->get(route('history.index'))->assertOk()->assertSee('Предмет отсутствует в текущем реестре')->assertSee('Удаление в прежней системе')->assertSee('SYNTHETIC-DELETED-ASSET')->assertSee('90001')->assertSee('7001');
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_asset_and_source_filters_keep_unrelated_history_out(): void
    {
        $first = $this->asset('FIRST-SYNTHETIC');
        $second = $this->asset('SECOND-SYNTHETIC');
        $this->legacy($first, [], ['original_snapshot' => ['id' => 101, 'name' => 'FIRST-LEGACY-SNAPSHOT']]);
        $this->legacy($second, [], ['original_snapshot' => ['id' => 102, 'name' => 'SECOND-LEGACY-SNAPSHOT']]);
        $this->event($first, 'asset.saved', ['reason' => 'FIRST-CURRENT-SNAPSHOT']);
        $this->actingAs($this->user('viewer'));
        $this->get(route('history.index', ['asset' => $first->uuid, 'source' => 'legacy']))->assertOk()->assertSee('FIRST-LEGACY-SNAPSHOT')->assertDontSee('SECOND-LEGACY-SNAPSHOT')->assertDontSee('FIRST-CURRENT-SNAPSHOT');
        $this->get(route('history.index', ['asset' => $first->uuid, 'source' => 'current']))->assertOk()->assertSee('FIRST-CURRENT-SNAPSHOT')->assertDontSee('FIRST-LEGACY-SNAPSHOT');
        $this->get(route('history.index', ['asset' => (string) Str::uuid()]))->assertNotFound();
    }

    public function test_snapshot_html_is_escaped_and_credential_fields_are_never_rendered(): void
    {
        $asset = $this->asset();
        $html = '<script>window.SYNTHETIC_HISTORY_XSS=1</script>';
        $this->legacy($asset, [], ['password_hash' => 'LEGACY-TOP-SECRET-PROBE', 'original_snapshot' => ['id' => 101, 'name' => $html, 'password_hash' => 'LEGACY-SNAPSHOT-SECRET-PROBE', 'license_key' => 'LEGACY-KEY-PROBE']]);
        $this->event($asset, 'asset.saved', ['reason' => 'SAFE-METADATA-PROBE', 'password' => 'CURRENT-PASSWORD-PROBE', 'nested' => ['api_token' => 'CURRENT-TOKEN-PROBE', 'license_key' => 'CURRENT-KEY-PROBE', 'safe' => 'SAFE-NESTED-PROBE']]);
        $response = $this->actingAs($this->user('viewer'))->get(route('history.index'))->assertOk()->assertSee($html)->assertDontSee($html, false)->assertSee('SAFE-METADATA-PROBE')->assertSee('SAFE-NESTED-PROBE');
        foreach (['LEGACY-TOP-SECRET-PROBE', 'LEGACY-SNAPSHOT-SECRET-PROBE', 'LEGACY-KEY-PROBE', 'CURRENT-PASSWORD-PROBE', 'CURRENT-TOKEN-PROBE', 'CURRENT-KEY-PROBE'] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_history_is_paginated_at_fifty_and_reading_does_not_mutate_it(): void
    {
        $asset = $this->asset();
        foreach (range(1, 55) as $index) {
            $this->event($asset, 'asset.synthetic_'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), []);
        }
        $this->actingAs($this->user('viewer'));
        $response = $this->get(route('history.index', ['source' => 'current']))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->count() === 50 && $entries->total() === 55)->assertSee('asset.synthetic_055')->assertDontSee('asset.synthetic_001');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('history.index', ['source' => 'current', 'page' => 2]))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->count() === 5)->assertSee('asset.synthetic_001')->assertDontSee('asset.synthetic_055');
        $this->assertDatabaseCount('audit_events', 55);
    }

    public function test_search_checks_identity_columns_and_never_scans_snapshot_payload(): void
    {
        $asset = $this->asset();
        $this->event($asset, 'asset.searchable_marker', ['reason' => 'PAYLOAD-ONLY-SEARCH-MARKER']);
        $this->actingAs($this->user('viewer'));
        $this->get(route('history.index', ['q' => 'searchable_marker']))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 1);
        $this->get(route('history.index', ['q' => $asset->uuid]))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 1);
        $this->get(route('history.index', ['q' => 'PAYLOAD-ONLY-SEARCH-MARKER']))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->total() === 0);
    }

    public function test_asset_partial_shows_legacy_summary_but_public_card_never_does(): void
    {
        $asset = $this->asset();
        $this->legacy($asset);
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->get(route('assets.show', $asset))->assertOk()->assertSee('История прежней системы')->assertSee('исходное событие #1001')->assertSee(route('history.index', ['asset' => $asset->uuid, 'source' => 'legacy']));
        $this->get(route('public.asset', $asset))->assertOk()->assertDontSee('legacy.equipment.insert')->assertDontSee('исходное событие #1001')->assertDontSee('История прежней системы');
    }

    public function test_history_has_no_write_actions(): void
    {
        $this->actingAs($this->user('admin'));
        $this->postJson(route('history.index'), [])->assertStatus(405);
        $this->patchJson(route('history.index'), [])->assertStatus(405);
        $this->deleteJson(route('history.index'))->assertStatus(405);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unknown_legacy_date_is_not_presented_as_import_time(): void
    {
        $asset = $this->asset();
        $this->legacy($asset, [], ['source_timestamp_unknown' => true]);
        $this->actingAs($this->user('viewer'))->get(route('history.index', ['source' => 'legacy']))
            ->assertOk()->assertSee('Дата неизвестна')->assertDontSee('02.01.2024 14:04:05');
        $this->get(route('assets.show', $asset))->assertOk()->assertSee('Дата неизвестна');
    }

    private function user(string $role, bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => $active]);
    }

    private function asset(string $name = 'SYNTHETIC-ASSET'): Asset
    {
        $category = Category::firstOrCreate(['slug' => 'history-synthetic'], ['name' => 'Тест истории', 'domain' => 'it', 'kind' => 'standard', 'maintenance_policy' => 'unspecified']);

        return Asset::create(['code' => 'TEST-HISTORY-'.Str::uuid(), 'name' => $name, 'category_id' => $category->id]);
    }

    private function event(Asset $asset, string $event, array $data, ?User $actor = null): AuditEvent
    {
        return AuditEvent::create(['event' => $event, 'subject_type' => 'Asset', 'subject_id' => $asset->id, 'subject_uuid' => $asset->uuid, 'actor_id' => $actor?->id, 'data' => $data, 'created_at' => '2025-01-02 03:04:05']);
    }

    private function legacy(Asset $asset, array $attributes = [], array $data = []): AuditEvent
    {
        return AuditEvent::create($attributes + ['event' => 'legacy.equipment.insert', 'subject_type' => 'Asset', 'subject_id' => $asset->id, 'subject_uuid' => $asset->uuid, 'actor_id' => null, 'data' => $data + ['legacy_audit_id' => 1001, 'legacy_entity_id' => '101', 'source_system' => 'synthetic-source', 'original_snapshot' => ['id' => 101, 'code' => 'SYN-LEGACY', 'name' => $asset->name]], 'created_at' => '2024-01-02 03:04:05']);
    }
}
