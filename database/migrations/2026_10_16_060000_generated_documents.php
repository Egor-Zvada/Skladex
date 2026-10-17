<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_document_records', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('template_key', 40);
            $table->unsignedInteger('template_version');
            $table->json('template_snapshot');
            $table->enum('status', ['draft', 'final'])->default('draft');
            $table->string('title');
            $table->date('document_date');
            $table->string('organization')->nullable();
            $table->string('author_name')->nullable();
            $table->string('recipient_name')->nullable();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalized_at', 6)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['status', 'document_date']);
        });
        Schema::create('generated_document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_record_id')->constrained('generated_document_records')->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->json('snapshot');
            $table->unique(['document_record_id', 'position'], 'generated_document_position_unique');
        });
        DB::statement('ALTER TABLE generated_document_records ADD CONSTRAINT generated_document_final_state CHECK ((status = \'draft\' AND finalized_at IS NULL) OR (status = \'final\' AND finalized_at IS NOT NULL))');
        DB::statement('ALTER TABLE generated_document_items ADD CONSTRAINT generated_document_position_positive CHECK (position BETWEEN 1 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_document_items');
        Schema::dropIfExists('generated_document_records');
    }
};
