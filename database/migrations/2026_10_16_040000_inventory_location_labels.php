<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable human-readable snapshots retain both building and room names.
        Schema::table('inventory_snapshot_items', fn (Blueprint $table) => $table->text('location_name')->nullable()->change());
        Schema::table('inventory_observations', fn (Blueprint $table) => $table->text('location_name')->change());
    }

    public function down(): void
    {
        foreach (['inventory_snapshot_items', 'inventory_observations'] as $table) {
            if (DB::table($table)->whereRaw('CHAR_LENGTH(location_name) > 200')->exists()) {
                throw new RuntimeException('Inventory location labels exceed 200 characters; truncation is forbidden.');
            }
        }
        Schema::table('inventory_snapshot_items', fn (Blueprint $table) => $table->string('location_name', 200)->nullable()->change());
        Schema::table('inventory_observations', fn (Blueprint $table) => $table->string('location_name', 200)->change());
    }
};
