<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_plans', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('software_installation_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name', 200);
            $t->string('mode', 20);
            $t->string('interval_unit', 10);
            $t->unsignedSmallInteger('interval_count');
            $t->date('anchor_on')->nullable();
            $t->date('next_due_on')->nullable();
            $t->date('last_completed_on')->nullable();
            $t->unsignedSmallInteger('lead_days')->default(7);
            $t->string('status', 20)->default('active');
            $t->json('checklist_schema')->nullable();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->boolean('requires_other_acceptor')->default(false);
            $t->unsignedBigInteger('version')->default(1);
            $t->timestamps();
            $t->index(['status', 'next_due_on']);
        });
        Schema::create('maintenance_occurrences', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('maintenance_plan_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('sequence');
            $t->date('due_on');
            $t->string('status', 20)->default('pending');
            $t->json('schedule_snapshot');
            $t->unsignedInteger('missed_periods')->default(0);
            $t->text('skip_reason')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['maintenance_plan_id', 'sequence']);
        });
        Schema::create('work_orders', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('software_installation_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('maintenance_occurrence_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('follow_up_for_id')->nullable()->unique()->constrained('work_orders')->restrictOnDelete();
            $t->unsignedInteger('attempt')->default(1);
            $t->string('title', 200);
            $t->string('kind', 30)->default('repair');
            $t->string('priority', 20)->default('normal');
            $t->string('status', 20)->default('open');
            $t->string('resume_status', 20)->nullable();
            $t->text('description')->nullable();
            $t->date('due_on')->nullable();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->json('checklist_schema')->nullable();
            $t->json('checklist_answers')->nullable();
            $t->text('result')->nullable();
            $t->string('reported_condition', 20)->nullable();
            $t->text('remaining_restrictions')->nullable();
            $t->json('materials')->nullable();
            $t->unsignedInteger('labour_minutes')->nullable();
            $t->decimal('service_cost', 14, 2)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('result_recorded_at')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('acceptance_note')->nullable();
            $t->boolean('counts_for_plan')->default(false);
            $t->boolean('requires_other_acceptor')->default(false);
            $t->unsignedBigInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['maintenance_occurrence_id', 'attempt']);
            $t->index(['status', 'due_on']);
        });
        Schema::create('maintenance_requests', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('software_installation_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('title', 200);
            $t->text('description');
            $t->string('priority', 20)->default('normal');
            $t->string('status', 20)->default('new');
            $t->text('decision_note')->nullable();
            $t->foreignId('work_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('work_order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('event', 50);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->text('reason')->nullable();
            $t->json('data')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('inventory_campaigns', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->string('name', 200);
            $t->string('status', 20)->default('draft');
            $t->json('scope');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('reconciliation_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->unsignedBigInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('inventory_snapshot_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventory_campaign_id')->constrained()->restrictOnDelete();
            $t->foreignId('asset_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('asset_version');
            $t->string('code', 64);
            $t->string('name', 200);
            $t->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('location_name', 200)->nullable();
            $t->string('responsible_name', 200)->nullable();
            $t->string('status', 20);
            $t->string('condition', 20);
            $t->timestamp('created_at');
            $t->unique(['inventory_campaign_id', 'asset_id']);
        });
        Schema::create('inventory_asset_reservations', function (Blueprint $t) {
            $t->foreignId('asset_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignId('inventory_campaign_id')->constrained()->restrictOnDelete();
        });
        Schema::create('inventory_observations', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('inventory_campaign_id')->constrained()->restrictOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained()->restrictOnDelete();
            $t->string('location_name', 200);
            $t->string('asset_code', 64)->nullable();
            $t->string('asset_name', 200)->nullable();
            $t->string('raw_code', 500);
            $t->unsignedBigInteger('asset_version')->nullable();
            $t->string('method', 20);
            $t->string('condition', 20)->nullable();
            $t->text('note')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('client_key', 80);
            $t->timestamp('observed_at');
            $t->timestamp('created_at');
            $t->unique(['inventory_campaign_id', 'actor_id', 'client_key'], 'inventory_observation_retry_unique');
        });
        Schema::create('inventory_resolutions', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('inventory_campaign_id')->constrained()->restrictOnDelete();
            $t->foreignId('asset_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('inventory_observation_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('decision', 30);
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('follow_up_assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('asset_version')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('inventory_reports', function (Blueprint $t) {
            $t->id();
            $t->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $t->foreignId('inventory_campaign_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision')->default(1);
            $t->json('payload');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['inventory_campaign_id', 'revision']);
        });
        Schema::create('operation_locks', function (Blueprint $t) {
            $t->string('name', 60)->primary();
        });
        DB::table('operation_locks')->insert(['name' => 'inventory.start']);
        Schema::create('operation_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('event_key', 190);
            $t->string('title', 200);
            $t->string('route_name', 100);
            $t->char('subject_uuid', 36)->charset('ascii')->collation('ascii_bin');
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['user_id', 'event_key']);
        });
        foreach (['maintenance_plans', 'work_orders', 'maintenance_requests'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_one_subject CHECK ((asset_id IS NOT NULL AND software_installation_id IS NULL) OR (asset_id IS NULL AND software_installation_id IS NOT NULL))");
        }
    }

    public function down(): void
    {
        foreach (['operation_notifications', 'operation_locks', 'inventory_reports', 'inventory_resolutions', 'inventory_observations', 'inventory_asset_reservations', 'inventory_snapshot_items', 'inventory_campaigns', 'work_order_events', 'maintenance_requests', 'work_orders', 'maintenance_occurrences', 'maintenance_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
