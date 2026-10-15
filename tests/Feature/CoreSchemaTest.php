<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetComponent;
use App\Models\AssetMovement;
use App\Models\Category;
use App\Models\Kit;
use App\Models\KitMember;
use App\Models\LabelProfile;
use App\Models\LicenseAssignment;
use App\Models\LicenseInstance;
use App\Models\LicenseSecret;
use App\Models\Location;
use App\Models\PhysicalPlacement;
use App\Models\QrAlias;
use App\Models\SoftwareInstallation;
use App\Models\SoftwareProduct;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CoreSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Core constraints must be tested on MariaDB, not SQLite.');
        }

        // RefreshDatabase can migrate:fresh. Never allow this suite to target production.
        if (! preg_match('/(?:^|_)(?:tests?|testing)(?:_|$)/i', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('CoreSchemaTest requires a dedicated database named *_test or *_testing.');
        }
    }

    public function test_uuid_binding_and_duplicate_inventory_numbers_are_independent_of_identity(): void
    {
        $first = $this->asset(['inventory_no' => '000042', 'responsible_name' => 'Иванов Иван']);
        $second = $this->asset(['inventory_no' => '000042']);

        $this->assertTrue(Str::isUuid($first->uuid));
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertSame('uuid', $first->getRouteKeyName());
        $this->assertSame(1, $first->version);
        $this->assertSame('stock', $first->status);
        $this->assertSame('unknown', $first->condition);
        $this->assertSame('000042', $first->fresh()->inventory_no);
        $this->assertSame('Иванов Иван', $first->fresh()->responsible_name);
        $this->assertNull($first->responsible_id);
        $this->assertSame(2, Asset::where('inventory_no', '000042')->count());

        $oldUuid = $first->uuid;
        $first->update(['name' => 'Переименованное устройство']);
        $this->assertSame($oldUuid, $first->fresh()->uuid);

        $first->update(['archived_at' => now()]);
        $this->assertConstraintRejects(fn () => $this->asset(['code' => $first->code]));
    }

    public function test_room_and_history_foreign_keys_restrict_deletion_instead_of_cascading(): void
    {
        $room = Location::create(['code' => 'SKX-R-TEST', 'name' => 'Кабинет']);
        $asset = $this->asset(['location_id' => $room->id]);
        $this->assertConstraintRejects(fn () => $room->delete());
        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'location_id' => $room->id]);

        $movement = AssetMovement::create([
            'asset_id' => $asset->id,
            'from_location_id' => null,
            'to_location_id' => $room->id,
            'reason' => 'Первичная постановка на учёт',
            'actor_id' => $this->actor()->id,
            'asset_version' => 1,
            'occurred_at' => now(),
        ]);

        $this->assertConstraintRejects(fn () => $asset->delete());
        $this->assertDatabaseHas('asset_movements', ['id' => $movement->id]);
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    public function test_component_quantity_and_individual_serial_constraints_are_enforced(): void
    {
        $parent = $this->asset();
        $child = $this->asset();
        $base = [
            'parent_asset_id' => $parent->id,
            'component_type' => 'ram',
            'description' => 'Память 8 ГБ',
            'quantity' => 2,
            'installed_at' => now(),
        ];

        $description = AssetComponent::create($base);
        $this->assertSame(2, $description->quantity);
        $this->assertSame(2, Asset::count(), 'Description rows must not create additional assets.');

        $this->assertConstraintRejects(fn () => AssetComponent::create(array_replace($base, ['quantity' => 0])));
        $this->assertConstraintRejects(fn () => AssetComponent::create(array_replace($base, ['child_asset_id' => $child->id])));
        $this->assertConstraintRejects(fn () => AssetComponent::create(array_replace($base, ['serial_no' => 'ONE-SERIAL'])));
        $this->assertConstraintRejects(fn () => AssetComponent::create(array_replace($base, [
            'child_asset_id' => $parent->id,
            'quantity' => 1,
        ])));
    }

    public function test_component_and_rack_share_one_current_physical_parent_guard(): void
    {
        $pc = $this->asset();
        $rack = $this->asset();
        $child = $this->asset();

        $placement = PhysicalPlacement::create([
            'asset_id' => $child->id,
            'parent_asset_id' => $pc->id,
            'kind' => 'component',
        ]);

        $this->assertConstraintRejects(fn () => PhysicalPlacement::create([
            'asset_id' => $child->id,
            'parent_asset_id' => $rack->id,
            'kind' => 'rack',
        ]));
        $this->assertSame($pc->id, $child->physicalPlacement->parent_asset_id);
        $this->assertConstraintRejects(fn () => $pc->delete());

        $placement->delete();
        PhysicalPlacement::create(['asset_id' => $child->id, 'parent_asset_id' => $rack->id, 'kind' => 'rack']);
        $this->assertSame($rack->id, $child->fresh()->physicalPlacement->parent_asset_id);

        $this->assertConstraintRejects(fn () => PhysicalPlacement::create([
            'asset_id' => $pc->id, 'parent_asset_id' => $pc->id, 'kind' => 'component',
        ]));
    }

    public function test_logical_kit_membership_does_not_assign_a_physical_parent(): void
    {
        $asset = $this->asset();
        $kit = Kit::create(['code' => 'TEST-KIT', 'name' => 'Комплект']);
        KitMember::create(['kit_id' => $kit->id, 'asset_id' => $asset->id]);

        $this->assertSame(1, $kit->assets()->count());
        $this->assertSame(0, PhysicalPlacement::count());
        $this->assertConstraintRejects(fn () => KitMember::create(['kit_id' => $kit->id, 'asset_id' => $asset->id]));
        $this->assertConstraintRejects(fn () => $kit->delete());
    }

    public function test_assignment_unique_guard_allows_multiple_released_history_rows(): void
    {
        $license = $this->license();
        $asset = $this->asset();
        $actor = $this->actor();
        $base = [
            'license_instance_id' => $license->id,
            'asset_id' => $asset->id,
            'actor_id' => $actor->id,
            'assigned_at' => '2026-10-15 01:00:00',
        ];

        $first = LicenseAssignment::create($base);
        $this->assertConstraintRejects(fn () => LicenseAssignment::create($base));
        $first->update(['released_at' => '2026-10-15 02:00:00']);

        $second = LicenseAssignment::create(array_replace($base, ['assigned_at' => '2026-10-15 03:00:00']));
        $second->update(['released_at' => '2026-10-15 04:00:00']);
        $third = LicenseAssignment::create(array_replace($base, ['assigned_at' => '2026-10-15 05:00:00']));

        $this->assertSame(3, $license->assignments()->count());
        $this->assertSame(1, $license->activeAssignments()->count());
        $this->assertSame($third->id, $license->activeAssignments()->first()->id);
        $this->assertConstraintRejects(fn () => $license->delete());
    }

    public function test_model_never_decrypts_or_serializes_license_payload(): void
    {
        $license = $this->license();
        $secret = LicenseSecret::create([
            'license_instance_id' => $license->id,
            'ciphertext' => 'opaque-test-payload-not-a-real-license-key',
            'key_id' => 'test-key-version',
        ]);

        $this->assertSame('opaque-test-payload-not-a-real-license-key', $secret->fresh()->ciphertext);
        $this->assertArrayNotHasKey('ciphertext', $secret->toArray());
        $this->assertArrayNotHasKey('key_id', $secret->toArray());
        $this->assertArrayNotHasKey('secret', $license->load('secret')->toArray());

        $second = $this->license();
        LicenseSecret::create([
            'license_instance_id' => $second->id,
            'ciphertext' => $secret->ciphertext,
            'key_id' => 'test-key-version',
        ]);
        $this->assertSame(2, LicenseSecret::count(), 'Shared activation material is not globally unique.');
    }

    public function test_installation_requires_a_device_or_an_explicit_environment(): void
    {
        $product = SoftwareProduct::create(['name' => 'Программа', 'kind' => 'in_house']);
        $this->assertConstraintRejects(fn () => SoftwareInstallation::create(['software_product_id' => $product->id]));
        $this->assertConstraintRejects(fn () => SoftwareInstallation::create([
            'software_product_id' => $product->id, 'environment' => '   ',
        ]));

        $environment = SoftwareInstallation::create(['software_product_id' => $product->id, 'environment' => 'production']);
        $device = SoftwareInstallation::create(['software_product_id' => $product->id, 'asset_id' => $this->asset()->id]);
        $this->assertNotSame($environment->id, $device->id);
    }

    public function test_label_geometry_rejects_invalid_grid_and_checks_both_axes(): void
    {
        $base = [
            'name' => 'Тестовый шаблон',
            'paper_width_mm' => '210.00', 'paper_height_mm' => '297.00',
            'label_width_mm' => '45.00', 'label_height_mm' => '25.00',
            'columns' => 4, 'rows' => 10,
            'gap_x_mm' => '3.00', 'gap_y_mm' => '2.00',
            'margin_left_mm' => '10.00', 'margin_top_mm' => '10.00',
            'offset_x_mm' => '-2.00', 'offset_y_mm' => '-2.00',
        ];
        LabelProfile::create($base);

        foreach ([
            ['label_height_mm' => 0], ['columns' => 0], ['gap_x_mm' => -1],
            ['offset_x_mm' => -11], ['offset_y_mm' => -11],
            ['offset_x_mm' => 30], ['offset_y_mm' => 40],
            ['label_width_mm' => '0.10', 'label_height_mm' => '0.10', 'columns' => 501, 'rows' => 1, 'gap_x_mm' => 0],
        ] as $invalid) {
            $this->assertConstraintRejects(fn () => LabelProfile::create(array_replace($base, $invalid)));
        }
    }

    public function test_legacy_aliases_keep_cyrillic_and_latin_identifiers_distinct(): void
    {
        $room = Location::create(['code' => 'SKX-R-ALIASES', 'name' => 'Помещение']);
        QrAlias::create(['target_type' => 'location', 'target_id' => $room->id, 'alias' => 'legacy:location:Сab-002']);
        QrAlias::create(['target_type' => 'location', 'target_id' => $room->id, 'alias' => 'legacy:location:Cab-002']);

        $this->assertSame(2, $room->qrAliases()->count());
        $this->assertSame('legacy:location:Сab-002', $room->qrAliases()->where('alias', 'legacy:location:Сab-002')->first()->alias);
    }

    public function test_system_seed_is_repeatable_and_keeps_inventory_and_users_empty(): void
    {
        $this->seed(DatabaseSeeder::class);
        $categoryCount = Category::count();
        $profileCount = LabelProfile::count();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($categoryCount, Category::count());
        $this->assertSame($profileCount, LabelProfile::count());
        $this->assertTrue(Category::where('domain', 'audio')->exists());
        $this->assertTrue(Category::where('kind', 'rack')->exists());
        $this->assertTrue(Category::where('kind', 'computer')->exists());
        $this->assertSame(0, Asset::count());
        $this->assertSame(0, Location::count());
        $this->assertSame(0, SoftwareProduct::count());
        $this->assertSame(0, LicenseInstance::count());
        $this->assertSame(0, QrAlias::count());
        $this->assertSame(0, User::count());
    }

    private function asset(array $attributes = []): Asset
    {
        $category = Category::firstOrCreate(['slug' => 'test-core'], [
            'name' => 'Тестовая категория', 'domain' => 'it', 'kind' => 'standard',
        ]);

        return Asset::create(array_replace([
            'code' => 'TEST-A-'.Str::uuid(), 'name' => 'Тестовый предмет', 'category_id' => $category->id,
        ], $attributes));
    }

    private function actor(): User
    {
        return User::create([
            'name' => 'Оператор теста', 'email' => Str::uuid().'@example.test',
            'password' => Str::random(40), 'role' => 'operator', 'is_active' => true,
        ]);
    }

    private function license(): LicenseInstance
    {
        $product = SoftwareProduct::create(['name' => 'Тестовое ПО', 'kind' => 'third_party']);

        return LicenseInstance::create([
            'code' => 'TEST-L-'.Str::uuid(), 'software_product_id' => $product->id,
            'metric' => 'device', 'capacity' => null,
        ]);
    }

    private function assertConstraintRejects(callable $operation): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertContains((string) $exception->getCode(), ['23000', 'HY000']);
            $this->assertContains((int) ($exception->errorInfo[1] ?? 0), [1062, 1451, 1452, 4025]);

            return;
        }

        $this->fail('MariaDB accepted a row that violates a core integrity constraint.');
    }
}
