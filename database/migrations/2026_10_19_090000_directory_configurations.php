<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_configurations', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->longText('payload');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
        });
        DB::statement('ALTER TABLE directory_configurations ADD CONSTRAINT directory_configuration_singleton CHECK (id = 1 AND version > 0)');
    }

    public function down(): void
    {
        if (DB::table('directory_configurations')->exists()) {
            throw new RuntimeException('Сначала сохраните и перенесите настройки доменного входа.');
        }
        Schema::dropIfExists('directory_configurations');
    }
};
