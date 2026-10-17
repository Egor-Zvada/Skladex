<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetService;
use App\Services\OperationsService;
use App\Services\WriteoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssetInputRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_asset_filters_are_rejected_without_server_errors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator', 'is_active' => true]));
        foreach (['q' => ['nested'], 'category' => ['nested'], 'location' => ['nested'], 'page' => ['nested'], 'building_id' => ['nested']] as $key => $value) {
            $this->getJson('/assets?'.http_build_query([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
    }

    public function test_database_string_limits_are_validated_before_card_or_component_writes(): void
    {
        $user = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $category = Category::create(['name' => 'Компьютеры', 'slug' => 'input-pc', 'domain' => 'it', 'kind' => 'computer']);
        $input = ['name' => 'ПК', 'category_id' => $category->id, 'condition' => 'unknown', 'maintenance_policy' => 'unspecified'];
        $this->actingAs($user);
        foreach (['inventory_no' => 120, 'serial_no' => 160, 'manufacturer' => 160, 'model' => 200] as $field => $limit) {
            $this->postJson(route('assets.store'), $input + [$field => str_repeat('Я', $limit + 1)])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame(0, Asset::count());
        $asset = app(AssetService::class)->save($user, $input);
        foreach (['inventory_no' => 120, 'serial_no' => 160] as $field => $limit) {
            $this->postJson(route('assets.components.store', $asset), ['expected_version' => 1, 'component_type' => 'RAM', 'description' => 'Память', 'quantity' => 1, $field => str_repeat('Я', $limit + 1)])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('asset_components', 0);
    }

    public function test_writtenoff_assets_leave_public_rooms_labels_and_new_maintenance_subjects(): void
    {
        $user = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $category = Category::create(['name' => 'Компьютеры', 'slug' => 'hidden-pc', 'domain' => 'it', 'kind' => 'computer']);
        $room = Location::create(['name' => 'Кабинет', 'code' => 'REG-ROOM', 'is_public' => true]);
        $asset = app(AssetService::class)->save($user, ['name' => 'Списанный ПК', 'category_id' => $category->id, 'location_id' => $room->id, 'condition' => 'faulty', 'maintenance_policy' => 'none']);
        $plan = app(OperationsService::class)->createPlan($user, ['asset_uuid' => $asset->uuid, 'name' => 'Осмотр', 'mode' => 'calendar', 'interval_unit' => 'month', 'interval_count' => 3, 'lead_days' => 7]);
        app(WriteoffService::class)->writeOff($user, $asset, ['expected_version' => 1, 'reason' => 'Проверка регресса']);
        $this->getJson(route('public.asset', $asset))->assertNotFound();
        $this->getJson(route('public.location', $room))->assertOk()->assertJsonCount(0, 'assets');
        $this->actingAs($user);
        $this->get(route('assets.show', $asset))->assertRedirect(route('writeoffs.show', $asset));
        $this->get(route('assets.edit', $asset))->assertConflict();
        $this->get(route('labels.index'))->assertOk()->assertViewHas('targets', fn ($rows) => $rows->isEmpty());
        $this->postJson(route('maintenance.requests.store'), ['asset_uuid' => $asset->uuid, 'title' => 'Новая заявка', 'description' => 'Не должна создаться', 'priority' => 'normal'])->assertUnprocessable();
        $this->postJson(route('maintenance.plans.update', $plan), ['expected_version' => $plan->fresh()->version, 'status' => 'active', 'reason' => 'Проверка'])->assertUnprocessable();
        $this->assertDatabaseCount('maintenance_requests', 0);
        $this->assertSame('paused', $plan->fresh()->status);
    }

    public function test_retired_asset_identity_cannot_be_reused_even_by_model_import(): void
    {
        $category = Category::create(['name' => 'ПК', 'slug' => 'retired-pc', 'domain' => 'it', 'kind' => 'computer']);
        $uuid = (string) Str::uuid();
        DB::table('retired_asset_codes')->insert(['asset_uuid' => $uuid, 'former_asset_id' => 999, 'code' => 'EQ-RETIRED', 'retired_at' => now()]);
        $this->expectException(HttpException::class);
        Asset::create(['uuid' => (string) Str::uuid(), 'name' => 'Новый ПК', 'code' => 'EQ-RETIRED', 'category_id' => $category->id]);
    }
}
