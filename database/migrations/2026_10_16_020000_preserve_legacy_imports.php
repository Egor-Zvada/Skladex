<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            // Stage lighting is technical equipment in the imported registry.
            // Unknown is explicit; an uncategorized source row is not classified as IT.
            $table->enum('domain', ['it', 'audio', 'stage', 'unknown'])->change();
        });
        Schema::table('asset_movements', function (Blueprint $table): void {
            // Legacy events did not record versions and may not identify an actor/time.
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->unsignedInteger('asset_version')->nullable()->change();
            $table->dateTime('occurred_at', 6)->nullable()->change();
        });
        Schema::create('legacy_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('source_system', 64)->charset('ascii');
            $table->char('dataset_sha256', 64)->charset('ascii');
            $table->string('source_host')->nullable();
            $table->dateTime('exported_at', 6)->nullable();
            $table->dateTime('applied_at', 6)->useCurrent();
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('source_counts');
            $table->json('result_counts');
            $table->json('metadata')->nullable();
            $table->unique(['source_system', 'dataset_sha256']);
        });
        Schema::create('legacy_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('legacy_imports')->restrictOnDelete();
            $table->string('source_system', 64)->charset('ascii');
            $table->string('table_name', 64)->charset('ascii');
            $table->string('source_key', 255)->collation('utf8mb4_bin');
            $table->string('target_type', 64)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->char('target_uuid', 36)->nullable()->charset('ascii')->collation('ascii_bin');
            $table->json('payload');
            $table->char('source_sha256', 64)->charset('ascii');
            $table->dateTime('created_at', 6)->useCurrent();
            $table->unique(['source_system', 'table_name', 'source_key'], 'legacy_source_record_unique');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('legacy_records')->exists() || DB::table('categories')->whereIn('domain', ['stage', 'unknown'])->exists()) {
            throw new RuntimeException('Legacy data exists. Review and back up the data before a schema rollback.');
        }
        Schema::dropIfExists('legacy_records');
        Schema::dropIfExists('legacy_imports');
        Schema::table('categories', fn (Blueprint $table) => $table->enum('domain', ['it', 'audio'])->change());
        // Nullable historical fields remain safe for both old and new commands.
    }
};
