<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_writeoffs', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->char('asset_uuid', 36)->charset('ascii')->collation('ascii_bin')->index();
            $t->string('asset_code', 64)->collation('utf8mb4_bin');
            $t->string('asset_name');
            $t->json('snapshot');
            $t->text('reason');
            $t->foreignId('written_off_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('written_off_at', 6)->nullable();
            $t->foreignId('restored_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('restored_at', 6)->nullable();
            $t->text('restore_reason')->nullable();
            $t->foreignId('purged_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('purged_at', 6)->nullable();
            $t->text('purge_reason')->nullable();
            $t->string('purge_backup_path')->nullable();
            $t->char('purge_backup_sha256', 64)->nullable();
            $t->index(['asset_id', 'written_off_at']);
        });

        Schema::create('retired_asset_codes', function (Blueprint $t) {
            $t->id();
            $t->char('asset_uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->unsignedBigInteger('former_asset_id')->unique();
            $t->string('code', 64)->collation('utf8mb4_bin')->unique();
            $t->dateTime('retired_at', 6);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('asset_writeoffs') && DB::table('asset_writeoffs')->exists()) {
            throw new RuntimeException('Нельзя удалить историю списаний и резервирование старых кодов.');
        }
        Schema::dropIfExists('retired_asset_codes');
        Schema::dropIfExists('asset_writeoffs');
    }
};
