<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Location;
use App\Models\QrAlias;

class QrResolver
{
    /** Parse data only. Never visit a scanned URL or redirect to its host. */
    public function resolve(string $input): Asset|Location|null
    {
        $value = trim($input);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return null;
        }
        if (preg_match('~^https?://~i', $value)) {
            // Only an exact, explicitly imported historic URL can bypass the host
            // allowlist. It resolves locally; it is never fetched or visited.
            if ($exact = $this->alias('url:'.$value)) {
                return $exact;
            }
            $url = parse_url($value);
            if (! $url || ! isset($url['host'])) {
                return null;
            }
            $hosts = config('skladex.qr_allowed_hosts', []);
            if (! in_array(mb_strtolower($url['host']), array_map('mb_strtolower', $hosts), true)) {
                return null;
            }
            $value = ($url['path'] ?? '/').(isset($url['query']) ? '?'.$url['query'] : '');
        }
        if (preg_match('~^/p/(a|r)/([0-9a-f-]{36})/?$~i', $value, $m)) {
            return ($m[1] === 'a' ? Asset::query() : Location::query())->where('uuid', $m[2])->whereNull('archived_at')->first();
        }
        if (preg_match('~^/eq/([^/?#]+)/?$~u', $value, $m)) {
            return $this->legacyAssetKey(rawurldecode($m[1]));
        }
        if (preg_match('~^/cabinet/([^/?#]+)/?$~u', $value, $m)) {
            return $this->alias('location-id:'.rawurldecode($m[1]));
        }
        if (str_starts_with($value, '/cabinet.php?')) {
            parse_str(parse_url($value, PHP_URL_QUERY) ?? '', $q);

            return isset($q['id']) && is_string($q['id']) ? $this->alias('location-id:'.$q['id']) : null;
        }
        if (str_starts_with($value, '/equipment_card.php?') || str_starts_with($value, '/admin/equipment.php?')) {
            parse_str(parse_url($value, PHP_URL_QUERY) ?? '', $q);
            $raw = $q['code'] ?? $q['key'] ?? $q['id'] ?? null;

            return is_string($raw) ? $this->legacyAssetKey($raw) : null;
        }
        if (str_starts_with($value, '/')) {
            return $this->alias('path:'.$value);
        }
        // A legacy numeric database ID is accepted ONLY through an explicit typed alias.
        if (ctype_digit($value)) {
            return $this->legacyAssetKey($value);
        }

        return $this->assetCode($value) ?? Location::where('code', $value)->whereNull('archived_at')->first() ?? $this->alias('location-id:'.$value);
    }

    private function assetCode(string $code): ?Asset
    {
        $code = trim($code);
        if (preg_match('/^EQ-\d+$/iD', $code)) {
            $code = strtoupper($code);
        }
        $alias = $this->alias('asset-code:'.$code);

        return $alias instanceof Asset ? $alias : Asset::where('code', $code)->whereNull('archived_at')->first();
    }

    private function legacyAssetKey(string $value): ?Asset
    {
        $value = trim($value);
        if (! ctype_digit($value)) {
            return $this->assetCode($value);
        }
        // The old PHP card treated numeric keys as integer IDs. Normalize only
        // that typed ID; Unicode room IDs and EQ code padding stay exact.
        $id = ltrim($value, '0');
        $target = $this->alias('asset-id:'.($id === '' ? '0' : $id));

        return $target instanceof Asset ? $target : null;
    }

    private function alias(string $alias): Asset|Location|null
    {
        $row = QrAlias::where('alias', $alias)->first();
        if (! $row) {
            return null;
        }

        return match ($row->target_type) {
            'asset' => Asset::whereKey($row->target_id)->whereNull('archived_at')->first(),
            'location' => Location::whereKey($row->target_id)->whereNull('archived_at')->first(),
            default => null,
        };
    }

    public function url(Asset|Location $target): string
    {
        return rtrim((string) config('skladex.qr_base_url'), '/').'/p/'.($target instanceof Asset ? 'a' : 'r').'/'.$target->uuid;
    }
}
