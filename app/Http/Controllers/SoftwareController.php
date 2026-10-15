<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\SoftwareInstallation;
use App\Models\SoftwareProduct;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\SoftwareService;
use App\Support\Access;
use Illuminate\Http\Request;

class SoftwareController extends Controller
{
    public function __construct(private SoftwareService $software) {}

    public function index(Request $request)
    {
        Access::check($request->user(), 'read');
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        $products = SoftwareProduct::with('owner')->withCount(['installations', 'licenses'])
            ->whereNull('archived_at')->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('name', 'like', '%'.$q.'%')->orWhere('vendor', 'like', '%'.$q.'%');
            }))->orderBy('name')->paginate(30)->withQueryString();
        if ($this->json($request)) {
            return response()->json($products);
        }

        return view('software.index', ['products' => $products, 'q' => $q, 'owners' => $this->owners()]);
    }

    public function show(Request $request, SoftwareProduct $software)
    {
        Access::check($request->user(), 'read');
        $software->load('owner', 'releases', 'installations.asset', 'installations.release', 'licenses');
        if ($this->json($request)) {
            // This endpoint is internal. Repository is never exposed on guest asset pages.
            return response()->json($software->toArray() + ['repository_url' => $software->repository_url]);
        }
        $installationIds = $software->installations->pluck('id');
        $work = $installationIds->isEmpty() ? collect() : WorkOrder::whereIn('software_installation_id', $installationIds)->latest('id')->limit(30)->get();

        return view('software.show', ['software' => $software, 'owners' => $this->owners(), 'assets' => $this->assets(), 'work' => $work]);
    }

    public function store(Request $request)
    {
        $product = $this->software->create($request->user(), $request->all());

        return $this->json($request) ? response()->json($product, 201) : redirect()->route('software.show', $product)->with('status', 'Программа добавлена. Теперь можно указать версии и установки.');
    }

    public function update(Request $request, SoftwareProduct $software)
    {
        $product = $this->software->update($request->user(), $software, $request->all());

        return $this->json($request) ? response()->json($product) : redirect()->route('software.show', $product)->with('status', 'Карточка программы сохранена.');
    }

    public function releaseStore(Request $request, SoftwareProduct $software)
    {
        $release = $this->software->addRelease($request->user(), $software, $request->all());

        return $this->json($request) ? response()->json($release, 201) : redirect()->route('software.show', $software)->with('status', 'Версия добавлена в каталог. Фактические установки изменяются отдельно.');
    }

    public function installationStore(Request $request, SoftwareProduct $software)
    {
        $installation = $this->software->saveInstallation($request->user(), $software, $request->all());

        return $this->json($request) ? response()->json($installation, 201) : redirect()->route('software.show', $software)->with('status', 'Установка добавлена. Автоматическое выполнение команд не производится.');
    }

    public function installationUpdate(Request $request, SoftwareProduct $software, SoftwareInstallation $installation)
    {
        $installation = $this->software->saveInstallation($request->user(), $software, $request->all(), $installation);

        return $this->json($request) ? response()->json($installation) : redirect()->route('software.show', $software)->with('status', 'Сведения об установке сохранены.');
    }

    private function json(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private function owners()
    {
        return User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    private function assets()
    {
        return Asset::whereNull('archived_at')->where('status', '!=', 'written_off')->orderBy('name')->get(['id', 'uuid', 'code', 'name']);
    }
}
