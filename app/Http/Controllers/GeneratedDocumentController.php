<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\GeneratedDocumentRecord;
use App\Services\GeneratedDocumentService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GeneratedDocumentController extends Controller
{
    public function index(Request $request, GeneratedDocumentService $service)
    {
        Access::check($request->user(), 'read');
        $data = $request->validate(['q' => 'nullable|string|max:150', 'status' => 'nullable|in:draft,final', 'template_key' => 'nullable|in:'.implode(',', array_keys($service->templates()))]);
        $items = GeneratedDocumentRecord::query()->withCount('items')
            ->when($data['q'] ?? null, fn ($query, $term) => $query->where('title', 'like', '%'.$term.'%'))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['template_key'] ?? null, fn ($query, $template) => $query->where('template_key', $template))
            ->orderByDesc('id')->paginate(25)->withQueryString();
        if ($request->is('api/*')) {
            return response()->json(['data' => $items->map(fn ($item) => $this->payload($item)), 'meta' => ['page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()], 'templates' => $service->templates()])->header('Cache-Control', 'private, no-store');
        }

        return view('document-records.index', ['items' => $items, 'templates' => $service->templates()]);
    }

    public function create(Request $request, GeneratedDocumentService $service)
    {
        Access::check($request->user(), 'write');
        $data = $request->validate(['asset_ids' => 'sometimes|array|max:100', 'asset_ids.*' => 'required|string|uuid|distinct:ignore_case', 'q' => 'nullable|string|max:150']);
        $oldSelection = old('asset_ids', $data['asset_ids'] ?? []);
        $selected = is_array($oldSelection) ? array_map('strtolower', array_slice(array_values(array_filter($oldSelection, fn ($id) => is_string($id) && Str::isUuid($id))), 0, 100)) : [];
        $query = $data['q'] ?? '';
        $base = Asset::with('location.building')->where(function ($builder) {
            $builder->whereNull('archived_at')->orWhere('status', 'written_off');
        });
        $chosen = (clone $base)->whereIn('uuid', $selected)->orderBy('name')->get();
        $rows = (clone $base)->whereNotIn('uuid', $selected)->when($query !== '', function ($builder) use ($query) {
            $builder->where(function ($builder) use ($query) {
                $builder->where('name', 'like', '%'.$query.'%')->orWhere('code', 'like', '%'.$query.'%')->orWhere('inventory_no', 'like', '%'.$query.'%');
            });
        })->orderBy('name')->orderBy('id')->limit(501 - $chosen->count())->get();
        $truncated = $rows->count() + $chosen->count() > 500;
        $assets = $chosen->concat($rows)->take(500);

        return view('document-records.create', ['assets' => $assets, 'selected' => $selected, 'templates' => $service->templates(), 'truncated' => $truncated, 'query' => $query]);
    }

    public function store(Request $request, GeneratedDocumentService $service)
    {
        $record = $service->create($request->user(), $request->all());

        return $request->is('api/*')
            ? response()->json(['data' => $this->payload($record)], 201)->header('Cache-Control', 'private, no-store')
            : redirect()->route('document-records.show', $record)->with('success', 'Черновик создан. Проверьте текст и перечень перед печатью.');
    }

    public function show(Request $request, GeneratedDocumentRecord $record)
    {
        Access::check($request->user(), 'read');
        $record->load('items');
        if ($request->is('api/*')) {
            return response()->json(['data' => $this->payload($record)])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('document-records.show', compact('record'))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, GeneratedDocumentRecord $record, GeneratedDocumentService $service)
    {
        $record = $service->update($request->user(), $record, $request->all());

        return $request->is('api/*')
            ? response()->json(['data' => $this->payload($record)])->header('Cache-Control', 'private, no-store')
            : redirect()->route('document-records.show', $record)->with('success', 'Черновик сохранён.');
    }

    public function finalize(Request $request, GeneratedDocumentRecord $record, GeneratedDocumentService $service)
    {
        $data = $request->validate(['expected_version' => 'required|integer|min:1']);
        $record = $service->finalize($request->user(), $record, $data['expected_version']);

        return $request->is('api/*')
            ? response()->json(['data' => $this->payload($record)])->header('Cache-Control', 'private, no-store')
            : redirect()->route('document-records.show', $record)->with('success', 'Редакция зафиксирована. Это не электронная подпись и не изменение статуса оборудования.');
    }

    public function pdf(Request $request, GeneratedDocumentRecord $record, GeneratedDocumentService $service)
    {
        return response($service->pdf($request->user(), $record), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="skladex-'.$record->number.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function payload(GeneratedDocumentRecord $record): array
    {
        $data = $record->only(['uuid', 'template_key', 'template_version', 'status', 'title', 'version']);
        $data += ['number' => $record->number, 'document_date' => $record->document_date->format('Y-m-d'), 'created_at' => $record->created_at->toIso8601String(), 'finalized_at' => $record->finalized_at?->toIso8601String()];
        if ($record->relationLoaded('items')) {
            $data += $record->only(['organization', 'author_name', 'recipient_name', 'body', 'template_snapshot']);
            $data['items'] = $record->items->map(fn ($item) => ['position' => $item->position, 'snapshot' => $item->snapshot])->all();
        } else {
            $data['items_count'] = $record->items_count;
        }

        return $data;
    }
}
