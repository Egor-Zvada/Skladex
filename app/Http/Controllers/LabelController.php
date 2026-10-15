<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\LabelProfile;
use App\Models\Location;
use App\Services\LabelService;
use App\Support\Access;
use Illuminate\Http\Request;

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
        $results = $model::whereNull('archived_at')->when($query !== '', function ($builder) use ($query) {
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

        return $this->download($service->pdf($geometry, $targets, $startCell), 'skladex-labels-a4.pdf');
    }

    public function test(Request $request, LabelService $service)
    {
        Access::check($request->user(), 'read');

        return $this->download($service->testPdf($this->geometry($request, $service)), 'skladex-print-test-a4.pdf');
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
