<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->enum('domain', ['it', 'audio']);
            $table->enum('kind', ['standard', 'computer', 'rack'])->default('standard');
            $table->enum('maintenance_policy', ['unspecified', 'none', 'reactive_only', 'scheduled'])->default('unspecified');
            $table->timestamps(6);
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('code', 64)->collation('utf8mb4_bin')->unique();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_public')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('code', 64)->collation('utf8mb4_bin')->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('responsible_name', 255)->nullable();
            $table->string('inventory_no', 120)->nullable()->index();
            $table->string('serial_no', 160)->nullable()->index();
            $table->string('manufacturer', 160)->nullable();
            $table->string('model', 200)->nullable();
            $table->enum('status', ['stock', 'in_use', 'repair', 'written_off'])->default('stock');
            $table->enum('condition', ['working', 'limited', 'faulty', 'unknown'])->default('unknown');
            $table->enum('maintenance_policy', ['unspecified', 'none', 'reactive_only', 'scheduled'])->default('unspecified');
            $table->json('specifications')->nullable();
            $table->text('private_notes')->nullable();
            $table->date('purchased_on')->nullable();
            $table->date('warranty_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['category_id', 'status']);
        });

        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->enum('from_status', ['stock', 'in_use', 'repair', 'written_off'])->nullable();
            $table->enum('to_status', ['stock', 'in_use', 'repair', 'written_off'])->nullable();
            $table->text('reason');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('asset_version');
            $table->dateTime('occurred_at', 6);
            $table->timestamps(6);
            $table->index(['asset_id', 'occurred_at']);
        });

        Schema::create('asset_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_asset_id')->constrained('assets')->restrictOnDelete();
            $table->foreignId('child_asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->string('component_type', 100);
            $table->text('description');
            $table->unsignedInteger('quantity');
            $table->string('serial_no', 160)->nullable();
            $table->string('inventory_no', 120)->nullable();
            $table->dateTime('installed_at', 6)->useCurrent();
            $table->dateTime('removed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['parent_asset_id', 'removed_at']);
        });

        Schema::create('physical_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->unique()->constrained('assets')->restrictOnDelete();
            $table->foreignId('parent_asset_id')->constrained('assets')->restrictOnDelete();
            $table->enum('kind', ['component', 'rack']);
            $table->string('position', 100)->nullable();
            $table->timestamps(6);
        });

        Schema::create('kits', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('code', 64)->collation('utf8mb4_bin')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('kit_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_id')->constrained('kits')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['kit_id', 'asset_id']);
        });

        Schema::create('software_products', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name');
            $table->string('vendor')->nullable();
            $table->enum('kind', ['third_party', 'in_house', 'firmware'])->default('third_party');
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('repository_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('software_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_product_id')->constrained('software_products')->restrictOnDelete();
            $table->string('version_name', 120);
            $table->date('released_on')->nullable();
            $table->date('supported_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps(6);
            $table->unique(['software_product_id', 'version_name'], 'software_releases_product_version_unique');
        });

        Schema::create('software_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_product_id')->constrained('software_products')->restrictOnDelete();
            $table->foreignId('software_release_id')->nullable()->constrained('software_releases')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->string('environment', 160)->nullable();
            $table->string('service_name')->nullable();
            $table->date('installed_on')->nullable();
            $table->dateTime('last_verified_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('license_instances', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('code', 64)->collation('utf8mb4_bin')->unique();
            $table->foreignId('software_product_id')->constrained('software_products')->restrictOnDelete();
            $table->string('name')->nullable();
            $table->string('external_reference')->nullable();
            $table->enum('metric', ['device', 'user', 'instance', 'unknown'])->default('device');
            $table->unsignedInteger('capacity')->nullable();
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('archived_at', 6)->nullable();
            $table->timestamps(6);
        });

        Schema::create('license_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_instance_id')->unique()->constrained('license_instances')->restrictOnDelete();
            $table->longText('ciphertext');
            $table->string('key_id', 100);
            $table->timestamps(6);
        });

        Schema::create('license_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_instance_id')->constrained('license_instances')->restrictOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();
            $table->dateTime('assigned_at', 6)->useCurrent();
            $table->dateTime('released_at', 6)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('active_guard')->nullable()->storedAs('CASE WHEN `released_at` IS NULL THEN 1 ELSE NULL END');
            $table->timestamps(6);
            $table->unique(['license_instance_id', 'asset_id', 'active_guard'], 'license_assignments_active_unique');
        });

        Schema::create('label_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('paper_width_mm', 8, 2)->default(210);
            $table->decimal('paper_height_mm', 8, 2)->default(297);
            $table->decimal('label_width_mm', 8, 2);
            $table->decimal('label_height_mm', 8, 2);
            $table->unsignedInteger('columns');
            $table->unsignedInteger('rows');
            $table->decimal('gap_x_mm', 8, 2)->default(0);
            $table->decimal('gap_y_mm', 8, 2)->default(0);
            $table->decimal('margin_left_mm', 8, 2)->default(0);
            $table->decimal('margin_top_mm', 8, 2)->default(0);
            $table->decimal('offset_x_mm', 8, 2)->default(0);
            $table->decimal('offset_y_mm', 8, 2)->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
        });

        Schema::create('qr_aliases', function (Blueprint $table) {
            $table->id();
            $table->enum('target_type', ['asset', 'location']);
            $table->unsignedBigInteger('target_id');
            $table->string('alias', 255)->collation('utf8mb4_bin')->unique();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 120)->index();
            $table->string('subject_type', 160);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->char('subject_uuid', 36)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        Schema::create('code_counters', function (Blueprint $table) {
            $table->string('prefix', 32)->collation('utf8mb4_bin')->primary();
            $table->unsignedBigInteger('next_value')->default(1);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('key', 191)->collation('utf8mb4_bin');
            $table->char('request_hash', 64);
            $table->enum('status', ['pending', 'complete'])->default('pending');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->mediumText('response_body')->nullable();
            $table->dateTime('expires_at', 6)->index();
            $table->timestamps(6);
            $table->unique(['user_id', 'key']);
        });

        // MariaDB/InnoDB owns these row-local invariants; commands own cross-row rules.
        $checks = [
            ['asset_components', 'components_quantity_positive', '`quantity` >= 1'],
            ['asset_components', 'components_linked_quantity_one', '`child_asset_id` IS NULL OR `quantity` = 1'],
            ['asset_components', 'components_serial_quantity_one', '`serial_no` IS NULL OR CHAR_LENGTH(TRIM(`serial_no`)) = 0 OR `quantity` = 1'],
            ['asset_components', 'components_not_self', '`child_asset_id` IS NULL OR `parent_asset_id` <> `child_asset_id`'],
            ['asset_components', 'components_dates_ordered', '`removed_at` IS NULL OR `removed_at` >= `installed_at`'],
            ['physical_placements', 'placements_not_self', '`asset_id` <> `parent_asset_id`'],
            ['software_installations', 'installations_have_target', '`asset_id` IS NOT NULL OR (`environment` IS NOT NULL AND CHAR_LENGTH(TRIM(`environment`)) > 0)'],
            ['license_assignments', 'assignments_dates_ordered', '`released_at` IS NULL OR `released_at` >= `assigned_at`'],
            ['label_profiles', 'labels_positive_dimensions', '`paper_width_mm` > 0 AND `paper_height_mm` > 0 AND `label_width_mm` > 0 AND `label_height_mm` > 0'],
            ['label_profiles', 'labels_bounded_grid', '`columns` >= 1 AND `rows` >= 1 AND `columns` <= 500 AND `rows` <= 500 AND (`columns` * `rows`) <= 500'],
            ['label_profiles', 'labels_nonnegative_spacing', '`gap_x_mm` >= 0 AND `gap_y_mm` >= 0 AND `margin_left_mm` >= 0 AND `margin_top_mm` >= 0'],
            ['label_profiles', 'labels_horizontal_fit', '(`margin_left_mm` + `offset_x_mm`) >= 0 AND (`margin_left_mm` + `offset_x_mm` + `columns` * `label_width_mm` + (`columns` - 1) * `gap_x_mm`) <= `paper_width_mm`'],
            ['label_profiles', 'labels_vertical_fit', '(`margin_top_mm` + `offset_y_mm`) >= 0 AND (`margin_top_mm` + `offset_y_mm` + `rows` * `label_height_mm` + (`rows` - 1) * `gap_y_mm`) <= `paper_height_mm`'],
            ['idempotency_keys', 'idempotency_http_status', '`response_status` IS NULL OR `response_status` BETWEEN 100 AND 599'],
        ];

        foreach (['locations', 'assets', 'kits', 'software_products', 'software_installations', 'license_instances', 'label_profiles'] as $table) {
            $checks[] = [$table, $table.'_version_positive', '`version` >= 1'];
        }

        foreach ($checks as [$table, $name, $expression]) {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        foreach ([
            'idempotency_keys', 'code_counters', 'audit_events', 'qr_aliases', 'label_profiles',
            'license_assignments', 'license_secrets', 'license_instances', 'software_installations',
            'software_releases', 'software_products', 'kit_members', 'kits', 'physical_placements',
            'asset_components', 'asset_movements', 'assets', 'locations', 'categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
