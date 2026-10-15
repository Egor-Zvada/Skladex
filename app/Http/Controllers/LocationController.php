<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function index(Request $r)
    {
        $items = Location::whereNull('archived_at')->withCount(['assets' => fn ($q) => $q->whereNull('archived_at')])->orderBy('name')->paginate(50);

        return $r->is('api/*') ? response()->json(['data' => $items->items(), 'meta' => ['total' => $items->total()]]) : view('locations.index', compact('items'));
    }

    public function store(Request $r)
    {
        Access::check($r->user(), 'write');
        $d = $r->validate(['name' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'is_public' => 'nullable|boolean']);
        $item = DB::transaction(function () use ($d) {
            $item = Location::create(['name' => $d['name'], 'code' => Codes::next('SKX-R'), 'notes' => $d['notes'] ?? null, 'is_public' => $d['is_public'] ?? true]);
            Audit::record('location.created', $item);

            return $item;
        });

        return $r->is('api/*') ? response()->json(['data' => $item], 201) : redirect()->route('locations.show', $item)->with('success', 'Кабинет создан.');
    }

    public function show(Request $r, Location $location)
    {
        abort_if($location->archived_at, 404);
        $assets = $location->assets()->with('category')->whereNull('archived_at')->orderBy('name')->paginate(50);

        return $r->is('api/*') ? response()->json(['data' => $location, 'assets' => $assets->items()]) : view('locations.show', compact('location', 'assets'));
    }

    public function update(Request $r, Location $location)
    {
        Access::check($r->user(), 'write');
        $d = $r->validate(['name' => 'required|string|max:255', 'notes' => 'nullable|string|max:5000', 'is_public' => 'required|boolean', 'expected_version' => 'required|integer']);
        DB::transaction(function () use ($location, $d) {
            $x = Location::lockForUpdate()->findOrFail($location->id);
            Version::check($x, $d['expected_version']);
            $x->fill(['name' => $d['name'], 'notes' => $d['notes'] ?? null, 'is_public' => $d['is_public']]);
            $x->version++;
            $x->save();
            Audit::record('location.updated', $x);
        });

        return $r->is('api/*') ? response()->json(['data' => $location->refresh()]) : back()->with('success', 'Кабинет сохранён.');
    }
}
