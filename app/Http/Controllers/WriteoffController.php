<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetWriteoff;
use App\Models\Location;
use App\Services\WriteoffService;
use Illuminate\Http\Request;

class WriteoffController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:150']);
        $query = Asset::with('category', 'location.building')->where('status', 'written_off')
            ->when(! empty($filters['q']), fn ($q) => $q->where(function ($q) use ($filters) {
                $value = '%'.$filters['q'].'%';
                $q->where('name', 'like', $value)->orWhere('code', 'like', $value)->orWhere('inventory_no', 'like', $value);
            }))->orderBy('name');
        $assets = $query->paginate(30)->withQueryString();
        if ($request->is('api/*')) {
            return response()->json(['data' => $assets->getCollection()->map(fn ($asset) => $this->data($asset)), 'meta' => ['page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'total' => $assets->total()]]);
        }

        return view('writeoffs.index', compact('assets'));
    }

    public function show(Request $request, Asset $asset, WriteoffService $service)
    {
        abort_unless($asset->status === 'written_off', 404);
        $asset->load('category', 'location.building');
        $history = AssetWriteoff::with('writtenOffBy')->where('asset_id', $asset->id)->orderByDesc('id')->get();
        $blockers = $service->purgeBlockers($asset);
        if ($request->is('api/*')) {
            return response()->json(['data' => $this->data($asset) + ['purge_blockers' => $blockers, 'history' => $history->map(fn ($row) => ['uuid' => $row->uuid, 'reason' => $row->reason, 'written_off_at' => $row->written_off_at?->toIso8601String(), 'restored_at' => $row->restored_at?->toIso8601String()])]]);
        }

        return view('writeoffs.show', ['asset' => $asset, 'history' => $history, 'blockers' => $blockers, 'locations' => Location::with('building')->whereNull('archived_at')->orderBy('name')->get()]);
    }

    public function store(Request $request, Asset $asset, WriteoffService $service)
    {
        $asset = $service->writeOff($request->user(), $asset, $request->all());

        return $request->is('api/*') ? response()->json(['data' => $this->data($asset)]) : redirect()->route('writeoffs.show', $asset)->with('success', 'Предмет перенесён в списание.');
    }

    public function restore(Request $request, Asset $asset, WriteoffService $service)
    {
        $asset = $service->restore($request->user(), $asset, $request->all());

        return $request->is('api/*') ? response()->json(['data' => $this->data($asset)]) : redirect()->route('assets.show', $asset)->with('success', 'Предмет возвращён в основной реестр. Проверьте необходимость обслуживания.');
    }

    public function purge(Request $request, string $asset, WriteoffService $service)
    {
        // Defer lookup until after the idempotency middleware so a successful
        // deletion can be replayed even though implicit model binding is gone.
        $asset = Asset::where('uuid', $asset)->firstOrFail();
        $service->purge($request->user(), $asset, $request->all());

        return $request->is('api/*') ? response()->json(['ok' => true]) : redirect()->route('writeoffs.index')->with('success', 'Предмет полностью удалён из реестра. Код и QR зарезервированы, история действий сохранена.');
    }

    private function data(Asset $asset): array
    {
        return ['id' => $asset->id, 'uuid' => $asset->uuid, 'code' => $asset->code, 'name' => $asset->name, 'category_id' => $asset->category_id, 'location_id' => $asset->location_id, 'inventory_no' => $asset->inventory_no, 'serial_no' => $asset->serial_no, 'status' => $asset->status, 'condition' => $asset->condition, 'version' => $asset->version];
    }
}
