<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Services\BuildingService;
use App\Support\Access;
use Illuminate\Http\Request;

class BuildingController extends Controller
{
    public function index(Request $request)
    {
        $items = Building::whereNull('archived_at')->withCount(['locations' => fn ($q) => $q->whereNull('archived_at')])->orderBy('name')->paginate(50);

        return $request->is('api/*')
            ? response()->json(['data' => $items->items(), 'meta' => ['total' => $items->total()]])
            : view('buildings.index', compact('items'));
    }

    public function show(Request $request, Building $building)
    {
        abort_if($building->archived_at, 404);
        $locations = $building->locations()->whereNull('archived_at')->withCount(['assets' => fn ($q) => $q->whereNull('archived_at')])->orderBy('name')->paginate(50);

        return $request->is('api/*')
            ? response()->json(['data' => $building, 'locations' => $locations->items(), 'meta' => ['total' => $locations->total()]])
            : view('buildings.show', compact('building', 'locations'));
    }

    public function store(Request $request, BuildingService $service)
    {
        Access::check($request->user(), 'write');
        $building = $service->create($request->user(), $this->validateFields($request));

        return $request->is('api/*')
            ? response()->json(['data' => $building], 201)
            : redirect()->route('buildings.show', $building)->with('success', 'Здание создано.');
    }

    public function update(Request $request, Building $building, BuildingService $service)
    {
        Access::check($request->user(), 'write');
        $data = $this->validateFields($request, true);
        $item = $service->update($request->user(), $building, $data, (int) $data['expected_version']);

        return $request->is('api/*')
            ? response()->json(['data' => $item])
            : back()->with('success', 'Здание сохранено.');
    }

    public function archive(Request $request, Building $building, BuildingService $service)
    {
        Access::check($request->user(), 'write');
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        $service->archive($request->user(), $building, (int) $data['expected_version']);

        return $request->is('api/*')
            ? response()->json(['ok' => true])
            : redirect()->route('buildings.index')->with('success', 'Здание архивировано.');
    }

    private function validateFields(Request $request, bool $updating = false): array
    {
        return $request->validate(['name' => 'required|string|max:255', 'address' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:5000'] + ($updating ? ['expected_version' => 'required|integer|min:1'] : []));
    }
}
