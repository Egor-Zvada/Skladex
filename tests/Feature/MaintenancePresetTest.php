<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\MaintenancePlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MaintenancePresetService;
use App\Services\OperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MaintenancePresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommendations_preserve_legacy_category_granularity_and_cover_unknown_equipment(): void
    {
        $service = app(MaintenancePresetService::class);
        $keys = ['computer', 'computer', 'computer', 'printer', 'network', 'peripheral', 'printer', 'ups', 'display', 'tablet', 'audio', 'stage', 'display'];
        foreach ($keys as $index => $key) {
            $category = new Category(['slug' => 'legacy-v1-category-'.($index + 1)]);
            $this->assertSame($key, $service->recommend((new Asset)->setRelation('category', $category)));
        }
        $this->assertSame('generic', $service->recommend((new Asset)->setRelation('category', null)));
        foreach (['servers' => 'server', 'racks' => 'rack', 'network' => 'network'] as $slug => $expected) {
            $this->assertSame($expected, $service->recommend((new Asset)->setRelation('category', new Category(['slug' => $slug]))));
        }
    }

    public function test_new_suggestions_have_no_invented_service_date_work_or_asset_condition_changes(): void
    {
        $actor = $this->actor();
        $asset = $this->asset('PRESET-ONE');
        $service = app(MaintenancePresetService::class);
        $result = $service->apply($actor, $this->input([$asset]));
        $plan = MaintenancePlan::firstOrFail();
        $this->assertSame(1, $result['created']);
        $this->assertSame('active', $plan->status);
        $this->assertNull($plan->next_due_on);
        $this->assertNull($plan->anchor_on);
        $this->assertNull($plan->last_completed_on);
        $this->assertSame(0, app(OperationsService::class)->generateDuePlans());
        $this->assertSame(0, WorkOrder::count());
        $this->assertDatabaseCount('maintenance_occurrences', 0);
        $this->assertDatabaseCount('maintenance_preset_applications', 1);
        $this->assertSame('unspecified', $asset->fresh()->maintenance_policy);
        $this->assertSame('unknown', $asset->fresh()->condition);
        $this->assertSame(1, $asset->fresh()->version);
        $this->assertSame('inspection', $plan->checklist_schema[0]['type']);
    }

    public function test_reapplying_preserves_user_customizations_and_previously_configured_plans(): void
    {
        $actor = $this->actor();
        $one = $this->asset('PRESET-REPLAY');
        $two = $this->asset('PRESET-EXISTING');
        $service = app(MaintenancePresetService::class);
        $first = $service->apply($actor, $this->input([$one]));
        $plan = MaintenancePlan::where('uuid', $first['plans'][0])->firstOrFail();
        $plan->update(['name' => 'Индивидуальное обслуживание', 'status' => 'paused', 'interval_count' => 9]);
        $custom = app(OperationsService::class)->createPlan($actor, ['asset_uuid' => $two->uuid, 'name' => 'Существующий план', 'mode' => 'completion', 'interval_unit' => 'month', 'interval_count' => 6, 'lead_days' => 3]);
        $result = $service->apply($actor, $this->input([$one, $two]) + ['first_due_on' => '2030-02-10']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame('Индивидуальное обслуживание', $plan->fresh()->name);
        $this->assertSame('paused', $plan->fresh()->status);
        $this->assertSame(9, $plan->fresh()->interval_count);
        $this->assertSame('Существующий план', $custom->fresh()->name);
        $this->assertDatabaseCount('maintenance_plans', 2);
    }

    public function test_explicit_first_due_date_and_interval_are_used_without_marking_work_completed(): void
    {
        $actor = $this->actor();
        $asset = $this->asset('PRESET-DATE');
        app(MaintenancePresetService::class)->apply($actor, $this->input([$asset]) + ['first_due_on' => '2030-02-10', 'interval_unit' => 'week', 'interval_count' => 4]);
        $plan = MaintenancePlan::firstOrFail();
        $this->assertSame('2030-02-10', $plan->next_due_on->toDateString());
        $this->assertSame('week', $plan->interval_unit);
        $this->assertSame(4, $plan->interval_count);
        $this->assertNull($plan->last_completed_on);
        $this->assertDatabaseCount('work_orders', 0);
    }

    public function test_stale_version_rejects_whole_batch_before_creating_any_plans(): void
    {
        $actor = $this->actor();
        $one = $this->asset('PRESET-ATOMIC-ONE');
        $two = $this->asset('PRESET-ATOMIC-TWO');
        $input = $this->input([$one, $two]);
        $two->update(['version' => 2]);
        try {
            app(MaintenancePresetService::class)->apply($actor, $input);
            $this->fail('Expected version conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('maintenance_plans', 0);
        $this->assertDatabaseCount('maintenance_preset_applications', 0);
    }

    public function test_archived_written_off_and_wrong_category_assets_are_not_assigned_presets(): void
    {
        $actor = $this->actor();
        foreach ([['archived_at' => now()], ['status' => 'written_off'], ['category_id' => Category::create(['slug' => 'audio-fixture', 'name' => 'Audio', 'domain' => 'audio'])->id]] as $index => $changes) {
            $asset = $this->asset('PRESET-REFUSE-'.$index);
            $asset->update($changes);
            try {
                app(MaintenancePresetService::class)->apply($actor, $this->input([$asset]));
                $this->fail('Expected ineligible asset refusal.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('maintenance_plans', 0);
    }

    public function test_reader_cannot_apply_presets(): void
    {
        $actor = $this->actor('viewer');
        $asset = $this->asset('PRESET-READONLY');
        try {
            app(MaintenancePresetService::class)->apply($actor, $this->input([$asset]));
            $this->fail('Expected permission refusal.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('maintenance_plans', 0);
    }

    public function test_application_ledger_records_sources_and_prevents_duplicate_application(): void
    {
        $actor = $this->actor();
        $asset = $this->asset('PRESET-SNAPSHOT');
        app(MaintenancePresetService::class)->apply($actor, $this->input([$asset]));
        $row = DB::table('maintenance_preset_applications')->first();
        $snapshot = json_decode($row->preset_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($asset->id, $row->asset_id);
        $this->assertNotEmpty($snapshot['scope']);
        $this->assertNotEmpty($snapshot['sources']);
        $this->assertNotEmpty($snapshot['checklist_schema']);
        $this->assertDatabaseCount('maintenance_preset_applications', 1);
    }

    public function test_api_exposes_catalog_without_private_notes_and_requires_scoped_idempotent_writes(): void
    {
        $actor = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $asset = $this->asset('PRESET-API');
        $asset->update(['private_notes' => 'private-preset-note']);
        $read = $actor->createToken('preset read', ['read'])->plainTextToken;
        $this->withToken($read)->getJson(route('api.maintenance.presets.index', ['preset_key' => 'computer']))
            ->assertOk()->assertJsonPath('selected', 'computer')->assertJsonPath('data.0.uuid', $asset->uuid)
            ->assertJsonPath('data.0.has_active_plan', false)->assertDontSee('private-preset-note');
        $this->withToken($read)->postJson(route('api.maintenance.presets.apply'), $this->input([$asset]))->assertForbidden();
        $write = $actor->createToken('preset write', ['read', 'write'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($write)->postJson(route('api.maintenance.presets.apply'), $this->input([$asset]))->assertUnprocessable();
        $this->withToken($write)->withHeader('Idempotency-Key', 'maintenance-preset-api-one')
            ->postJson(route('api.maintenance.presets.apply'), $this->input([$asset]))->assertOk()->assertJsonPath('data.created', 1);
        $this->postJson(route('api.maintenance.presets.apply'), $this->input([$asset]))->assertOk()->assertJsonPath('data.created', 1);
        $this->assertDatabaseCount('maintenance_plans', 1);
        $this->assertDatabaseCount('maintenance_preset_applications', 1);
    }

    public function test_uuid_case_is_normalized_and_duplicates_cannot_change_batch_semantics(): void
    {
        $actor = $this->actor();
        $asset = $this->asset('PRESET-UUID');
        $upper = strtoupper($asset->uuid);
        $result = app(MaintenancePresetService::class)->apply($actor, ['preset_key' => 'computer', 'asset_uuids' => [$upper], 'asset_versions' => [$upper => 1]]);
        $this->assertSame(1, $result['created']);
        $this->expectException(ValidationException::class);
        app(MaintenancePresetService::class)->apply($actor, ['preset_key' => 'computer', 'asset_uuids' => [$upper, $asset->uuid], 'asset_versions' => [$asset->uuid => 1]]);
    }

    public function test_invalid_old_input_does_not_break_preset_selection_form(): void
    {
        $actor = $this->actor();
        $this->asset('PRESET-INVALID-FORM');
        $this->actingAs($actor)->withSession(['_old_input' => ['asset_uuids' => 'invalid', 'interval_count' => ['nested'], 'first_due_on' => ['nested']]])
            ->get(route('maintenance.presets.index'))->assertOk()->assertSee('Рекомендуемые регламенты');
    }

    private function actor(string $role = 'operator'): User
    {
        $actor = User::factory()->create(['role' => $role, 'is_active' => true]);
        $this->actingAs($actor);

        return $actor;
    }

    private function asset(string $code): Asset
    {
        $category = Category::firstOrCreate(['slug' => 'computers'], ['name' => 'Компьютеры', 'domain' => 'it', 'kind' => 'computer']);

        return Asset::create(['name' => $code, 'code' => $code, 'category_id' => $category->id]);
    }

    private function input(array $assets): array
    {
        return ['preset_key' => 'computer', 'asset_uuids' => array_map(fn ($asset) => $asset->uuid, $assets), 'asset_versions' => array_column(array_map(fn ($asset) => [$asset->uuid, $asset->version], $assets), 1, 0)];
    }
}
