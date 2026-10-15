<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\InventoryCampaign;
use App\Models\Location;
use App\Models\MaintenanceOccurrence;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRequest;
use App\Models\SoftwareInstallation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\OperationsService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function __construct(private OperationsService $operations) {}

    private function choices(): array
    {
        return ['assets' => Asset::whereNull('archived_at')->orderBy('name')->get(['id', 'uuid', 'code', 'name']), 'locations' => Location::whereNull('archived_at')->orderBy('name')->get(['id', 'uuid', 'code', 'name']), 'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']), 'installations' => SoftwareInstallation::with('product')->whereNull('archived_at')->orderBy('id')->get(), 'categories' => Category::orderBy('name')->get()];
    }

    public function index(Request $request)
    {
        Access::check($request->user(), 'read');
        $request->validate(['q' => 'nullable|string|max:200', 'status' => 'nullable|string', 'mine' => 'nullable|boolean', 'journal' => 'nullable|boolean']);
        $query = WorkOrder::with(['asset', 'softwareInstallation', 'assignee'])->latest('id');
        if (array_key_exists($request->query('status'), OperationsService::WORK_STATUSES)) {
            $query->where('status', $request->query('status'));
        }
        if ($request->boolean('mine')) {
            $query->where('assignee_id', $request->user()->id);
        }
        if ($request->boolean('journal')) {
            $query->whereIn('status', ['completed', 'closed']);
        }
        if ($request->filled('q')) {
            $q = mb_substr($request->string('q')->toString(), 0, 200);
            $query->where('title', 'like', '%'.$q.'%');
        }

        return view('operations.maintenance.index', $this->choices() + ['works' => $query->paginate(30)->withQueryString(), 'notifications' => DB::table('operation_notifications')->where('user_id', $request->user()->id)->latest('id')->limit(10)->get()]);
    }

    public function storeWork(Request $request)
    {
        $data = $this->withChecklist($request);
        $work = $request->boolean('record_completed') ? $this->operations->recordCompletedWork($request->user(), $data) : $this->operations->createWork($request->user(), $data);

        return redirect()->route('maintenance.work.show', $work)->with('status', 'Работа сохранена.');
    }

    public function showWork(Request $request, WorkOrder $work)
    {
        Access::check($request->user(), 'read');
        $work->load(['asset', 'softwareInstallation', 'assignee', 'events']);

        return view('operations.maintenance.work', $this->choices() + ['work' => $work]);
    }

    public function transitionWork(Request $request, WorkOrder $work)
    {
        $this->operations->transitionWork($request->user(), $work, $request->all());

        return back()->with('status', 'Состояние работы изменено.');
    }

    public function updateWork(Request $request, WorkOrder $work)
    {
        $this->operations->updateWork($request->user(), $work, $request->all());

        return back()->with('status', 'Назначение сохранено.');
    }

    public function completeWork(Request $request, WorkOrder $work)
    {
        $data = $request->all();
        if ($request->filled('material_name')) {
            $data['materials'] = [['name' => $request->input('material_name'), 'quantity' => $request->input('material_quantity'), 'unit_cost' => $request->input('material_cost')]];
        }
        $this->operations->completeWork($request->user(), $work, $data);

        return back()->with('status', 'Результат сохранён. Требуется принятие.');
    }

    public function acceptWork(Request $request, WorkOrder $work)
    {
        $this->operations->acceptWork($request->user(), $work, $request->all());

        return back()->with('status', 'Результат принят.');
    }

    public function correctWork(Request $request, WorkOrder $work)
    {
        $this->operations->correctClosedWork($request->user(), $work, $request->all());

        return back()->with('status', 'Коррекция сохранена отдельным событием; даты и зачёт ТО не изменены.');
    }

    public function requests(Request $request)
    {
        Access::check($request->user(), 'read');

        return view('operations.maintenance.requests', $this->choices() + ['requests' => MaintenanceRequest::with(['asset', 'workOrder'])->latest('id')->paginate(30)]);
    }

    public function storeRequest(Request $request)
    {
        $this->operations->createRequest($request->user(), $request->all());

        return back()->with('status', 'Заявка зарегистрирована.');
    }

    public function triageRequest(Request $request, MaintenanceRequest $maintenanceRequest)
    {
        $row = $this->operations->triageRequest($request->user(), $maintenanceRequest, $request->all());

        return $row->work_order_id ? redirect()->route('maintenance.work.show', $row->workOrder)->with('status', 'Решение сохранено.') : back()->with('status', 'Решение сохранено.');
    }

    public function plans(Request $request)
    {
        Access::check($request->user(), 'read');

        return view('operations.maintenance.plans', $this->choices() + ['plans' => MaintenancePlan::with('asset')->latest('id')->paginate(30)]);
    }

    public function storePlan(Request $request)
    {
        $plan = $this->operations->createPlan($request->user(), $this->withChecklist($request));

        return redirect()->route('maintenance.plans.show', $plan)->with('status', 'Регламент сохранён.');
    }

    public function showPlan(Request $request, MaintenancePlan $plan)
    {
        Access::check($request->user(), 'read');
        $plan->load(['asset', 'occurrences.workOrders']);

        return view('operations.maintenance.plan', $this->choices() + ['plan' => $plan]);
    }

    public function changePlan(Request $request, MaintenancePlan $plan)
    {
        $this->operations->changePlan($request->user(), $plan, $this->withChecklist($request));

        return back()->with('status', 'Параметры регламента сохранены.');
    }

    public function generatePlan(Request $request, MaintenancePlan $plan)
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        $work = $this->operations->generatePlan($request->user(), $plan, $data['expected_version']);

        return $work ? redirect()->route('maintenance.work.show', $work) : back()->with('status', 'Новая работа не требуется: проверьте срок и текущую обязанность.');
    }

    public function decideOccurrence(Request $request, MaintenanceOccurrence $occurrence)
    {
        $work = $this->operations->decideOccurrence($request->user(), $occurrence, $request->all());

        return $work ? redirect()->route('maintenance.work.show', $work) : back()->with('status', 'Пропуск зарегистрирован отдельно от выполнения.');
    }

    public function inventoryIndex(Request $request)
    {
        Access::check($request->user(), 'read');

        return view('operations.inventory.index', $this->choices() + ['campaigns' => InventoryCampaign::latest('id')->paginate(30)]);
    }

    public function storeCampaign(Request $request)
    {
        $campaign = $this->operations->createCampaign($request->user(), $request->all());

        return redirect()->route('inventory.show', $campaign)->with('status', 'Черновик кампании сохранён.');
    }

    public function showCampaign(Request $request, InventoryCampaign $campaign)
    {
        Access::check($request->user(), 'read');
        $report = $campaign->status === 'closed' ? $campaign->reports()->latest('revision')->firstOrFail()->payload : $this->operations->report($campaign);

        return view('operations.inventory.campaign', $this->choices() + ['campaign' => $campaign, 'report' => $report]);
    }

    public function startCampaign(Request $request, InventoryCampaign $campaign)
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        $this->operations->startCampaign($request->user(), $campaign, $data['expected_version']);

        return back()->with('status', 'Снимок сохранён. Можно проводить обход.');
    }

    public function observe(Request $request, InventoryCampaign $campaign)
    {
        $observation = $this->operations->observe($request->user(), $campaign, $request->all());
        if ($request->expectsJson()) {
            return response()->json(['saved' => true, 'observation_uuid' => $observation->uuid, 'known' => (bool) $observation->asset_id, 'asset_code' => $observation->asset_code, 'location' => $observation->location_name, 'version' => $campaign->fresh()->version, 'progress' => $this->operations->progress($campaign)]);
        }

        return back()->with('status', $observation->asset_id ? 'Наблюдение сохранено: '.$observation->asset_code : 'Неизвестная метка сохранена для разбора.');
    }

    public function reconcile(Request $request, InventoryCampaign $campaign)
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        $this->operations->beginReconciliation($request->user(), $campaign, $data['expected_version']);

        return back()->with('status', 'Обход остановлен. Разберите расхождения.');
    }

    public function resolve(Request $request, InventoryCampaign $campaign)
    {
        $this->operations->resolve($request->user(), $campaign, $request->all());

        return back()->with('status', 'Решение сохранено с историей.');
    }

    public function closeCampaign(Request $request, InventoryCampaign $campaign)
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        $this->operations->closeCampaign($request->user(), $campaign, $data['expected_version']);

        return back()->with('status', 'Кампания закрыта. Отчёт зафиксирован.');
    }

    public function cancelCampaign(Request $request, InventoryCampaign $campaign)
    {
        $this->operations->cancelCampaign($request->user(), $campaign, $request->all());

        return back()->with('status', 'Кампания отменена, материалы сохранены.');
    }

    public function report(Request $request, InventoryCampaign $campaign)
    {
        Access::check($request->user(), 'read');
        abort_unless($campaign->status === 'closed', 409, 'Итоговый отчёт создаётся при закрытии.');
        $report = $campaign->reports()->latest('revision')->firstOrFail();

        return response()->json($report->payload, 200, ['Content-Disposition' => 'attachment; filename="inventory-'.$campaign->uuid.'.json"']);
    }

    private function withChecklist(Request $request): array
    {
        $data = $request->all();
        if (! empty($data['checklist_text']) && ! isset($data['checklist_schema'])) {
            $lines = preg_split('/\R/u', (string) $data['checklist_text']);
            $data['checklist_schema'] = [];
            foreach ($lines as $index => $line) {
                if (trim($line) !== '') {
                    $data['checklist_schema'][] = ['key' => 'step_'.($index + 1), 'label' => trim($line), 'type' => 'inspection', 'required' => true];
                }
            }
        }

        return $data;
    }
}
