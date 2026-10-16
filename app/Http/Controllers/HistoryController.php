<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Support\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class HistoryController extends Controller
{
    public function index(Request $request)
    {
        Access::check($request->user(), 'read');
        $filters = $request->validate([
            'source' => ['nullable', Rule::in(['legacy', 'current'])],
            'q' => 'nullable|string|max:150',
            'asset' => 'nullable|uuid',
        ]);
        $source = $filters['source'] ?? '';
        $q = trim($filters['q'] ?? '');
        $asset = ! empty($filters['asset']) ? Asset::where('uuid', $filters['asset'])->firstOrFail() : null;
        $query = AuditEvent::with('actor:id,name');
        if ($source === 'legacy') {
            $query->where('event', 'like', 'legacy.%');
        } elseif ($source === 'current') {
            $query->where('event', 'not like', 'legacy.%');
        }
        if ($asset) {
            self::scopeAsset($query, $asset);
        }
        if ($q !== '') {
            // Search indexed identity/event columns, never the unbounded JSON payload.
            $query->where(function (Builder $query) use ($q) {
                $query->where('event', 'like', '%'.$q.'%')->orWhere('subject_type', 'like', '%'.$q.'%')->orWhere('subject_uuid', 'like', '%'.$q.'%');
                if (ctype_digit($q) && strlen($q) <= 18) {
                    $query->orWhere('subject_id', (int) $q);
                }
            });
        }
        $entries = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(50)->withQueryString();
        $assetIds = $entries->getCollection()->where('subject_type', 'Asset')->pluck('subject_id')->filter()->unique();
        $assetUuids = $entries->getCollection()->where('subject_type', 'Asset')->pluck('subject_uuid')->filter()->unique();
        $assets = $assetIds->isEmpty() && $assetUuids->isEmpty() ? collect() : Asset::where(function (Builder $query) use ($assetIds, $assetUuids) {
            $query->whereIn('id', $assetIds)->orWhereIn('uuid', $assetUuids);
        })->get(['id', 'uuid', 'code', 'name', 'archived_at']);
        $rows = $entries->getCollection()->map(fn (AuditEvent $event) => self::present($event, $assets));

        return response()->view('history.index', compact('entries', 'rows', 'source', 'q', 'asset'))->header('Cache-Control', 'private, no-store');
    }

    /** Read-only asset summary. The public card never includes this partial. */
    public static function legacyFor(Asset $asset): Collection
    {
        Access::check(auth()->user(), 'read');
        $query = AuditEvent::where('event', 'like', 'legacy.equipment.%');
        self::scopeAsset($query, $asset);

        return $query->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()->map(fn (AuditEvent $event) => self::present($event, collect([$asset])));
    }

    private static function scopeAsset(Builder $query, Asset $asset): void
    {
        $query->where('subject_type', 'Asset')->where(function (Builder $query) use ($asset) {
            $query->where('subject_id', $asset->id)->orWhere('subject_uuid', $asset->uuid);
        });
    }

    private static function present(AuditEvent $event, Collection $assets): array
    {
        $legacy = str_starts_with($event->event, 'legacy.');
        $data = is_array($event->data) ? $event->data : [];
        $target = $event->subject_type === 'Asset' ? $assets->first(fn (Asset $asset) => ($event->subject_id !== null && (int) $event->subject_id === $asset->id) || ($event->subject_uuid !== null && $event->subject_uuid === $asset->uuid)) : null;
        $details = $legacy ? self::legacyData($data) : self::scrubCredentials($data);

        return [
            'id' => $event->id,
            'event' => $event->event,
            'title' => match ($event->event) {
                'legacy.equipment.insert' => 'Создание в прежней системе',
                'legacy.equipment.update' => 'Изменение в прежней системе',
                'legacy.equipment.delete' => 'Удаление в прежней системе',
                default => $event->event,
            },
            'legacy' => $legacy,
            'created_at' => $legacy && ($data['source_timestamp_unknown'] ?? false) ? null : $event->created_at,
            // Legacy audit_log has no author; the importer is not the historical author.
            'actor' => $legacy ? null : $event->actor?->name,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'subject_uuid' => $event->subject_uuid,
            'asset' => $target,
            'missing_asset' => $event->subject_type === 'Asset' && $target === null,
            'legacy_audit_id' => $legacy ? self::scalar($data['legacy_audit_id'] ?? null) : null,
            'legacy_entity_id' => $legacy ? self::scalar($data['legacy_entity_id'] ?? null) : null,
            'source_system' => $legacy ? self::scalar($data['source_system'] ?? null) : null,
            'details' => json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    private static function legacyData(array $data): array
    {
        $safe = [];
        foreach (['source_system', 'legacy_audit_id', 'legacy_entity_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $safe[$field] = self::scalar($data[$field]);
            }
        }
        $snapshot = $data['original_snapshot'] ?? null;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        if (is_array($snapshot)) {
            $safe['original_snapshot'] = [];
            foreach (['id', 'code', 'name', 'inventory_no', 'status', 'cabinet_id', 'category_id'] as $field) {
                if (array_key_exists($field, $snapshot)) {
                    $safe['original_snapshot'][$field] = self::scalar($snapshot[$field]);
                }
            }
        }

        return $safe;
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) || $value === null ? $value : null;
    }

    private static function scrubCredentials(array $data): array
    {
        $safe = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/^key$|password|passwd|pwd|secret|cipher|master.?key|token|activation|license.?key|api.?key|authorization|cookie|private.?key/i', $key)) {
                continue;
            }
            $safe[$key] = is_array($value) ? self::scrubCredentials($value) : $value;
        }

        return $safe;
    }
}
