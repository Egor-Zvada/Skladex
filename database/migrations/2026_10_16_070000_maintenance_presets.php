<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_preset_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('preset_key', 40)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('maintenance_plan_id')->constrained()->restrictOnDelete();
            $table->json('preset_snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['asset_id', 'preset_key'], 'maintenance_asset_preset_unique');
            $table->unique('maintenance_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_preset_applications');
    }
};
