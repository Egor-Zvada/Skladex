<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\Category;
use App\Models\LicenseInstance;
use App\Models\Location;
use App\Models\PhysicalPlacement;
use App\Models\SoftwareProduct;
use App\Services\AssetService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $r)
    {
        $q = Asset::with('category', 'location')->whereNull('archived_at')->when($r->filled('q'), fn ($q) => $q->where(function ($q) use ($r) {
            $v = '%'.mb_substr($r->string('q'), 0, 150).'%';
            $q->where('name', 'like', $v)->orWhere('code', 'like', $v)->orWhere('inventory_no', 'like', $v)->orWhere('serial_no', 'like', $v);
        }))
            ->when($r->filled('category'), fn ($q) => $q->where('category_id', $r->integer('category')))->when($r->filled('location'), fn ($q) => $q->where('location_id', $r->integer('location')))->orderBy('name');
        $assets = $q->paginate(30)->withQueryString();
        if ($r->is('api/*')) {
            return response()->json(['data' => $assets->getCollection()->map(fn ($a) => $this->data($a)), 'meta' => ['page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'total' => $assets->total()]]);
        }

        return view('assets.index', ['assets' => $assets, 'categories' => Category::orderBy('name')->get(), 'locations' => Location::whereNull('archived_at')->orderBy('name')->get()]);
    }

    public function create(Request $r)
    {
        Access::check($r->user(), 'write');

        return $this->form(new Asset);
    }

    public function edit(Request $r, Asset $asset)
    {
        Access::check($r->user(), 'write');
        abort_if($asset->archived_at, 404);

        return $this->form($asset);
    }

    private function form(Asset $asset)
    {
        return view('assets.form', ['asset' => $asset, 'categories' => Category::orderBy('name')->get(), 'locations' => Location::whereNull('archived_at')->orderBy('name')->get()]);
    }

    public function store(Request $r, AssetService $service)
    {
        return $this->saved($r, $service->save($r->user(), $this->validateAsset($r, false)));
    }

    public function update(Request $r, Asset $asset, AssetService $service)
    {
        return $this->saved($r, $service->save($r->user(), $this->validateAsset($r, true), $asset));
    }

    private function saved(Request $r, Asset $asset)
    {
        return $r->is('api/*') ? response()->json(['data' => $this->data($asset)], 201) : redirect()->route('assets.show', $asset)->with('success', 'Карточка сохранена.');
    }

    public function show(Request $r, Asset $asset)
    {
        abort_if($asset->archived_at, 404);
        $asset->load('category', 'location', 'components.childAsset', 'physicalPlacement.parentAsset', 'containedPlacements.asset', 'activeLicenseAssignments.licenseInstance.product', 'softwareInstallations.product');
        if ($r->is('api/*')) {
            return response()->json(['data' => $this->detail($asset)]);
        }

        return view('assets.show', ['asset' => $asset, 'locations' => Location::whereNull('archived_at')->orderBy('name')->get(), 'available' => Asset::whereNull('archived_at')->where('id', '!=', $asset->id)->orderBy('name')->get(), 'racks' => Asset::whereHas('category', fn ($q) => $q->where('kind', 'rack'))->whereNull('archived_at')->get(), 'licenses' => LicenseInstance::with('product')->whereNull('archived_at')->get(), 'products' => SoftwareProduct::whereNull('archived_at')->orderBy('name')->get(), 'contents' => $this->contents($asset)]);
    }

    public function move(Request $r, Asset $asset, AssetService $service)
    {
        $d = $r->validate(['location_id' => ['present', 'nullable', Rule::exists('locations', 'id')->whereNull('archived_at')], 'expected_contents' => 'nullable|array', 'expected_contents.*' => 'required|integer|min:1', 'status' => 'nullable|in:stock,in_use,repair,written_off', 'reason' => 'required|string|max:2000', 'include_children' => 'nullable|boolean', 'expected_version' => 'required|integer|min:1']);
        $asset = $service->move($r->user(), $asset, $d);

        return $r->is('api/*') ? response()->json(['data' => $this->data($asset)]) : back()->with('success', 'Перемещение сохранено.');
    }

    public function component(Request $r, Asset $asset, AssetService $service)
    {
        $d = $r->validate(['expected_version' => 'required|integer|min:1', 'component_type' => 'required|string|max:100', 'description' => 'required_without:child_asset_id|nullable|string|max:500', 'quantity' => 'required|integer|min:1|max:1000', 'serial_no' => 'nullable|string|max:255', 'inventory_no' => 'nullable|string|max:255', 'child_asset_id' => 'nullable|exists:assets,id', 'expected_child_version' => 'required_with:child_asset_id|nullable|integer|min:1']);
        abort_if((! empty($d['serial_no']) || ! empty($d['inventory_no']) || ! empty($d['child_asset_id'])) && (int) $d['quantity'] !== 1, 422, 'Для индивидуального предмета количество должно равняться 1.');
        $row = $service->component($r->user(), $asset, $d);

        return $r->is('api/*') ? response()->json(['data' => $row], 201) : back()->with('success', 'Комплектующее добавлено.');
    }

    public function removeComponent(Request $r, Asset $asset, AssetComponent $component, AssetService $service)
    {
        $r->validate(['expected_version' => 'required|integer']);
        $service->removeComponent($r->user(), $asset, $component, $r->integer('expected_version'));

        return $r->is('api/*') ? response()->json(['ok' => true]) : back()->with('success', 'Комплектующее снято.');
    }

    public function rack(Request $r, Asset $asset, AssetService $service)
    {
        $d = $r->validate(['rack_id' => 'required|exists:assets,id', 'expected_rack_version' => 'required|integer|min:1', 'position' => 'nullable|string|max:100', 'expected_version' => 'required|integer']);
        $service->rack($r->user(), $asset, Asset::findOrFail($d['rack_id']), $d['expected_version'], $d['position'] ?? null, $d['expected_rack_version']);

        return $r->is('api/*') ? response()->json(['data' => $this->data($asset->refresh())]) : back()->with('success', 'Размещение сохранено.');
    }

    public function detach(Request $r, Asset $asset, AssetService $service)
    {
        $r->validate(['expected_version' => 'required|integer']);
        $service->detach($r->user(), $asset, $r->integer('expected_version'));

        return $r->is('api/*') ? response()->json(['ok' => true]) : back()->with('success', 'Предмет снят со шкафа.');
    }

    public function archive(Request $r, Asset $asset, AssetService $service)
    {
        $r->validate(['expected_version' => 'required|integer']);
        $service->archive($r->user(), $asset, $r->integer('expected_version'));

        return $r->is('api/*') ? response()->json(['ok' => true]) : redirect()->route('assets.index')->with('success', 'Карточка перенесена в архив.');
    }

    private function validateAsset(Request $r, bool $update): array
    {
        return $r->validate(['name' => 'required|string|max:255', 'category_id' => 'required|exists:categories,id', 'location_id' => $update ? ['prohibited'] : ['nullable', Rule::exists('locations', 'id')->whereNull('archived_at')], 'responsible_name' => 'nullable|string|max:255', 'inventory_no' => 'nullable|string|max:255', 'serial_no' => 'nullable|string|max:255', 'manufacturer' => 'nullable|string|max:255', 'model' => 'nullable|string|max:255', 'status' => $update ? 'prohibited' : 'nullable|in:stock,in_use,repair,written_off', 'condition' => 'required|in:working,limited,faulty,unknown', 'maintenance_policy' => 'required|in:unspecified,none,reactive_only,scheduled', 'private_notes' => 'nullable|string|max:10000', 'purchased_on' => 'nullable|date', 'warranty_until' => 'nullable|date', 'expected_version' => $update ? 'required|integer|min:1' : 'nullable|integer']);
    }

    private function contents(Asset $asset): Collection
    {
        $ids = [];
        $frontier = [$asset->id];
        while ($frontier) {
            $frontier = PhysicalPlacement::whereIn('parent_asset_id', $frontier)->pluck('asset_id')->all();
            foreach ($frontier as $id) {
                abort_if(in_array($id, $ids), 409, 'Цикл размещения.');
                $ids[] = $id;
            }
        }

        return Asset::whereIn('id', $ids)->with('category')->orderBy('id')->get();
    }

    private function detail(Asset $asset): array
    {
        return $this->data($asset) + [
            'responsible_name' => $asset->responsible_name, 'private_notes' => $asset->private_notes,
            'specifications' => $asset->specifications, 'purchased_on' => $asset->purchased_on?->toDateString(), 'warranty_until' => $asset->warranty_until?->toDateString(),
            'components' => $asset->components->map(fn ($c) => ['id' => $c->id, 'component_type' => $c->component_type, 'description' => $c->description, 'quantity' => $c->quantity, 'serial_no' => $c->serial_no, 'inventory_no' => $c->inventory_no, 'child_asset' => $c->childAsset ? $this->data($c->childAsset) : null]),
            'placement' => $asset->physicalPlacement ? ['kind' => $asset->physicalPlacement->kind, 'position' => $asset->physicalPlacement->position, 'parent_asset' => $this->data($asset->physicalPlacement->parentAsset)] : null,
            'contents' => $this->contents($asset)->map(fn ($a) => $this->data($a)),
            'licenses' => $asset->activeLicenseAssignments->map(fn ($x) => ['assignment_id' => $x->id, 'uuid' => $x->licenseInstance->uuid, 'code' => $x->licenseInstance->code, 'product_name' => $x->licenseInstance->product?->name]),
            'software' => $asset->softwareInstallations->map(fn ($x) => ['id' => $x->id, 'product_uuid' => $x->product->uuid, 'product_name' => $x->product->name, 'version' => $x->version]),
        ];
    }

    private function data(Asset $a): array
    {
        return ['id' => $a->id, 'uuid' => $a->uuid, 'code' => $a->code, 'name' => $a->name, 'category_id' => $a->category_id, 'location_id' => $a->location_id, 'location_uuid' => $a->location?->uuid, 'inventory_no' => $a->inventory_no, 'serial_no' => $a->serial_no, 'manufacturer' => $a->manufacturer, 'model' => $a->model, 'status' => $a->status, 'condition' => $a->condition, 'maintenance_policy' => $a->maintenance_policy, 'version' => $a->version];
    }
}
