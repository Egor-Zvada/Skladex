<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Kit;
use App\Models\KitMember;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Version;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitController extends Controller
{
    public function index(Request $r)
    {
        $kits = Kit::whereNull('archived_at')->with('assets')->orderBy('name')->paginate(30);
        $assets = Asset::whereNull('archived_at')->orderBy('name')->get();

        return $r->is('api/*') ? response()->json(['data' => $kits->items()]) : view('kits.index', compact('kits', 'assets'));
    }

    public function store(Request $r)
    {
        Access::check($r->user(), 'write');
        $d = $r->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000', 'asset_ids' => 'nullable|array|max:100', 'asset_ids.*' => 'integer|distinct|exists:assets,id']);
        $kit = DB::transaction(function () use ($d) {
            $kit = Kit::create(['name' => $d['name'], 'code' => Codes::next('SKX-K'), 'description' => $d['description'] ?? null]);
            foreach ($d['asset_ids'] ?? [] as $id) {
                abort_if(Asset::findOrFail($id)->archived_at, 422);
                KitMember::create(['kit_id' => $kit->id, 'asset_id' => $id]);
            }Audit::record('kit.created', $kit, ['members' => count($d['asset_ids'] ?? [])]);

            return $kit;
        });

        return $r->is('api/*') ? response()->json(['data' => $kit], 201) : back()->with('success', 'Комплект создан.');
    }

    public function member(Request $r, Kit $kit)
    {
        Access::check($r->user(), 'write');
        $d = $r->validate(['expected_version' => 'required|integer', 'asset_id' => 'required|integer|exists:assets,id', 'remove' => 'nullable|boolean']);
        DB::transaction(function () use ($kit, $d) {
            $k = Kit::lockForUpdate()->findOrFail($kit->id);
            Version::check($k, $d['expected_version']);
            abort_if($k->archived_at, 409);
            if ($d['remove'] ?? false) {
                KitMember::where('kit_id', $k->id)->where('asset_id', $d['asset_id'])->delete();
            } else {
                abort_if(Asset::findOrFail($d['asset_id'])->archived_at, 422);
                KitMember::firstOrCreate(['kit_id' => $k->id, 'asset_id' => $d['asset_id']]);
            }$k->increment('version');
            Audit::record('kit.members.changed', $k, ['asset_id' => $d['asset_id'], 'remove' => $d['remove'] ?? false]);
        });

        return $r->is('api/*') ? response()->json(['ok' => true]) : back()->with('success', 'Состав комплекта сохранён.');
    }
}
