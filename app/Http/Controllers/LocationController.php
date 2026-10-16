<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Location;
use App\Services\LocationService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $r)
    {
        $filters = $r->validate(['building_id' => ['nullable', function ($attribute, $value, $fail) {
            if ($value !== 'unassigned' && ((! is_string($value) && ! is_int($value)) || ! ctype_digit((string) $value) || ! Building::whereKey($value)->whereNull('archived_at')->exists())) {
                $fail('Выберите действующее здание или кабинеты без здания.');
            }
        }]]);
        $buildingId = $filters['building_id'] ?? null;
        $items = Location::whereNull('archived_at')->with('building:id,uuid,name')
            ->when($buildingId === 'unassigned', fn ($q) => $q->whereNull('building_id'))
            ->when($buildingId !== null && $buildingId !== 'unassigned', fn ($q) => $q->where('building_id', $buildingId))
            ->withCount(['assets' => fn ($q) => $q->whereNull('archived_at')])->orderBy('name')->paginate(50)->withQueryString();
        $buildings = Building::whereNull('archived_at')->orderBy('name')->get(['id', 'uuid', 'name']);

        return $r->is('api/*') ? response()->json(['data' => $items->items(), 'meta' => ['total' => $items->total()]]) : view('locations.index', compact('items', 'buildings', 'buildingId'));
    }

    public function store(Request $r)
    {
        Access::check($r->user(), 'write');
        $d = $r->validate(['name' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'is_public' => 'nullable|boolean', 'building_id' => ['nullable', 'integer', Rule::exists('buildings', 'id')->whereNull('archived_at')]]);
        $item = app(LocationService::class)->create($r->user(), $d);

        return $r->is('api/*') ? response()->json(['data' => $item], 201) : redirect()->route('locations.show', $item)->with('success', 'Кабинет создан.');
    }

    public function show(Request $r, Location $location)
    {
        abort_if($location->archived_at, 404);
        $location->load('building:id,uuid,name');
        $assets = $location->assets()->with('category')->whereNull('archived_at')->orderBy('name')->paginate(50);
        $buildings = Building::whereNull('archived_at')->orderBy('name')->get(['id', 'uuid', 'name']);

        return $r->is('api/*') ? response()->json(['data' => $location, 'assets' => $assets->items()]) : view('locations.show', compact('location', 'assets', 'buildings'));
    }

    public function update(Request $r, Location $location)
    {
        Access::check($r->user(), 'write');
        $d = $r->validate(['name' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'is_public' => 'required|boolean', 'expected_version' => 'required|integer', 'building_id' => ['nullable', 'integer', Rule::exists('buildings', 'id')->whereNull('archived_at')]]);
        app(LocationService::class)->update($r->user(), $location, $d);

        return $r->is('api/*') ? response()->json(['data' => $location->refresh()]) : back()->with('success', 'Кабинет сохранён.');
    }
}
