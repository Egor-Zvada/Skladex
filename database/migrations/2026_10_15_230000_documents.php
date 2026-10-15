<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('software_product_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('work_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('path')->unique();
            $t->string('original_name');
            $t->string('mime_type', 150);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->timestamps();
        });
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_one_parent CHECK ((asset_id IS NOT NULL)+(software_product_id IS NOT NULL)+(work_order_id IS NOT NULL)=1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
