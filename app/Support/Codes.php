<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Codes
{
    public static function lockPhysicalGraph(): void
    {
        // All graph mutations acquire this row before locking any asset. This
        // prevents disjoint attachments from closing a cycle and keeps subtree
        // moves consistent with concurrent installs/removals.
        DB::table('code_counters')->insertOrIgnore(['prefix' => 'LOCK-PHYSICAL-GRAPH', 'next_value' => 1]);
        DB::table('code_counters')->where('prefix', 'LOCK-PHYSICAL-GRAPH')->lockForUpdate()->first();
    }

    public static function next(string $prefix): string
    {
        return DB::transaction(function () use ($prefix) {
            DB::table('code_counters')->insertOrIgnore(['prefix' => $prefix, 'next_value' => 1]);
            do {
                $counter = DB::table('code_counters')->where('prefix', $prefix)->lockForUpdate()->first();
                DB::table('code_counters')->where('prefix', $prefix)->increment('next_value');
                $code = $prefix.'-'.str_pad((string) $counter->next_value, 6, '0', STR_PAD_LEFT);
            } while ($prefix === 'SKX-A' && (DB::table('retired_asset_codes')->where('code', $code)->exists() || DB::table('assets')->where('code', $code)->exists()));

            return $code;
        });
    }
}
