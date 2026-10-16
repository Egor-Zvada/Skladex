<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
        });
        Schema::table('locations', function (Blueprint $table) {
            // Legacy rooms stay unassigned until their actual building is selected.
            $table->foreignId('building_id')->nullable()->constrained('buildings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', fn (Blueprint $table) => $table->dropConstrainedForeignId('building_id'));
        Schema::dropIfExists('buildings');
    }
};
