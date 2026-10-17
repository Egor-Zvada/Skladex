<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\LabelProfile;
use App\Models\Location;
use App\Services\LabelService;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LabelController extends Controller
{
    public function index(Request $request, LabelService $service)
    {
        Access::check($request->user(), 'read');
        $data = $request->validate(['target_type' => 'nullable|in:asset,location', 'profile_id' => 'nullable|integer|min:1|exists:label_profiles,id', 'q' => 'nullable|string|max:150']);
        $targetType = $data['target_type'] ?? 'asset';
        $profile = ! empty($data['profile_id']) ? LabelProfile::findOrFail($data['profile_id']) : null;
        $geometry = $service->geometry($profile ? $profile->only(LabelService::GEOMETRY_FIELDS) : $service->defaults());
        $model = $targetType === 'asset' ? Asset::class : Location::class;
        $query = $data['q'] ?? '';
        $results = $model::whereNull('archived_at')->when($targetType === 'asset', fn ($q) => $q->where('status', '!=', 'written_off'))->when($query !== '', function ($builder) use ($query) {
            $builder->where(function ($builder) use ($query) {
                $builder->where('name', 'like', '%'.$query.'%')->orWhere('code', 'like', '%'.$query.'%');
            });
        })->orderBy('name')->orderBy('id')->limit(LabelService::MAX_LABELS + 1)->get(['uuid', 'code', 'name']);
        $truncated = $results->count() > LabelService::MAX_LABELS;
        $targets = $results->take(LabelService::MAX_LABELS);
        $profiles = LabelProfile::orderBy('name')->get();
        if ($request->is('api/*')) {
            return response()->json(['data' => ['profiles' => $profiles, 'geometry' => $geometry, 'targets' => $targets, 'target_type' => $targetType, 'truncated' => $truncated, 'limits' => ['labels' => LabelService::MAX_LABELS, 'pages' => LabelService::MAX_PAGES]]]);
        }

        return view('labels.index', compact('profiles', 'profile', 'geometry', 'targets', 'targetType', 'query', 'truncated'));
    }

    public function store(Request $request, LabelService $service)
    {
        $profile = $service->saveProfile($request->user(), $request->only(array_merge(LabelService::GEOMETRY_FIELDS, ['name', 'profile_id', 'expected_version'])));

        return $request->is('api/*')
            ? response()->json(['data' => $profile], $profile->wasRecentlyCreated ? 201 : 200)
            : redirect()->route('labels.index', ['profile_id' => $profile->id])->with('success', 'Профиль печати сохранён.');
    }

    public function preview(Request $request, LabelService $service)
    {
        if (! $request->is('api/*') && $request->expectsJson() && $request->boolean('editor')) {
            Access::check($request->user(), 'read');
            $data = $request->validate([
                'editor' => 'required|boolean', 'target_type' => 'required|in:asset,location',
                'target_ids' => 'sometimes|array|max:500', 'target_ids.*' => 'required|string|uuid|distinct:ignore_case',
                'start_cell' => 'required|integer|min:1|max:500',
                'qr_indices' => 'sometimes|array|max:24', 'qr_indices.*' => 'required|integer|min:0|max:499|distinct',
            ]);
            $geometry = $this->geometry($request, $service);
            $ids = $data['target_ids'] ?? [];
            $targets = $ids === [] ? [] : $service->targets($request->user(), $data['target_type'], $ids);
            $layout = $service->editorLayout($geometry, count($targets), (int) $data['start_cell']);
            $images = [];
            foreach ($data['qr_indices'] ?? [] as $index) {
                if ($index >= count($targets)) {
                    throw ValidationException::withMessages(['qr_indices' => 'Индекс QR выходит за выбранную пачку.']);
                }
                $images[$index] = 'data:image/png;base64,'.base64_encode($service->qrPng($targets[$index]['qr_url']));
            }

            return response()->json(['data' => [
                'layout' => $layout, 'start_cell' => (int) $data['start_cell'],
                'box' => $service->contentBox(['width_mm' => $geometry['label_width_mm'], 'height_mm' => $geometry['label_height_mm']]),
                'images' => (object) $images,
            ]])->header('Cache-Control', 'private, no-store');
        }
        [$geometry, $targets, $startCell] = $this->batch($request, $service);
        $layout = $service->layout($geometry, count($targets), $startCell);
        if ($request->is('api/*')) {
            return response()->json(['data' => ['layout' => $layout, 'targets' => $targets, 'start_cell' => $startCell]]);
        }
        $boxes = [];
        foreach ($layout['pages'] as $cells) {
            foreach ($cells as $cell) {
                $boxes[$cell['index']] = $service->contentBox($cell);
            }
        }
        $images = array_map(fn ($target) => 'data:image/png;base64,'.base64_encode($service->qrPng($target['qr_url'])), $targets);

        return view('labels.preview', compact('geometry', 'targets', 'startCell', 'layout', 'boxes', 'images'));
    }

    public function pdf(Request $request, LabelService $service)
    {
        [$geometry, $targets, $startCell] = $this->batch($request, $service);

        return $this->download($service->pdf($geometry, $targets, $startCell), 'skladex-labels.pdf');
    }

    public function test(Request $request, LabelService $service)
    {
        Access::check($request->user(), 'read');

        return $this->download($service->testPdf($this->geometry($request, $service)), 'skladex-print-test.pdf');
    }

    private function batch(Request $request, LabelService $service): array
    {
        Access::check($request->user(), 'read');
        $data = $request->validate(['target_type' => 'required|in:asset,location', 'target_ids' => 'required|array|min:1|max:500', 'target_ids.*' => 'required|string|uuid|distinct:ignore_case', 'start_cell' => 'required|integer|min:1|max:500']);
        $geometry = $this->geometry($request, $service);
        $targets = $service->targets($request->user(), $data['target_type'], $data['target_ids']);

        return [$geometry, $targets, (int) $data['start_cell']];
    }

    private function geometry(Request $request, LabelService $service): array
    {
        $data = $request->validate(['profile_id' => 'nullable|integer|min:1|exists:label_profiles,id']);
        $base = ! empty($data['profile_id']) ? LabelProfile::findOrFail($data['profile_id'])->only(LabelService::GEOMETRY_FIELDS) : $service->defaults();

        return $service->geometry(array_replace($base, $request->only(LabelService::GEOMETRY_FIELDS)));
    }

    private function download(string $pdf, string $filename)
    {
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
