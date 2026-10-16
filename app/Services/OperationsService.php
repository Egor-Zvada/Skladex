<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Category;
use App\Models\InventoryCampaign;
use App\Models\InventoryObservation;
use App\Models\InventoryReport;
use App\Models\InventoryResolution;
use App\Models\InventorySnapshotItem;
use App\Models\Location;
use App\Models\MaintenanceOccurrence;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRequest;
use App\Models\SoftwareInstallation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderEvent;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OperationsService
{
    public const WORK_STATUSES = ['open' => 'Открыта', 'in_progress' => 'В работе', 'on_hold' => 'Приостановлена', 'completed' => 'Выполнена, ожидает принятия', 'closed' => 'Закрыта', 'cancelled' => 'Отменена'];

    public const CAMPAIGN_STATUSES = ['draft' => 'Черновик', 'active' => 'Проводится', 'reconciling' => 'Сверка', 'closed' => 'Закрыта', 'cancelled' => 'Отменена'];

    public const PRIORITIES = ['low' => 'Низкий', 'normal' => 'Обычный', 'high' => 'Высокий', 'critical' => 'Критичный'];

    public const KINDS = ['inspection' => 'Осмотр', 'preventive' => 'Профилактика', 'diagnostics' => 'Диагностика', 'repair' => 'Ремонт', 'upgrade' => 'Замена / обновление', 'external' => 'Внешний сервис'];

    public function createWork(User $actor, array $input): WorkOrder
    {
        Access::check($actor, 'write');
        $data = $this->validateWork($input);

        return DB::transaction(function () use ($actor, $data) {
            $work = WorkOrder::create($this->subject($data) + Arr::only($data, ['title', 'kind', 'priority', 'description', 'due_on', 'assignee_id', 'checklist_schema', 'requires_other_acceptor']) + ['created_by' => $actor->id]);
            $this->event($work, $actor, 'created', null, 'open');
            $this->notify($work->assignee_id, 'assigned:'.$work->uuid, 'Назначена работа: '.$work->title, 'maintenance.work.show', $work->uuid);

            return $work;
        });
    }

    public function recordCompletedWork(User $actor, array $input): WorkOrder
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($actor, $input) {
            $work = $this->createWork($actor, $input);

            return $this->completeWork($actor, $work, array_merge($input, ['expected_version' => $work->version]));
        });
    }

    public function updateWork(User $actor, WorkOrder $work, array $input): WorkOrder
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'assignee_id' => 'nullable|integer|exists:users,id', 'due_on' => 'nullable|date_format:Y-m-d', 'priority' => ['required', Rule::in(array_keys(self::PRIORITIES))], 'reason' => 'required|string|max:10000'])->validate();

        return DB::transaction(function () use ($actor, $work, $data) {
            $row = WorkOrder::whereKey($work->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            abort_unless(in_array($row->status, ['open', 'in_progress', 'on_hold'], true), 409, 'Нельзя менять параметры завершённой работы.');
            $row->fill(Arr::only($data, ['assignee_id', 'due_on', 'priority']));
            $row->version++;
            $row->save();
            $this->event($row, $actor, 'reassigned', $row->status, $row->status, $data['reason']);
            $this->notify($row->assignee_id, 'assigned:'.$row->uuid.':'.$row->version, 'Изменено назначение: '.$row->title, 'maintenance.work.show', $row->uuid);

            return $row;
        });
    }

    public function createRequest(User $actor, array $input): MaintenanceRequest
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, $this->subjectRules() + ['title' => 'required|string|max:200', 'description' => 'required|string|max:10000', 'priority' => ['required', Rule::in(array_keys(self::PRIORITIES))]])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $request = MaintenanceRequest::create($this->subject($data) + Arr::only($data, ['title', 'description', 'priority']) + ['created_by' => $actor->id]);
            Audit::record('maintenance.request.created', $request);

            return $request;
        });
    }

    public function triageRequest(User $actor, MaintenanceRequest $request, array $input): MaintenanceRequest
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'decision' => ['required', Rule::in(['accepted', 'rejected', 'needs_info', 'merged'])], 'reason' => 'required|string|max:10000', 'work_order_uuid' => 'nullable|uuid'])->validate();

        return DB::transaction(function () use ($actor, $request, $data) {
            $row = MaintenanceRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            abort_unless(in_array($row->status, ['new', 'needs_info'], true), 409, 'По заявке уже принято решение.');
            if ($data['decision'] === 'accepted') {
                $work = WorkOrder::create(Arr::only($row->getAttributes(), ['asset_id', 'software_installation_id', 'title', 'description', 'priority']) + ['created_by' => $actor->id]);
                $this->event($work, $actor, 'request.accepted', null, 'open');
                $row->work_order_id = $work->id;
            } elseif ($data['decision'] === 'merged') {
                $work = WorkOrder::where('uuid', $data['work_order_uuid'] ?? '')->firstOrFail();
                abort_unless($work->asset_id === $row->asset_id && $work->software_installation_id === $row->software_installation_id, 422, 'Объединять можно только обращения по одному объекту.');
                $row->work_order_id = $work->id;
            }
            $row->status = $data['decision'];
            $row->decision_note = $data['reason'];
            $row->version++;
            $row->save();
            Audit::record('maintenance.request.triaged', $row, ['decision' => $row->status, 'work_order_id' => $row->work_order_id]);

            return $row;
        });
    }

    public function transitionWork(User $actor, WorkOrder $work, array $input): WorkOrder
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'action' => ['required', Rule::in(['start', 'hold', 'resume', 'cancel', 'reject_result'])], 'reason' => 'nullable|string|max:10000'])->validate();

        return DB::transaction(function () use ($actor, $work, $data) {
            $row = WorkOrder::whereKey($work->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            $from = $row->status;
            $to = match ($data['action']) {
                'start' => $from === 'open' ? 'in_progress' : null,
                'hold' => in_array($from, ['open', 'in_progress'], true) ? 'on_hold' : null,
                'resume' => $from === 'on_hold' ? ($row->resume_status ?? 'in_progress') : null,
                'cancel' => in_array($from, ['open', 'in_progress', 'on_hold'], true) ? 'cancelled' : null,
                'reject_result' => $from === 'completed' ? 'in_progress' : null,
            };
            abort_if($to === null, 409, 'Этот переход состояния недоступен.');
            if (in_array($data['action'], ['hold', 'cancel', 'reject_result'], true)) {
                $this->requireReason($data['reason'] ?? null);
            }
            if ($data['action'] === 'reject_result') {
                Access::check($actor, 'accept-work');
            }
            if ($to === 'on_hold') {
                $row->resume_status = $from;
            }
            // Previous result remains in the immutable transition event when rework begins.
            $eventData = $from === 'completed' ? ['previous_result' => $row->result, 'previous_answers' => $row->checklist_answers, 'completed_at' => $row->completed_at?->toIso8601String()] : [];
            if ($data['action'] === 'reject_result') {
                $row->completed_at = null;
                $row->completed_by = null;
            }
            $row->status = $to;
            $row->version++;
            $row->save();
            $this->event($row, $actor, $data['action'], $from, $to, $data['reason'] ?? null, $eventData);

            return $row;
        });
    }

    public function completeWork(User $actor, WorkOrder $work, array $input): WorkOrder
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, [
            'expected_version' => 'required|integer|min:1', 'result' => 'required|string|max:20000', 'completed_at' => 'required|date',
            'reported_condition' => ['nullable', Rule::in(['working', 'limited', 'faulty', 'unknown'])],
            'remaining_restrictions' => 'nullable|string|max:10000', 'checklist_answers' => 'nullable|array',
            'labour_minutes' => 'nullable|integer|min:0|max:1000000', 'service_cost' => 'nullable|numeric|min:0|max:999999999999',
            'materials' => 'nullable|array|max:100', 'materials.*.name' => 'required|string|max:200',
            'materials.*.quantity' => 'required|numeric|gt:0|max:1000000', 'materials.*.unit_cost' => 'nullable|numeric|min:0|max:999999999999',
        ])->validate();

        return DB::transaction(function () use ($actor, $work, $data) {
            $row = WorkOrder::whereKey($work->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            abort_unless(in_array($row->status, ['open', 'in_progress', 'on_hold'], true), 409, 'Работа уже завершена либо отменена.');
            $fact = CarbonImmutable::parse($data['completed_at'], MaintenanceSchedule::TIMEZONE)->utc();
            if ($fact->isFuture()) {
                throw ValidationException::withMessages(['completed_at' => 'Фактическое выполнение не может быть в будущем.']);
            }
            $answers = $this->validateAnswers($row->checklist_schema ?? [], $data['checklist_answers'] ?? []);
            $from = $row->status;
            $row->fill(Arr::only($data, ['result', 'reported_condition', 'remaining_restrictions', 'labour_minutes', 'service_cost', 'materials']));
            $row->checklist_answers = $answers;
            $row->completed_at = $fact;
            $row->result_recorded_at = now();
            $row->completed_by = $actor->id;
            $row->status = 'completed';
            $row->version++;
            $row->save();
            $this->event($row, $actor, 'completed', $from, 'completed', null, ['result' => $row->result, 'answers' => $answers]);
            $this->notify($row->created_by, 'accept:'.$row->uuid.':'.$row->version, 'Ожидает принятия: '.$row->title, 'maintenance.work.show', $row->uuid);

            return $row;
        });
    }

    public function acceptWork(User $actor, WorkOrder $work, array $input): WorkOrder
    {
        Access::check($actor, 'accept-work');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'note' => 'required|string|max:10000', 'counts_for_plan' => 'required|boolean'])->validate();

        return DB::transaction(function () use ($actor, $work, $data) {
            // All plan-related commands lock plan -> occurrence -> work, including cancellation/replacement.
            $plan = null;
            $occurrence = null;
            if ($work->maintenance_occurrence_id) {
                $occurrence = MaintenanceOccurrence::findOrFail($work->maintenance_occurrence_id);
                $plan = MaintenancePlan::whereKey($occurrence->maintenance_plan_id)->lockForUpdate()->firstOrFail();
                $occurrence = MaintenanceOccurrence::whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
            }
            $row = WorkOrder::whereKey($work->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            abort_unless($row->status === 'completed', 409, 'Принять можно только выполненную работу.');
            abort_if(($row->requires_other_acceptor || $row->priority === 'critical') && $row->completed_by === $actor->id, 403, 'Нужен другой уполномоченный сотрудник для принятия.');
            abort_if($data['counts_for_plan'] && ! $occurrence, 422, 'Работа не связана с экземпляром регламента.');
            $row->status = 'closed';
            $row->accepted_by = $actor->id;
            $row->accepted_at = now();
            $row->acceptance_note = $data['note'];
            $row->counts_for_plan = (bool) $data['counts_for_plan'];
            $row->version++;
            $row->save();
            if ($occurrence && $row->counts_for_plan) {
                abort_unless($occurrence->status === 'pending', 409, 'Этот срок уже засчитан или пропущен.');
                $schedule = $occurrence->schedule_snapshot;
                $fact = $row->completed_at->setTimezone(MaintenanceSchedule::TIMEZONE)->toDateString();
                $due = $occurrence->due_on->toDateString();
                abort_if(MaintenanceSchedule::date($fact)->lessThan(MaintenanceSchedule::date($due)->subDays((int) ($schedule['lead_days'] ?? 0))), 422, 'Работа выполнена до допустимого окна этого срока.');
                $occurrence->status = 'completed';
                $occurrence->decided_by = $actor->id;
                $occurrence->decided_at = now();
                if ($schedule['mode'] === 'calendar') {
                    $next = MaintenanceSchedule::after($schedule['anchor_on'], $schedule['interval_unit'], (int) $schedule['interval_count'], $fact);
                    $previous = MaintenanceSchedule::after($schedule['anchor_on'], $schedule['interval_unit'], (int) $schedule['interval_count'], $due);
                    $occurrence->missed_periods = max(0, $next['period'] - $previous['period']);
                    // Early completion must not regenerate the same calendar due date.
                    $nextDate = $next['date']->greaterThan($occurrence->due_on) ? $next['date'] : $previous['date'];
                } else {
                    $nextDate = MaintenanceSchedule::at($fact, $schedule['interval_unit'], (int) $schedule['interval_count']);
                }
                $occurrence->save();
                $plan->last_completed_on = $fact;
                // A newer plan revision retains its explicitly chosen next due date.
                if ($plan->next_due_on?->toDateString() === $due) {
                    $plan->next_due_on = $nextDate->toDateString();
                }
                $plan->version++;
                $plan->save();
            }
            $bad = collect($row->checklist_answers ?? [])->contains(fn ($answer) => in_array($answer['value'] ?? null, ['flag', 'fail'], true));
            if ($bad && ! WorkOrder::where('follow_up_for_id', $row->id)->exists()) {
                $follow = WorkOrder::create(['asset_id' => $row->asset_id, 'software_installation_id' => $row->software_installation_id, 'title' => 'Замечания: '.mb_substr($row->title, 0, 180), 'kind' => 'diagnostics', 'priority' => $row->priority, 'description' => $row->result.PHP_EOL.$row->remaining_restrictions, 'created_by' => $actor->id, 'assignee_id' => $row->assignee_id, 'follow_up_for_id' => $row->id]);
                $this->event($follow, $actor, 'inspection.follow_up', null, 'open');
            }
            // Acceptance deliberately never marks an Asset working or clears another defect.
            $this->event($row, $actor, 'accepted', 'completed', 'closed', $data['note'], ['counts_for_plan' => $row->counts_for_plan]);

            return $row;
        });
    }

    public function createPlan(User $actor, array $input): MaintenancePlan
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, $this->subjectRules() + [
            'name' => 'required|string|max:200', 'mode' => ['required', Rule::in(['calendar', 'completion'])],
            'interval_unit' => ['required', Rule::in(['day', 'week', 'month', 'year'])], 'interval_count' => 'required|integer|min:1|max:120',
            'first_due_on' => 'nullable|date_format:Y-m-d', 'lead_days' => 'required|integer|min:0|max:365',
            'assignee_id' => 'nullable|integer|exists:users,id', 'requires_other_acceptor' => 'sometimes|boolean',
        ] + $this->checklistRules())->validate();

        return DB::transaction(function () use ($actor, $data) {
            $subject = $this->subject($data, true);
            $plan = MaintenancePlan::create($subject + Arr::only($data, ['name', 'mode', 'interval_unit', 'interval_count', 'lead_days', 'assignee_id', 'requires_other_acceptor', 'checklist_schema']) + ['anchor_on' => $data['first_due_on'] ?? null, 'next_due_on' => $data['first_due_on'] ?? null, 'created_by' => $actor->id]);
            Audit::record('maintenance.plan.created', $plan);

            return $plan;
        });
    }

    public function changePlan(User $actor, MaintenancePlan $plan, array $input): MaintenancePlan
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'status' => ['required', Rule::in(['active', 'paused'])], 'next_due_on' => 'nullable|date_format:Y-m-d', 'reason' => 'required|string|max:10000',
            'name' => 'sometimes|required|string|max:200', 'mode' => ['sometimes', 'required', Rule::in(['calendar', 'completion'])],
            'interval_unit' => ['sometimes', 'required', Rule::in(['day', 'week', 'month', 'year'])], 'interval_count' => 'sometimes|required|integer|min:1|max:120',
            'lead_days' => 'sometimes|required|integer|min:0|max:365', 'assignee_id' => 'sometimes|nullable|integer|exists:users,id', 'requires_other_acceptor' => 'sometimes|boolean',
        ] + $this->checklistRules())->validate();

        return DB::transaction(function () use ($plan, $data) {
            $row = MaintenancePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            $scheduleChanged = false;
            foreach (['mode', 'interval_unit', 'interval_count'] as $field) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $row->$field) {
                    $scheduleChanged = true;
                }
            }
            if ($scheduleChanged && empty($data['next_due_on'])) {
                throw ValidationException::withMessages(['next_due_on' => 'При изменении календарного правила укажите первый срок нового расписания.']);
            }
            $row->fill(Arr::only($data, ['name', 'mode', 'interval_unit', 'interval_count', 'lead_days', 'assignee_id', 'requires_other_acceptor', 'checklist_schema']));
            $row->status = $data['status'];
            if (array_key_exists('next_due_on', $data) && $data['next_due_on']) {
                $row->next_due_on = $data['next_due_on'];
                $row->anchor_on ??= $data['next_due_on'];
            }
            if ($scheduleChanged) {
                $row->anchor_on = $data['next_due_on'];
            }
            $row->version++;
            $row->save();
            Audit::record('maintenance.plan.changed', $row, ['reason' => $data['reason']]);

            return $row;
        });
    }

    public function correctClosedWork(User $actor, WorkOrder $work, array $input): WorkOrder
    {
        Access::check($actor, 'accept-work');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:10000', 'result' => 'required|string|max:20000'])->validate();

        return DB::transaction(function () use ($actor, $work, $data) {
            $row = WorkOrder::whereKey($work->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            abort_unless($row->status === 'closed', 409, 'Коррекция применяется к закрытому результату.');
            $previous = $row->result;
            $row->result = $data['result'];
            $row->version++;
            $row->save();
            $this->event($row, $actor, 'result.corrected', 'closed', 'closed', $data['reason'], ['previous_result' => $previous, 'corrected_result' => $row->result]);

            // Factual dates, schedule credit and original acceptance remain unchanged.
            return $row;
        });
    }

    /** Internal scheduler entry point: never mounted as a public GET. */
    public function generateDuePlans(): int
    {
        $count = 0;
        MaintenancePlan::where('status', 'active')->whereNotNull('next_due_on')->orderBy('id')->chunkById(100, function ($plans) use (&$count) {
            foreach ($plans as $plan) {
                $count += $this->generateOnePlan($plan->id) ? 1 : 0;
            }
        });

        return $count;
    }

    public function generatePlan(User $actor, MaintenancePlan $plan, int $expected): ?WorkOrder
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($plan, $expected) {
            $row = MaintenancePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $expected);

            return $this->generateOnePlan($row->id);
        });
    }

    private function generateOnePlan(int $id): ?WorkOrder
    {
        return DB::transaction(function () use ($id) {
            $plan = MaintenancePlan::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($plan->status !== 'active' || ! $plan->next_due_on) {
                return null;
            }
            if ($plan->asset_id && ! Asset::whereKey($plan->asset_id)->whereNull('archived_at')->where('status', '!=', 'written_off')->exists()) {
                return null;
            }
            if ($plan->software_installation_id && ! SoftwareInstallation::whereKey($plan->software_installation_id)->whereNull('archived_at')->exists()) {
                return null;
            }
            $today = CarbonImmutable::now(MaintenanceSchedule::TIMEZONE)->startOfDay();
            if (MaintenanceSchedule::date($plan->next_due_on->toDateString())->greaterThan($today->addDays($plan->lead_days))) {
                return null;
            }
            $occurrence = $plan->occurrences()->where('status', 'pending')->lockForUpdate()->first();
            if (! $occurrence) {
                $occurrence = $plan->occurrences()->create(['sequence' => ((int) $plan->occurrences()->max('sequence')) + 1, 'due_on' => $plan->next_due_on->toDateString(), 'schedule_snapshot' => ['mode' => $plan->mode, 'anchor_on' => $plan->anchor_on?->toDateString(), 'interval_unit' => $plan->interval_unit, 'interval_count' => $plan->interval_count, 'lead_days' => $plan->lead_days, 'plan_version' => $plan->version]]);
            }
            // Cancelled or accepted-but-not-credited work leaves the obligation outstanding.
            // Replacement requires the explicit retry command below, not each scheduler tick.
            if ($occurrence->workOrders()->exists()) {
                $this->remind($plan, $occurrence);

                return null;
            }
            $work = $this->workFromOccurrence($plan, $occurrence, 1);
            $this->remind($plan, $occurrence);

            return $work;
        });
    }

    public function decideOccurrence(User $actor, MaintenanceOccurrence $occurrence, array $input): ?WorkOrder
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'action' => ['required', Rule::in(['retry', 'skip'])], 'reason' => 'required|string|max:10000'])->validate();

        return DB::transaction(function () use ($actor, $occurrence, $data) {
            $plan = MaintenancePlan::whereKey($occurrence->maintenance_plan_id)->lockForUpdate()->firstOrFail();
            Version::check($plan, $data['expected_version']);
            $row = MaintenanceOccurrence::whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->status === 'pending', 409, 'Срок уже завершён.');
            abort_if($row->workOrders()->whereNotIn('status', ['closed', 'cancelled'])->exists(), 409, 'Сначала завершите или отмените текущую работу.');
            if ($data['action'] === 'retry') {
                $work = $this->workFromOccurrence($plan, $row, ((int) $row->workOrders()->max('attempt')) + 1);
                Audit::record('maintenance.occurrence.retried', $row, ['reason' => $data['reason']]);

                return $work;
            }
            $row->status = 'skipped';
            $row->skip_reason = $data['reason'];
            $row->decided_by = $actor->id;
            $row->decided_at = now();
            $row->save();
            $s = $row->schedule_snapshot;
            $plan->next_due_on = $s['mode'] === 'calendar' ? MaintenanceSchedule::after($s['anchor_on'], $s['interval_unit'], (int) $s['interval_count'], $row->due_on->toDateString())['date']->toDateString() : null;
            // A completion-based skip cannot invent a new factual completion baseline.
            $plan->version++;
            $plan->save();
            Audit::record('maintenance.occurrence.skipped', $row, ['reason' => $data['reason']]);

            return null;
        });
    }

    public function createCampaign(User $actor, array $input): InventoryCampaign
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['name' => 'required|string|max:200', 'location_uuids' => 'nullable|array|max:500', 'location_uuids.*' => 'uuid|exists:locations,uuid', 'category_ids' => 'nullable|array|max:500', 'category_ids.*' => 'integer|exists:categories,id', 'asset_uuids' => 'nullable|array|max:5000', 'asset_uuids.*' => 'uuid|exists:assets,uuid'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $campaign = InventoryCampaign::create(['name' => $data['name'], 'scope' => Arr::only($data, ['location_uuids', 'category_ids', 'asset_uuids']), 'created_by' => $actor->id]);
            Audit::record('inventory.created', $campaign);

            return $campaign;
        });
    }

    public function startCampaign(User $actor, InventoryCampaign $campaign, int $expected): InventoryCampaign
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($campaign, $expected) {
            DB::table('operation_locks')->where('name', 'inventory.start')->lockForUpdate()->first();
            $row = InventoryCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $expected);
            abort_unless($row->status === 'draft', 409, 'Кампания уже запущена.');
            $query = Asset::query()->whereNull('archived_at')->where('status', '!=', 'written_off');
            $scope = $row->scope;
            if (! empty($scope['location_uuids'])) {
                $query->whereIn('location_id', $this->descendantLocationIds($scope['location_uuids']));
            }
            if (! empty($scope['category_ids'])) {
                $query->whereIn('category_id', $scope['category_ids']);
            }
            if (! empty($scope['asset_uuids'])) {
                $query->whereIn('uuid', $scope['asset_uuids']);
            }
            $assets = $query->orderBy('id')->lockForUpdate()->get();
            abort_if($assets->isEmpty(), 422, 'В выбранной области пока нет оборудования для обхода.');
            abort_if(DB::table('inventory_asset_reservations')->whereIn('asset_id', $assets->modelKeys())->exists(), 409, 'Область пересекается с действующей кампанией.');
            $resolved = [
                'resolved_location_ids' => ! empty($scope['location_uuids']) ? $this->descendantLocationIds($scope['location_uuids']) : Location::whereNull('archived_at')->pluck('id')->all(),
                'resolved_category_ids' => $scope['category_ids'] ?? Category::pluck('id')->all(),
                'resolved_asset_ids' => ! empty($scope['asset_uuids']) ? $assets->modelKeys() : null,
            ];
            if (! $resolved['resolved_category_ids']) {
                $resolved['resolved_category_ids'] = Category::pluck('id')->all();
            }
            foreach (InventoryCampaign::whereIn('status', ['active', 'reconciling'])->get() as $other) {
                $otherScope = $other->scope;
                if (! isset($otherScope['resolved_location_ids'])) {
                    continue;
                }
                $overlap = false;
                if ($resolved['resolved_asset_ids'] === null && ($otherScope['resolved_asset_ids'] ?? null) === null) {
                    $overlap = (bool) array_intersect($resolved['resolved_location_ids'], $otherScope['resolved_location_ids']) && (bool) array_intersect($resolved['resolved_category_ids'], $otherScope['resolved_category_ids']);
                } else {
                    $candidate = Asset::query();
                    if ($resolved['resolved_asset_ids'] !== null) {
                        $candidate->whereIn('id', $resolved['resolved_asset_ids']);
                    } else {
                        $candidate->whereIn('location_id', $resolved['resolved_location_ids'])->whereIn('category_id', $resolved['resolved_category_ids']);
                    }
                    if (($otherScope['resolved_asset_ids'] ?? null) !== null) {
                        $candidate->whereIn('id', $otherScope['resolved_asset_ids']);
                    } else {
                        $candidate->whereIn('location_id', $otherScope['resolved_location_ids'])->whereIn('category_id', $otherScope['resolved_category_ids']);
                    }
                    $overlap = $candidate->exists();
                }
                abort_if($overlap, 409, 'Помещения и категории пересекаются с действующей кампанией.');
            }
            foreach ($assets as $asset) {
                InventorySnapshotItem::create(['inventory_campaign_id' => $row->id, 'asset_id' => $asset->id, 'asset_version' => $asset->version, 'code' => $asset->code, 'name' => $asset->name, 'location_id' => $asset->location_id, 'location_name' => Location::with('building')->find($asset->location_id)?->display_name, 'responsible_name' => $asset->responsible_name ?: $asset->responsible?->name, 'status' => $asset->status, 'condition' => $asset->condition, 'created_at' => now()]);
                DB::table('inventory_asset_reservations')->insert(['asset_id' => $asset->id, 'inventory_campaign_id' => $row->id]);
            }
            $row->scope = $scope + $resolved;
            $row->status = 'active';
            $row->started_at = now();
            $row->version++;
            $row->save();
            Audit::record('inventory.started', $row, ['expected_count' => $assets->count()]);

            return $row;
        });
    }

    public function observe(User $actor, InventoryCampaign $campaign, array $input): InventoryObservation
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'code' => 'required|string|max:500', 'location_uuid' => 'required|uuid|exists:locations,uuid', 'client_key' => 'required|string|max:80', 'method' => ['required', Rule::in(['qr', 'manual'])], 'condition' => ['nullable', Rule::in(['working', 'limited', 'faulty', 'unknown'])], 'note' => 'nullable|string|max:10000'])->validate();

        return DB::transaction(function () use ($actor, $campaign, $data) {
            $row = InventoryCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $existing = $row->observations()->where('actor_id', $actor->id)->where('client_key', $data['client_key'])->first();
            if ($existing) {
                abort_unless($existing->raw_code === $data['code'] && $existing->location?->uuid === $data['location_uuid'] && $existing->method === $data['method'] && $existing->note === ($data['note'] ?? null) && $existing->condition === ($data['condition'] ?? null), 409, 'Ключ повтора уже использован для другого наблюдения.');

                return $existing;
            }
            Version::check($row, $data['expected_version']);
            abort_unless($row->status === 'active', 409, 'Приём наблюдений остановлен.');
            $location = Location::with('building')->where('uuid', $data['location_uuid'])->whereNull('archived_at')->firstOrFail();
            // Parse known QR routes/aliases as data only. The resolver never visits scanned URLs.
            $resolved = app(QrResolver::class)->resolve($data['code']);
            abort_if($resolved instanceof Location, 422, 'Это метка кабинета. Выберите кабинет в контексте обхода и сканируйте метку конкретного предмета.');
            $asset = $resolved instanceof Asset ? Asset::whereKey($resolved->id)->whereNull('archived_at')->lockForUpdate()->first() : null;
            $observation = $row->observations()->create(['asset_id' => $asset?->id, 'location_id' => $location->id, 'location_name' => $location->display_name, 'asset_code' => $asset?->code, 'asset_name' => $asset?->name, 'raw_code' => $data['code'], 'asset_version' => $asset?->version, 'method' => $data['method'], 'condition' => $data['condition'] ?? null, 'note' => $data['note'] ?? null, 'actor_id' => $actor->id, 'client_key' => $data['client_key'], 'observed_at' => now(), 'created_at' => now()]);
            Audit::record('inventory.observed', $observation, ['campaign_id' => $row->id, 'asset_id' => $asset?->id]);

            return $observation;
        });
    }

    public function beginReconciliation(User $actor, InventoryCampaign $campaign, int $expected): InventoryCampaign
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($campaign, $expected) {
            $row = InventoryCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $expected);
            abort_unless($row->status === 'active', 409, 'Кампания не находится в режиме обхода.');
            $row->status = 'reconciling';
            $row->reconciliation_at = now();
            $row->version++;
            $row->save();
            Audit::record('inventory.reconciliation.started', $row);

            return $row;
        });
    }

    public function resolve(User $actor, InventoryCampaign $campaign, array $input): InventoryResolution
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'asset_uuid' => 'nullable|uuid|exists:assets,uuid', 'observation_uuid' => 'nullable|uuid', 'asset_expected_version' => 'required_with:asset_uuid|nullable|integer|min:1', 'decision' => ['required', Rule::in(['confirmed', 'move', 'not_found', 'follow_up', 'ignore_unknown'])], 'reason' => 'required|string|max:10000', 'follow_up_assignee_id' => 'nullable|integer|exists:users,id'])->validate();

        return DB::transaction(function () use ($actor, $campaign, $data) {
            $row = InventoryCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            abort_unless($row->status === 'reconciling', 409, 'Решения принимаются в режиме сверки.');
            Codes::lockPhysicalGraph();
            $observation = empty($data['observation_uuid']) ? null : $row->observations()->where('uuid', $data['observation_uuid'])->firstOrFail();
            $asset = empty($data['asset_uuid']) ? null : Asset::where('uuid', $data['asset_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless($asset || ($observation && ! $observation->asset_id), 422, 'Укажите предмет или неизвестное наблюдение.');
            if ($asset) {
                Version::check($asset, $data['asset_expected_version'] ?? null);
                abort_unless($row->snapshotItems()->where('asset_id', $asset->id)->exists() || $row->observations()->where('asset_id', $asset->id)->exists(), 422, 'Предмет не относится к этой кампании.');
            }
            if ($data['decision'] === 'move') {
                abort_unless($asset && $observation && $observation->asset_id === $asset->id, 422, 'Для перемещения нужно наблюдение выбранного предмета.');
                abort_if((int) $observation->asset_version !== (int) $asset->version, 409, 'После наблюдения учёт менялся. Нужна повторная проверка.');
                abort_if(DB::table('physical_placements')->where('asset_id', $asset->id)->orWhere('parent_asset_id', $asset->id)->lockForUpdate()->get()->isNotEmpty(), 422, 'Предмет имеет физическое размещение или содержимое. Используйте контролируемое перемещение в карточке.');
                $from = $asset->location_id;
                abort_if($from === $observation->location_id, 422, 'Предмет уже находится в этом помещении.');
                $asset->location_id = $observation->location_id;
                $asset->version++;
                $asset->save();
                AssetMovement::create(['asset_id' => $asset->id, 'from_location_id' => $from, 'to_location_id' => $asset->location_id, 'from_status' => $asset->status, 'to_status' => $asset->status, 'reason' => 'Инвентаризация '.$row->name.': '.$data['reason'], 'actor_id' => $actor->id, 'asset_version' => $asset->version, 'occurred_at' => now()]);
                Audit::record('asset.inventory.moved', $asset, ['campaign_id' => $row->id, 'observation_id' => $observation->id, 'from_location_id' => $from, 'to_location_id' => $asset->location_id]);
            }
            if ($data['decision'] === 'confirmed') {
                abort_unless($asset, 422, 'Нужен известный предмет.');
                $observations = $row->observations()->where('asset_id', $asset->id)->get();
                abort_if($observations->isEmpty() || $observations->contains(fn ($o) => (int) $o->asset_version !== (int) $asset->version || $o->location_id !== $asset->location_id), 409, 'Наблюдения противоречат учёту или устарели. Назначьте повторную проверку.');
            }
            if ($data['decision'] === 'follow_up') {
                abort_if(empty($data['follow_up_assignee_id']), 422, 'Назначьте ответственного за дальнейшую проверку.');
            }
            if ($data['decision'] === 'ignore_unknown') {
                abort_if(! $observation || $observation->asset_id, 422, 'Это решение относится только к неизвестной метке.');
            }
            if ($data['decision'] === 'not_found') {
                abort_unless($asset && ! $row->observations()->where('asset_id', $asset->id)->exists(), 422, 'По предмету есть наблюдения; разберите их вместо отметки «не найден».');
            }
            $resolution = $row->resolutions()->create(['asset_id' => $asset?->id, 'inventory_observation_id' => $observation?->id, 'decision' => $data['decision'], 'reason' => $data['reason'], 'actor_id' => $actor->id, 'follow_up_assignee_id' => $data['follow_up_assignee_id'] ?? null, 'asset_version' => $asset?->version, 'created_at' => now()]);
            $row->version++;
            $row->save();
            Audit::record('inventory.resolved', $resolution, ['campaign_id' => $row->id, 'decision' => $data['decision']]);

            return $resolution;
        });
    }

    public function progress(InventoryCampaign $campaign): array
    {
        return ['expected' => $campaign->snapshotItems()->count(), 'observed' => $campaign->observations()->whereIn('asset_id', $campaign->snapshotItems()->select('asset_id'))->distinct()->count('asset_id')];
    }

    public function report(InventoryCampaign $campaign, bool $lockAssets = false): array
    {
        $snapshots = $campaign->snapshotItems()->get();
        $observations = $campaign->observations()->orderBy('id')->get();
        $resolutions = $campaign->resolutions()->orderBy('id')->get();
        $assetQuery = Asset::whereIn('id', $snapshots->pluck('asset_id')->merge($observations->pluck('asset_id')->filter())->unique())->orderBy('id');
        if ($lockAssets) {
            $assetQuery->lockForUpdate();
        }
        $assets = $assetQuery->get()->keyBy('id');
        $rows = [];
        foreach ($snapshots as $snapshot) {
            $seen = $observations->where('asset_id', $snapshot->asset_id);
            $asset = $assets->get($snapshot->asset_id);
            $decision = $resolutions->where('asset_id', $snapshot->asset_id)->last();
            if ($decision && (int) $decision->asset_version !== (int) $asset?->version) {
                $decision = null;
            }
            $locations = $seen->pluck('location_id')->unique();
            $stale = $seen->contains(fn ($o) => (int) $o->asset_version !== (int) $asset?->version);
            $state = $seen->isEmpty() ? 'unseen' : ($locations->count() > 1 ? 'conflict' : ($stale ? 'stale' : ($locations->first() !== $snapshot->location_id ? 'elsewhere' : 'found')));
            if ($seen->isNotEmpty() && (int) $asset?->version !== (int) $snapshot->asset_version && $state === 'found') {
                $state = 'changed';
            }
            $rows[] = ['asset_id' => $snapshot->asset_id, 'asset_uuid' => $asset?->uuid, 'code' => $snapshot->code, 'name' => $snapshot->name, 'expected_location' => $snapshot->location_name, 'expected_location_id' => $snapshot->location_id, 'expected_responsible' => $snapshot->responsible_name, 'expected_status' => $snapshot->status, 'expected_condition' => $snapshot->condition, 'snapshot_version' => $snapshot->asset_version, 'current_version' => $asset?->version, 'state' => $state, 'decision' => $decision?->decision, 'reason' => $decision?->reason, 'decision_actor_id' => $decision?->actor_id, 'decision_at' => $decision?->created_at, 'follow_up_assignee_id' => $decision?->follow_up_assignee_id, 'observations' => $seen->map(fn ($o) => $this->observationData($o))->values()->all()];
        }
        $extra = $observations->filter(fn ($o) => $o->asset_id && ! $snapshots->contains('asset_id', $o->asset_id))->groupBy('asset_id')->map(function ($seen) use ($resolutions, $assets) {
            $o = $seen->first();
            $r = $resolutions->where('asset_id', $o->asset_id)->last();
            if ($r && (int) $r->asset_version !== (int) $assets->get($o->asset_id)?->version) {
                $r = null;
            }

            return ['asset_id' => $o->asset_id, 'asset_uuid' => $assets->get($o->asset_id)?->uuid, 'name' => $o->asset_name, 'code' => $o->asset_code, 'current_version' => $assets->get($o->asset_id)?->version, 'decision' => $r?->decision, 'reason' => $r?->reason, 'observations' => $seen->map(fn ($item) => $this->observationData($item))->values()->all()];
        })->values()->all();
        $unknown = $observations->whereNull('asset_id')->map(function ($o) use ($resolutions) {
            $r = $resolutions->where('inventory_observation_id', $o->id)->last();

            return $this->observationData($o) + ['raw_code' => $o->raw_code, 'decision' => $r?->decision, 'reason' => $r?->reason];
        })->values()->all();

        return ['campaign' => ['uuid' => $campaign->uuid, 'name' => $campaign->name, 'started_at' => $campaign->started_at?->toIso8601String(), 'closed_at' => $campaign->closed_at?->toIso8601String()], 'summary' => ['expected' => count($rows), 'observed' => collect($rows)->filter(fn ($r) => count($r['observations']) > 0)->count(), 'extra' => count($extra), 'unknown' => count($unknown)], 'items' => $rows, 'extra' => $extra, 'unknown' => $unknown];
    }

    public function closeCampaign(User $actor, InventoryCampaign $campaign, int $expected): InventoryReport
    {
        Access::check($actor, 'write');

        return DB::transaction(function () use ($actor, $campaign, $expected) {
            $row = InventoryCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $expected);
            abort_unless($row->status === 'reconciling', 409, 'Сначала завершите обход и выполните сверку.');
            // Current reads under row locks avoid a stale repeatable-read snapshot at closure.
            $report = $this->report($row, true);
            foreach ($report['items'] as $item) {
                abort_if($item['state'] !== 'found' && ! $item['decision'], 422, 'По каждому расхождению нужно решение или дальнейшее действие с ответственным.');
            }
            foreach (array_merge($report['extra'], $report['unknown']) as $item) {
                abort_if(! $item['decision'], 422, 'Разберите найденные вне состава и неизвестные метки.');
            }
            $row->status = 'closed';
            $row->closed_at = now();
            $row->version++;
            $row->save();
            $report['campaign']['closed_at'] = $row->closed_at->toIso8601String();
            $frozen = $row->reports()->create(['revision' => 1, 'payload' => $report, 'created_by' => $actor->id, 'created_at' => now()]);
            DB::table('inventory_asset_reservations')->where('inventory_campaign_id', $row->id)->delete();
            Audit::record('inventory.closed', $row, ['report_id' => $frozen->id]);

            return $frozen;
        });
    }

    public function cancelCampaign(User $actor, InventoryCampaign $campaign, array $input): InventoryCampaign
    {
        Access::check($actor, 'write');
        $data = Validator::make($input, ['expected_version' => 'required|integer|min:1', 'reason' => 'required|string|max:10000'])->validate();

        return DB::transaction(function () use ($campaign, $data) {
            $row = InventoryCampaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            Version::check($row, $data['expected_version']);
            abort_if(in_array($row->status, ['closed', 'cancelled'], true), 409, 'Кампания уже завершена.');
            $row->status = 'cancelled';
            $row->cancellation_reason = $data['reason'];
            $row->version++;
            $row->save();
            DB::table('inventory_asset_reservations')->where('inventory_campaign_id', $row->id)->delete();
            Audit::record('inventory.cancelled', $row, ['reason' => $data['reason']]);

            return $row;
        });
    }

    private function subjectRules(): array
    {
        return ['asset_uuid' => 'nullable|uuid|exists:assets,uuid', 'software_installation_id' => 'nullable|integer|exists:software_installations,id'];
    }

    private function subject(array $data, bool $forPlan = false): array
    {
        $asset = empty($data['asset_uuid']) ? null : Asset::where('uuid', $data['asset_uuid'])->whereNull('archived_at')->firstOrFail();
        $software = empty($data['software_installation_id']) ? null : SoftwareInstallation::whereKey($data['software_installation_id'])->whereNull('archived_at')->firstOrFail();
        if (($asset === null) === ($software === null)) {
            throw ValidationException::withMessages(['asset_uuid' => 'Выберите ровно один объект: оборудование или установку ПО/сервис.']);
        }
        abort_if($forPlan && $asset?->status === 'written_off', 422, 'Нельзя назначить новый регламент списанному предмету.');

        return ['asset_id' => $asset?->id, 'software_installation_id' => $software?->id];
    }

    private function validateWork(array $input): array
    {
        return Validator::make($input, $this->subjectRules() + ['title' => 'required|string|max:200', 'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'priority' => ['required', Rule::in(array_keys(self::PRIORITIES))], 'description' => 'nullable|string|max:10000', 'due_on' => 'nullable|date_format:Y-m-d', 'assignee_id' => 'nullable|integer|exists:users,id', 'requires_other_acceptor' => 'sometimes|boolean'] + $this->checklistRules())->validate();
    }

    private function checklistRules(): array
    {
        return ['checklist_schema' => 'nullable|array|max:100', 'checklist_schema.*' => 'array:key,label,type,required', 'checklist_schema.*.key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,39}$/|distinct', 'checklist_schema.*.label' => 'required|string|max:200', 'checklist_schema.*.type' => ['required', Rule::in(['check', 'inspection', 'number', 'text'])], 'checklist_schema.*.required' => 'required|boolean'];
    }

    private function validateAnswers(array $schema, array $answers): array
    {
        $result = [];
        foreach ($schema as $step) {
            $answer = $answers[$step['key']] ?? [];
            if (! is_array($answer)) {
                throw ValidationException::withMessages(['checklist_answers' => 'Некорректный формат результата: '.$step['label']]);
            }
            $value = $answer['value'] ?? null;
            $note = $answer['note'] ?? null;
            if ($note !== null && ! is_string($note)) {
                throw ValidationException::withMessages(['checklist_answers' => 'Пояснение должно быть текстом.']);
            }
            $present = $value !== null && $value !== '';
            if (($step['required'] ?? false) && ! $present) {
                throw ValidationException::withMessages(['checklist_answers' => 'Заполните: '.$step['label']]);
            }
            if (! $present) {
                continue;
            }
            $valid = match ($step['type']) {
                'check' => in_array($value, [true, false, 1, 0, '1', '0'], true) && (! ($step['required'] ?? false) || in_array($value, [true, 1, '1'], true)),
                'inspection' => in_array($value, ['pass', 'flag', 'fail', 'na'], true),
                'number' => is_numeric($value) && is_finite((float) $value),
                'text' => is_string($value) && mb_strlen($value) <= 10000,
            };
            if (! $valid || ($step['type'] === 'inspection' && in_array($value, ['flag', 'fail', 'na'], true) && ! is_string($note)) || (is_string($note) && mb_strlen($note) > 10000)) {
                throw ValidationException::withMessages(['checklist_answers' => 'Проверьте ответ и пояснение: '.$step['label']]);
            }
            if ($step['type'] === 'inspection' && in_array($value, ['flag', 'fail', 'na'], true) && trim((string) $note) === '') {
                throw ValidationException::withMessages(['checklist_answers' => 'Укажите пояснение: '.$step['label']]);
            }
            $result[$step['key']] = ['value' => $value, 'note' => $note];
        }

        return $result;
    }

    private function requireReason(?string $reason): void
    {
        if (trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите причину.']);
        }
    }

    private function event(WorkOrder $work, ?User $actor, string $event, ?string $from, string $to, ?string $reason = null, array $data = []): void
    {
        WorkOrderEvent::create(['work_order_id' => $work->id, 'actor_id' => $actor?->id, 'event' => $event, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'data' => $data ?: null, 'created_at' => now()]);
        Audit::record('work.'.$event, $work, ['from' => $from, 'to' => $to]);
    }

    private function workFromOccurrence(MaintenancePlan $plan, MaintenanceOccurrence $occurrence, int $attempt): WorkOrder
    {
        $work = WorkOrder::create(['asset_id' => $plan->asset_id, 'software_installation_id' => $plan->software_installation_id, 'maintenance_occurrence_id' => $occurrence->id, 'attempt' => $attempt, 'title' => $plan->name, 'kind' => 'preventive', 'priority' => 'normal', 'due_on' => $occurrence->due_on->toDateString(), 'assignee_id' => $plan->assignee_id, 'created_by' => $plan->created_by, 'checklist_schema' => $plan->checklist_schema, 'requires_other_acceptor' => $plan->requires_other_acceptor]);
        $this->event($work, null, 'plan.generated', null, 'open');
        $this->notify($work->assignee_id, 'assigned:'.$work->uuid, 'Назначено ТО: '.$work->title, 'maintenance.work.show', $work->uuid);

        return $work;
    }

    private function notify(?int $userId, string $key, string $title, string $route, string $uuid): void
    {
        if ($userId) {
            DB::table('operation_notifications')->insertOrIgnore(['user_id' => $userId, 'event_key' => $key, 'title' => mb_substr($title, 0, 200), 'route_name' => $route, 'subject_uuid' => $uuid, 'created_at' => now()]);
        }
    }

    private function remind(MaintenancePlan $plan, MaintenanceOccurrence $occurrence): void
    {
        $today = CarbonImmutable::now(MaintenanceSchedule::TIMEZONE)->startOfDay();
        $stage = MaintenanceSchedule::date($occurrence->due_on->toDateString())->lessThan($today) ? 'overdue' : 'upcoming';
        $this->notify($plan->assignee_id ?? $plan->created_by, 'occurrence:'.$occurrence->uuid.':'.$stage, ($stage === 'overdue' ? 'Просрочено ТО: ' : 'Приближается ТО: ').$plan->name, 'maintenance.plans.show', $plan->uuid);
    }

    private function descendantLocationIds(array $uuids): array
    {
        $all = Location::whereNull('archived_at')->get(['id', 'uuid', 'parent_id']);
        $ids = $all->whereIn('uuid', $uuids)->pluck('id')->all();
        do {
            $before = count($ids);
            foreach ($all as $location) {
                if (in_array($location->parent_id, $ids, true) && ! in_array($location->id, $ids, true)) {
                    $ids[] = $location->id;
                }
            }
        } while (count($ids) > $before);

        return $ids;
    }

    private function observationData(InventoryObservation $o): array
    {
        return ['uuid' => $o->uuid, 'asset_version' => $o->asset_version, 'location_id' => $o->location_id, 'location' => $o->location_name, 'actor_id' => $o->actor_id, 'at' => $o->observed_at->toIso8601String(), 'method' => $o->method, 'condition' => $o->condition, 'note' => $o->note];
    }
}
