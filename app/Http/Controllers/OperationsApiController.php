<?php

namespace App\Http\Controllers;

use App\Models\InventoryCampaign;
use App\Models\MaintenanceOccurrence;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRequest;
use App\Models\WorkOrder;
use App\Services\OperationsService;
use App\Support\Access;
use Illuminate\Http\Request;

class OperationsApiController extends Controller
{
    public function __construct(private OperationsService $operations) {}

    public function workIndex(Request $r)
    {
        Access::check($r->user(), 'read');

        return WorkOrder::orderByDesc('id')->paginate(50);
    }

    public function workShow(Request $r, WorkOrder $work)
    {
        Access::check($r->user(), 'read');

        return $work->load('events');
    }

    public function workStore(Request $r)
    {
        return response()->json($this->operations->createWork($r->user(), $r->all()), 201);
    }

    public function completedStore(Request $r)
    {
        return response()->json($this->operations->recordCompletedWork($r->user(), $r->all()), 201);
    }

    public function workUpdate(Request $r, WorkOrder $work)
    {
        return $this->operations->updateWork($r->user(), $work, $r->all());
    }

    public function workTransition(Request $r, WorkOrder $work)
    {
        return $this->operations->transitionWork($r->user(), $work, $r->all());
    }

    public function workComplete(Request $r, WorkOrder $work)
    {
        return $this->operations->completeWork($r->user(), $work, $r->all());
    }

    public function workAccept(Request $r, WorkOrder $work)
    {
        return $this->operations->acceptWork($r->user(), $work, $r->all());
    }

    public function workCorrect(Request $r, WorkOrder $work)
    {
        return $this->operations->correctClosedWork($r->user(), $work, $r->all());
    }

    public function requestIndex(Request $r)
    {
        Access::check($r->user(), 'read');

        return MaintenanceRequest::latest('id')->paginate(50);
    }

    public function requestStore(Request $r)
    {
        return response()->json($this->operations->createRequest($r->user(), $r->all()), 201);
    }

    public function requestTriage(Request $r, MaintenanceRequest $maintenanceRequest)
    {
        return $this->operations->triageRequest($r->user(), $maintenanceRequest, $r->all());
    }

    public function planIndex(Request $r)
    {
        Access::check($r->user(), 'read');

        return MaintenancePlan::latest('id')->paginate(50);
    }

    public function planShow(Request $r, MaintenancePlan $plan)
    {
        Access::check($r->user(), 'read');

        return $plan->load('occurrences.workOrders');
    }

    public function planStore(Request $r)
    {
        return response()->json($this->operations->createPlan($r->user(), $r->all()), 201);
    }

    public function planUpdate(Request $r, MaintenancePlan $plan)
    {
        return $this->operations->changePlan($r->user(), $plan, $r->all());
    }

    public function planGenerate(Request $r, MaintenancePlan $plan)
    {
        $d = $r->validate(['expected_version' => 'required|integer|min:1']);
        $w = $this->operations->generatePlan($r->user(), $plan, $d['expected_version']);

        return response()->json(['work' => $w], $w ? 201 : 200);
    }

    public function occurrenceDecide(Request $r, MaintenanceOccurrence $occurrence)
    {
        return response()->json(['work' => $this->operations->decideOccurrence($r->user(), $occurrence, $r->all())]);
    }

    public function campaignIndex(Request $r)
    {
        Access::check($r->user(), 'read');

        return InventoryCampaign::latest('id')->paginate(50);
    }

    public function campaignShow(Request $r, InventoryCampaign $campaign)
    {
        Access::check($r->user(), 'read');

        return ['campaign' => $campaign, 'report' => $campaign->status === 'closed' ? $campaign->reports()->latest('revision')->firstOrFail()->payload : $this->operations->report($campaign)];
    }

    public function campaignStore(Request $r)
    {
        return response()->json($this->operations->createCampaign($r->user(), $r->all()), 201);
    }

    public function campaignStart(Request $r, InventoryCampaign $campaign)
    {
        $d = $r->validate(['expected_version' => 'required|integer|min:1']);

        return $this->operations->startCampaign($r->user(), $campaign, $d['expected_version']);
    }

    public function observationStore(Request $r, InventoryCampaign $campaign)
    {
        return response()->json($this->operations->observe($r->user(), $campaign, $r->all()), 201);
    }

    public function campaignReconcile(Request $r, InventoryCampaign $campaign)
    {
        $d = $r->validate(['expected_version' => 'required|integer|min:1']);

        return $this->operations->beginReconciliation($r->user(), $campaign, $d['expected_version']);
    }

    public function resolutionStore(Request $r, InventoryCampaign $campaign)
    {
        return response()->json($this->operations->resolve($r->user(), $campaign, $r->all()), 201);
    }

    public function campaignClose(Request $r, InventoryCampaign $campaign)
    {
        $d = $r->validate(['expected_version' => 'required|integer|min:1']);

        return $this->operations->closeCampaign($r->user(), $campaign, $d['expected_version']);
    }

    public function campaignCancel(Request $r, InventoryCampaign $campaign)
    {
        return $this->operations->cancelCampaign($r->user(), $campaign, $r->all());
    }

    public function campaignReport(Request $r, InventoryCampaign $campaign)
    {
        Access::check($r->user(), 'read');
        abort_unless($campaign->status === 'closed', 409, 'Кампания ещё не закрыта.');

        return $campaign->reports()->latest('revision')->firstOrFail()->payload;
    }
}
