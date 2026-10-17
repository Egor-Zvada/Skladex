<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Category;
use App\Models\MaintenancePlan;
use App\Services\MaintenancePresetService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenancePresetController extends Controller
{
    public function index(Request $request, MaintenancePresetService $service)
    {
        Access::check($request->user(), 'read');
        $catalog = $service->catalog();
        $input = $request->validate(['preset_key' => ['nullable', 'string', Rule::in(array_keys($catalog))], 'q' => 'nullable|string|max:200']);
        $selected = $input['preset_key'] ?? 'computer';
        $categoryIds = Category::all()->filter(fn ($category) => $service->recommend((new Asset)->setRelation('category', $category)) === $selected)->pluck('id');
        $assets = Asset::with('category', 'location.building')->whereNull('archived_at')->where('status', '!=', 'written_off')
            ->where(function ($query) use ($categoryIds, $selected) {
                $query->whereIn('category_id', $categoryIds);
                if ($selected === 'generic') {
                    $query->orWhereNull('category_id');
                }
            })
            ->when($input['q'] ?? '', function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%');
                });
            })->orderBy('name')->paginate(60)->withQueryString();
        $existing = MaintenancePlan::whereIn('asset_id', $assets->pluck('id'))->where('status', 'active')->pluck('asset_id')->all();

        if ($request->is('api/*')) {
            return response()->json([
                'catalog' => $catalog, 'selected' => $selected,
                'data' => $assets->getCollection()->map(fn ($asset) => [
                    'uuid' => $asset->uuid, 'code' => $asset->code, 'name' => $asset->name,
                    'version' => $asset->version, 'category_id' => $asset->category_id,
                    'has_active_plan' => in_array($asset->id, $existing, true),
                ]),
                'meta' => ['page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'total' => $assets->total()],
            ])->header('Cache-Control', 'private, no-store');
        }

        return view('operations.maintenance.presets', ['catalog' => $catalog, 'selected' => $selected, 'preset' => $catalog[$selected], 'assets' => $assets, 'existing' => $existing, 'q' => $input['q'] ?? '']);
    }

    public function apply(Request $request, MaintenancePresetService $service)
    {
        $result = $service->apply($request->user(), $request->all());

        if ($request->is('api/*')) {
            return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('maintenance.presets.index', ['preset_key' => $result['preset_key']])
            ->with('status', 'Добавлено регламентов: '.$result['created'].'. Пропущено ранее настроенных: '.$result['skipped'].'.'.($request->filled('first_due_on') ? '' : ' Первый срок не назначен — укажите его в карточке регламента, чтобы включить напоминания.'));
    }
}
