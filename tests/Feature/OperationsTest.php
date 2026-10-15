<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryCampaign;
use App\Models\Location;
use App\Models\QrAlias;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private OperationsService $operations;

    private User $operator;

    private Asset $asset;

    private Location $a;

    private Location $b;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 15:00:00', 'Asia/Sakhalin'));
        $this->travelTo(CarbonImmutable::now());
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $category = Category::create(['name' => 'Тестовый звук', 'slug' => 'test-audio', 'domain' => 'audio', 'kind' => 'standard', 'maintenance_policy' => 'unspecified']);
        $this->a = Location::create(['code' => 'SKX-R-TEST-A', 'name' => 'A']);
        $this->b = Location::create(['code' => 'SKX-R-TEST-B', 'name' => 'B']);
        $this->asset = Asset::create(['code' => 'SKX-A-TEST-1', 'name' => 'Тестовый предмет', 'category_id' => $category->id, 'location_id' => $this->a->id, 'status' => 'in_use', 'condition' => 'working', 'version' => 1]);
        $this->operations = app(OperationsService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function work(array $extra = []): WorkOrder
    {
        return $this->operations->createWork($this->operator, array_merge(['asset_uuid' => $this->asset->uuid, 'title' => 'Проверка', 'kind' => 'inspection', 'priority' => 'normal'], $extra));
    }

    private function completed(WorkOrder $work, array $extra = []): WorkOrder
    {
        return $this->operations->completeWork($this->operator, $work, array_merge(['expected_version' => $work->version, 'result' => 'Осмотр выполнен', 'completed_at' => '2026-10-15 10:00'], $extra));
    }

    private function activeCampaign(array $scope = []): InventoryCampaign
    {
        $campaign = $this->operations->createCampaign($this->operator, ['name' => 'Тестовый обход'] + $scope);

        return $this->operations->startCampaign($this->operator, $campaign, $campaign->version);
    }

    private function observation(InventoryCampaign $campaign, string $key, ?Location $location = null, ?User $actor = null, ?string $code = null)
    {
        return $this->operations->observe($actor ?? $this->operator, $campaign, ['expected_version' => $campaign->version, 'code' => $code ?? $this->asset->code, 'location_uuid' => ($location ?? $this->a)->uuid, 'client_key' => $key, 'method' => 'qr']);
    }

    private function assertHttpStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected HTTP '.$status);
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
        }
    }

    public function test_read_only_role_cannot_create_or_accept_work(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->assertHttpStatus(403, fn () => $this->operations->createWork($viewer, ['asset_uuid' => $this->asset->uuid, 'title' => 'X', 'kind' => 'repair', 'priority' => 'normal']));
        $work = $this->completed($this->work());
        $this->assertHttpStatus(403, fn () => $this->operations->acceptWork($viewer, $work, ['expected_version' => $work->version, 'note' => 'X', 'counts_for_plan' => false]));
    }

    public function test_stale_version_prevents_transition_and_leaves_one_event(): void
    {
        $work = $this->work();
        $this->operations->transitionWork($this->operator, $work, ['expected_version' => 1, 'action' => 'start']);
        $this->assertHttpStatus(409, fn () => $this->operations->transitionWork($this->operator, $work, ['expected_version' => 1, 'action' => 'hold', 'reason' => 'Нет доступа']));
        $this->assertSame('in_progress', $work->fresh()->status);
        $this->assertSame(2, $work->fresh()->events()->count());
    }

    public function test_failed_inspection_is_recorded_and_creates_one_follow_up_without_healing_asset(): void
    {
        $this->asset->update(['condition' => 'faulty']);
        $work = $this->work(['checklist_schema' => [['key' => 'signal', 'label' => 'Проверка сигнала', 'type' => 'inspection', 'required' => true]]]);
        $work = $this->completed($work, ['checklist_answers' => ['signal' => ['value' => 'fail', 'note' => 'Нет сигнала']], 'reported_condition' => 'faulty', 'remaining_restrictions' => 'Не использовать']);
        $this->operations->acceptWork($this->operator, $work, ['expected_version' => $work->version, 'note' => 'Осмотр проведён, нужен ремонт', 'counts_for_plan' => false]);
        $this->assertSame('faulty', $this->asset->fresh()->condition);
        $this->assertSame(1, WorkOrder::where('follow_up_for_id', $work->id)->count());
        $this->assertHttpStatus(409, fn () => $this->operations->acceptWork($this->operator, $work, ['expected_version' => $work->version, 'note' => 'Повтор', 'counts_for_plan' => false]));
    }

    public function test_required_check_and_not_applicable_explanation_are_enforced(): void
    {
        $work = $this->work(['checklist_schema' => [['key' => 'safety', 'label' => 'Проверка', 'type' => 'inspection', 'required' => true]]]);
        try {
            $this->completed($work, ['checklist_answers' => ['safety' => ['value' => 'na', 'note' => '']]]);
            $this->fail('Missing explanation accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('checklist_answers', $e->errors());
        }
        $this->assertSame('open', $work->fresh()->status);
    }

    public function test_critical_work_cannot_be_self_accepted(): void
    {
        $work = $this->completed($this->work(['priority' => 'critical']));
        $this->assertHttpStatus(403, fn () => $this->operations->acceptWork($this->operator, $work, ['expected_version' => $work->version, 'note' => 'Проверил', 'counts_for_plan' => false]));
        $other = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->operations->acceptWork($other, $work, ['expected_version' => $work->version, 'note' => 'Проверено другим сотрудником', 'counts_for_plan' => false]);
        $this->assertSame('closed', $work->fresh()->status);
    }

    public function test_completion_plan_uses_fact_date_not_late_acceptance_and_scheduler_is_idempotent(): void
    {
        $plan = $this->operations->createPlan($this->operator, ['asset_uuid' => $this->asset->uuid, 'name' => 'Профилактика', 'mode' => 'completion', 'interval_unit' => 'day', 'interval_count' => 90, 'first_due_on' => '2026-10-01', 'lead_days' => 0]);
        $this->assertSame(1, $this->operations->generateDuePlans());
        $this->assertSame(0, $this->operations->generateDuePlans());
        $work = WorkOrder::whereNotNull('maintenance_occurrence_id')->firstOrFail();
        $work = $this->completed($work, ['completed_at' => '2026-10-10 09:00']);
        $this->assertSame('2026-10-01', $plan->fresh()->next_due_on->toDateString());
        $this->operations->acceptWork($this->operator, $work, ['expected_version' => $work->version, 'note' => 'Принято позднее выполнения', 'counts_for_plan' => true]);
        $this->assertSame('2027-01-08', $plan->fresh()->next_due_on->toDateString());
        $this->assertSame('2026-10-10', $plan->fresh()->last_completed_on->toDateString());
    }

    public function test_due_today_is_generated_in_organisation_timezone_without_one_day_delay(): void
    {
        $this->operations->createPlan($this->operator, ['asset_uuid' => $this->asset->uuid, 'name' => 'Сегодня', 'mode' => 'calendar', 'interval_unit' => 'month', 'interval_count' => 1, 'first_due_on' => '2026-10-15', 'lead_days' => 0]);
        $this->assertSame(1, $this->operations->generateDuePlans());
        $this->assertSame('2026-10-15', WorkOrder::firstOrFail()->due_on->toDateString());
    }

    public function test_cancellation_does_not_reset_due_or_create_an_automatic_retry(): void
    {
        $plan = $this->operations->createPlan($this->operator, ['asset_uuid' => $this->asset->uuid, 'name' => 'Осмотр', 'mode' => 'calendar', 'interval_unit' => 'month', 'interval_count' => 1, 'first_due_on' => '2026-10-01', 'lead_days' => 0]);
        $this->operations->generateDuePlans();
        $work = WorkOrder::firstOrFail();
        $this->operations->transitionWork($this->operator, $work, ['expected_version' => $work->version, 'action' => 'cancel', 'reason' => 'Нет доступа']);
        $this->operations->generateDuePlans();
        $this->assertSame(1, WorkOrder::count());
        $this->assertSame('2026-10-01', $plan->fresh()->next_due_on->toDateString());
        $this->operations->decideOccurrence($this->operator, $plan->occurrences()->first(), ['expected_version' => $plan->fresh()->version, 'action' => 'retry', 'reason' => 'Доступ предоставлен']);
        $this->assertSame(2, WorkOrder::count());
    }

    public function test_plan_edit_does_not_change_issued_checklist_or_due(): void
    {
        $plan = $this->operations->createPlan($this->operator, ['asset_uuid' => $this->asset->uuid, 'name' => 'Осмотр', 'mode' => 'calendar', 'interval_unit' => 'month', 'interval_count' => 1, 'first_due_on' => '2026-10-01', 'lead_days' => 0, 'checklist_schema' => [['key' => 'old', 'label' => 'Прежний пункт', 'type' => 'check', 'required' => true]]]);
        $this->operations->generateDuePlans();
        $work = WorkOrder::firstOrFail();
        $this->operations->changePlan($this->operator, $plan, ['expected_version' => $plan->version, 'status' => 'active', 'next_due_on' => '2026-11-01', 'checklist_schema' => [['key' => 'new', 'label' => 'Новый пункт', 'type' => 'text', 'required' => false]], 'reason' => 'Новый срок для будущих работ']);
        $this->assertSame('old', $work->fresh()->checklist_schema[0]['key']);
        $this->assertSame('2026-10-01', $work->fresh()->due_on->toDateString());
    }

    public function test_closed_result_correction_preserves_old_text_and_acceptance_dates(): void
    {
        $work = $this->completed($this->work());
        $work = $this->operations->acceptWork($this->operator, $work, ['expected_version' => $work->version, 'note' => 'Принято', 'counts_for_plan' => false]);
        $accepted = $work->accepted_at->toIso8601String();
        $fact = $work->completed_at->toIso8601String();
        $this->operations->correctClosedWork($this->operator, $work, ['expected_version' => $work->version, 'result' => 'Уточнён результат', 'reason' => 'Исправлена опечатка']);
        $event = $work->events()->reorder('id', 'desc')->first();
        $this->assertSame('Осмотр выполнен', $event->data['previous_result']);
        $this->assertSame($accepted, $work->fresh()->accepted_at->toIso8601String());
        $this->assertSame($fact, $work->fresh()->completed_at->toIso8601String());
    }

    public function test_snapshot_is_materialized_and_same_scan_does_not_inflate_progress(): void
    {
        $campaign = $this->activeCampaign();
        $first = $this->observation($campaign, 'retry-1');
        $again = $this->observation($campaign, 'retry-1');
        $this->assertSame($first->id, $again->id);
        $other = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->observation($campaign, 'independent', $this->a, $other);
        $this->assertSame(2, $campaign->observations()->count());
        $this->assertSame(['expected' => 1, 'observed' => 1], $this->operations->progress($campaign));
        $this->asset->update(['name' => 'Новое имя']);
        $this->assertSame('Тестовый предмет', $campaign->snapshotItems()->first()->name);
    }

    public function test_observation_key_cannot_be_reused_with_other_location(): void
    {
        $campaign = $this->activeCampaign();
        $this->observation($campaign, 'same');
        $this->assertHttpStatus(409, fn () => $this->observation($campaign, 'same', $this->b));
    }

    public function test_full_uuid_qr_url_and_human_code_count_the_same_asset_once(): void
    {
        $campaign = $this->activeCampaign();
        $url = 'https://skladex.vve.local/p/a/'.$this->asset->uuid;
        $full = $this->observation($campaign, 'full-url', null, null, $url);
        $plain = $this->observation($campaign, 'human-code');
        $this->assertSame($this->asset->id, $full->asset_id);
        $this->assertSame($plain->asset_id, $full->asset_id);
        $this->assertSame($url, $full->raw_code);
        $this->assertSame($this->asset->code, $full->asset_code);
        $this->assertSame(['expected' => 1, 'observed' => 1], $this->operations->progress($campaign));
    }

    public function test_legacy_eq_alias_url_resolves_explicit_target_without_synthesizing_id(): void
    {
        $campaign = $this->activeCampaign();
        QrAlias::create(['alias' => 'asset-code:EQ-999999', 'target_type' => 'asset', 'target_id' => $this->asset->id]);
        $url = 'http://skladex.vve.local/eq/EQ-999999';
        $observation = $this->observation($campaign, 'legacy-url', null, null, $url);
        $this->assertSame($this->asset->id, $observation->asset_id);
        $this->assertSame($url, $observation->raw_code);
        $this->assertSame(1, Asset::count());
        $this->assertSame(['expected' => 1, 'observed' => 1], $this->operations->progress($campaign));
    }

    public function test_unicode_cabinet_alias_and_new_cabinet_qr_do_not_confirm_any_assets(): void
    {
        $campaign = $this->activeCampaign();
        QrAlias::create(['alias' => 'location-id:Сe-Cab-009', 'target_type' => 'location', 'target_id' => $this->a->id]);
        $url = 'https://skladex.vve.local/cabinet.php?id='.rawurlencode('Сe-Cab-009');
        $this->assertHttpStatus(422, fn () => $this->observation($campaign, 'cabinet-alias', null, null, $url));
        $this->assertHttpStatus(422, fn () => $this->observation($campaign, 'cabinet-new', null, null, 'https://skladex.vve.local/p/r/'.$this->a->uuid));
        $this->assertSame(0, $campaign->observations()->count());
        $this->assertSame(['expected' => 1, 'observed' => 0], $this->operations->progress($campaign));
    }

    public function test_foreign_host_cannot_impersonate_a_known_asset_qr(): void
    {
        $campaign = $this->activeCampaign();
        $input = 'https://untrusted.invalid/p/a/'.$this->asset->uuid;
        $observation = $this->observation($campaign, 'untrusted', null, null, $input);
        $this->assertNull($observation->asset_id);
        $this->assertSame($input, $observation->raw_code);
        $this->assertSame(['expected' => 1, 'observed' => 0], $this->operations->progress($campaign));
    }

    public function test_archived_alias_target_stays_unknown_during_inventory(): void
    {
        $campaign = $this->activeCampaign();
        QrAlias::create(['alias' => 'asset-code:EQ-000250', 'target_type' => 'asset', 'target_id' => $this->asset->id]);
        $this->asset->update(['archived_at' => now(), 'version' => 2]);
        $observation = $this->observation($campaign, 'archived', null, null, '/eq/EQ-000250');
        $this->assertNull($observation->asset_id);
        $this->assertSame('/eq/EQ-000250', $observation->raw_code);
        $this->assertSame(0, $this->operations->progress($campaign)['observed']);
    }

    public function test_unknown_and_out_of_snapshot_do_not_increase_denominator(): void
    {
        $campaign = $this->activeCampaign(['asset_uuids' => [$this->asset->uuid]]);
        $extra = Asset::create(['code' => 'SKX-A-TEST-2', 'name' => 'Вне снимка', 'category_id' => $this->asset->category_id, 'location_id' => $this->a->id, 'status' => 'in_use', 'condition' => 'unknown', 'version' => 1]);
        $this->observation($campaign, 'extra', null, null, $extra->code);
        $this->observation($campaign, 'unknown', null, null, 'https://untrusted.invalid/action');
        $report = $this->operations->report($campaign);
        $this->assertSame(['expected' => 1, 'observed' => 0, 'extra' => 1, 'unknown' => 1], $report['summary']);
        $this->assertSame(2, Asset::count());
    }

    public function test_a_to_b_to_a_invalidates_old_observation_even_when_location_matches(): void
    {
        $campaign = $this->activeCampaign();
        $obs = $this->observation($campaign, 'before');
        $this->asset->update(['location_id' => $this->b->id, 'version' => 2]);
        $this->asset->update(['location_id' => $this->a->id, 'version' => 3]);
        $campaign = $this->operations->beginReconciliation($this->operator, $campaign, $campaign->version);
        $this->assertHttpStatus(409, fn () => $this->operations->resolve($this->operator, $campaign, ['expected_version' => $campaign->version, 'asset_uuid' => $this->asset->uuid, 'asset_expected_version' => 3, 'observation_uuid' => $obs->uuid, 'decision' => 'confirmed', 'reason' => 'Попытка подтвердить старый скан']));
        $this->assertSame('stale', $this->operations->report($campaign)['items'][0]['state']);
    }

    public function test_inventory_move_requires_fresh_observation_and_creates_normal_movement(): void
    {
        $campaign = $this->activeCampaign();
        $obs = $this->observation($campaign, 'elsewhere', $this->b);
        $campaign = $this->operations->beginReconciliation($this->operator, $campaign, $campaign->version);
        $this->operations->resolve($this->operator, $campaign, ['expected_version' => $campaign->version, 'asset_uuid' => $this->asset->uuid, 'asset_expected_version' => 1, 'observation_uuid' => $obs->uuid, 'decision' => 'move', 'reason' => 'Подтверждено ответственным']);
        $this->assertSame($this->b->id, $this->asset->fresh()->location_id);
        $this->assertDatabaseHas('asset_movements', ['asset_id' => $this->asset->id, 'from_location_id' => $this->a->id, 'to_location_id' => $this->b->id]);
    }

    public function test_overlapping_campaign_is_blocked_until_first_is_closed(): void
    {
        $first = $this->activeCampaign();
        $second = $this->operations->createCampaign($this->operator, ['name' => 'Пересекается', 'asset_uuids' => [$this->asset->uuid]]);
        $this->assertHttpStatus(409, fn () => $this->operations->startCampaign($this->operator, $second, $second->version));
        $this->operations->cancelCampaign($this->operator, $first, ['expected_version' => $first->version, 'reason' => 'Отменён тестовый обход']);
        $this->assertSame('active', $this->operations->startCampaign($this->operator, $second, $second->version)->status);
    }

    public function test_room_scope_overlap_is_blocked_even_after_old_item_leaves_and_new_item_arrives(): void
    {
        $first = $this->activeCampaign(['location_uuids' => [$this->a->uuid]]);
        $this->asset->update(['location_id' => $this->b->id, 'version' => 2]);
        Asset::create(['code' => 'SKX-A-ARRIVED', 'name' => 'Новый предмет', 'category_id' => $this->asset->category_id, 'location_id' => $this->a->id, 'status' => 'in_use', 'condition' => 'unknown', 'version' => 1]);
        $second = $this->operations->createCampaign($this->operator, ['name' => 'Тот же кабинет', 'location_uuids' => [$this->a->uuid]]);
        $this->assertHttpStatus(409, fn () => $this->operations->startCampaign($this->operator, $second, $second->version));
        $this->assertSame(1, $first->snapshotItems()->count());
    }

    public function test_late_post_cannot_change_closed_report_and_rename_keeps_snapshot(): void
    {
        $campaign = $this->activeCampaign();
        $this->observation($campaign, 'first');
        $campaign = $this->operations->beginReconciliation($this->operator, $campaign, $campaign->version);
        $report = $this->operations->closeCampaign($this->operator, $campaign, $campaign->version);
        $payload = $report->payload;
        $this->asset->update(['name' => 'Переименовано позднее']);
        $this->assertHttpStatus(409, fn () => $this->observation($campaign, 'late'));
        $this->assertSame($payload, $report->fresh()->payload);
        $this->assertSame('Тестовый предмет', $report->fresh()->payload['items'][0]['name']);
        $this->assertSame('in_use', $this->asset->fresh()->status);
    }

    public function test_not_found_needs_explicit_resolution_and_never_writes_off_asset(): void
    {
        $campaign = $this->activeCampaign();
        $campaign = $this->operations->beginReconciliation($this->operator, $campaign, $campaign->version);
        $this->assertHttpStatus(422, fn () => $this->operations->closeCampaign($this->operator, $campaign, $campaign->version));
        $this->operations->resolve($this->operator, $campaign, ['expected_version' => $campaign->version, 'asset_uuid' => $this->asset->uuid, 'asset_expected_version' => 1, 'decision' => 'not_found', 'reason' => 'Не предъявлен после повторного поиска']);
        $this->operations->closeCampaign($this->operator, $campaign->fresh(), $campaign->fresh()->version);
        $this->assertSame('in_use', $this->asset->fresh()->status);
    }
}
