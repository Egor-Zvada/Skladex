<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\LicenseAssignment;
use App\Models\LicenseInstance;
use App\Models\LicenseSecret;
use App\Models\SoftwareInstallation;
use App\Models\SoftwareProduct;
use App\Models\SoftwareRelease;
use App\Models\User;
use App\Support\LicenseKeyCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SoftwareLicenseTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private SoftwareProduct $product;

    protected function beforeRefreshingDatabase(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Software and license locking/constraints require MariaDB.');
        }
        if (! preg_match('/(?:^|_)(?:tests?|testing)(?:_|$)/i', DB::connection()->getDatabaseName())) {
            throw new RuntimeException('SoftwareLicenseTest requires a dedicated *_test or *_testing database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['skladex.license_master_key' => 'base64:'.base64_encode(random_bytes(32)), 'skladex.license_key_id' => 'test-v1']);
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $this->product = SoftwareProduct::create(['name' => 'Тестовый сервис', 'kind' => 'in_house']);
        $this->apiAs($this->operator);
    }

    public function test_quick_license_create_encrypts_key_and_never_serializes_it(): void
    {
        $key = 'TEST-SECRET-ONLY-ENCRYPTED';
        $asset = $this->asset();
        $response = $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'license_key' => $key, 'asset_id' => $asset->id, 'capacity' => 2]);
        $response->assertCreated()->assertJsonPath('license.code', 'SKX-L-000001');
        $license = LicenseInstance::firstOrFail();
        $secret = LicenseSecret::firstOrFail();
        $this->assertNotSame($key, $secret->ciphertext);
        $this->assertSame($key, app(LicenseKeyCipher::class)->decrypt($secret->ciphertext, $secret->key_id));
        $this->assertSame(1, $license->activeAssignments()->count());
        $this->getJson(route('api.licenses.show', $license))->assertOk()->assertDontSee($key)->assertDontSee($secret->ciphertext)->assertJsonPath('license.secret_exists', true);
        $this->getJson(route('api.licenses.index'))->assertOk()->assertDontSee($key)->assertDontSee('ciphertext')->assertDontSee('key_id');
        $this->assertStringNotContainsString($key, json_encode(AuditEvent::all()));
        $this->assertStringNotContainsString($key, json_encode(DB::table('idempotency_keys')->get()));
    }

    public function test_unknown_capacity_and_user_metric_are_explicit_manual_warnings(): void
    {
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'metric' => 'user', 'asset_id' => $this->asset()->id])
            ->assertCreated()->assertJsonCount(2, 'warnings');
        $this->assertNull(LicenseInstance::firstOrFail()->capacity);
    }

    public function test_shared_key_is_allowed_for_independent_license_instances(): void
    {
        $data = ['software_product_id' => $this->product->id, 'license_key' => 'SHARED-VOLUME-KEY', 'capacity' => 5];
        $this->postCommand('api.licenses.store', [], $data)->assertCreated();
        $this->postCommand('api.licenses.store', [], $data)->assertCreated();
        $this->assertSame(2, LicenseInstance::count());
        $this->assertSame(2, LicenseSecret::count());
    }

    public function test_non_device_metrics_never_claim_or_enforce_seats_from_equipment_links(): void
    {
        foreach (['user', 'instance', 'unknown'] as $metric) {
            $response = $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'metric' => $metric, 'capacity' => 1, 'asset_id' => $this->asset()->id]);
            $response->assertCreated()->assertJsonPath('usage.remaining', null)->assertJsonPath('usage.needs_review', true);
            $license = LicenseInstance::where('uuid', $response->json('license.uuid'))->firstOrFail();
            $this->postCommand('api.licenses.assign', $license, ['expected_version' => 1, 'asset_id' => $this->asset()->id])->assertOk()->assertJsonPath('usage.equipment_links', 2)->assertJsonPath('usage.remaining', null);
        }
    }

    public function test_capacity_duplicate_assignment_stale_version_and_history_are_guarded(): void
    {
        $license = LicenseInstance::create(['code' => 'TEST-CAPACITY', 'software_product_id' => $this->product->id, 'capacity' => 1]);
        $first = $this->asset();
        $second = $this->asset();
        $this->postCommand('api.licenses.assign', $license, ['expected_version' => 1, 'asset_id' => $first->id])->assertOk()->assertJsonPath('license.version', 2);
        $this->postCommand('api.licenses.assign', $license, ['expected_version' => 1, 'asset_id' => $second->id])->assertConflict();
        $this->postCommand('api.licenses.assign', $license, ['expected_version' => 2, 'asset_id' => $second->id])->assertUnprocessable();
        $this->postCommand('api.licenses.assign', $license, ['expected_version' => 2, 'asset_id' => $first->id])->assertUnprocessable();
        $this->assertSame(2, $license->fresh()->version);
        $assignment = $license->assignments()->firstOrFail();
        $this->postCommand('api.licenses.release', [$license, $assignment], ['expected_version' => 2])->assertOk()->assertJsonPath('license.version', 3);
        $this->postCommand('api.licenses.assign', $license, ['expected_version' => 3, 'asset_id' => $first->id])->assertOk();
        $this->assertSame(2, $license->assignments()->count());
        $this->assertSame(1, $license->activeAssignments()->count());
        $this->assertNotNull($assignment->fresh()->released_at);
    }

    public function test_capacity_cannot_be_lowered_below_active_assignments(): void
    {
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'asset_id' => $this->asset()->id, 'capacity' => 2])->assertCreated();
        $license = LicenseInstance::firstOrFail();
        $this->patchJson(route('api.licenses.update', $license), ['expected_version' => 1, 'capacity' => 0], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(2, $license->fresh()->capacity);
        $this->assertSame(1, $license->fresh()->version);
    }

    public function test_failed_initial_assignment_rolls_back_license_key_counter_and_audit(): void
    {
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'license_key' => 'NEVER-PERSIST-ON-ROLLBACK', 'asset_id' => $this->asset()->id, 'capacity' => 0])->assertUnprocessable();
        $this->assertSame(0, LicenseInstance::count());
        $this->assertSame(0, LicenseSecret::count());
        $this->assertSame(0, LicenseAssignment::count());
        $this->assertDatabaseMissing('code_counters', ['prefix' => 'SKX-L']);
        $this->assertSame(0, AuditEvent::count());
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    public function test_missing_cipher_configuration_rolls_back_and_returns_safe_error(): void
    {
        config(['skladex.license_master_key' => '']);
        $key = 'DONT-FLASH-THIS-KEY';
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'license_key' => $key])->assertUnprocessable()->assertDontSee($key);
        $this->assertDatabaseCount('license_instances', 0);
        $this->assertDatabaseCount('license_secrets', 0);
    }

    public function test_web_validation_never_flashes_activation_key(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->operator)->from(route('licenses.index'))->post(route('licenses.store'), ['license_key' => 'SECRET-WEB-INVALID', 'name' => 'Пометка'])
            ->assertRedirect(route('licenses.index'))->assertSessionHasErrors('software_product_id')->assertSessionMissing('_old_input.license_key')->assertSessionHas('_old_input.name', 'Пометка');
        $this->assertStringNotContainsString('SECRET-WEB-INVALID', json_encode(session()->all()));
    }

    public function test_reveal_requires_admin_and_scoped_token_and_is_never_idempotency_cached(): void
    {
        $key = 'ADMIN-REVEAL-ONLY';
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'license_key' => $key])->assertCreated();
        $license = LicenseInstance::firstOrFail();
        $this->postCommand('api.licenses.reveal', $license, ['expected_version' => 1])->assertForbidden()->assertDontSee($key);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->apiAs($admin, ['read', 'write']);
        $this->postCommand('api.licenses.reveal', $license, ['expected_version' => 1])->assertForbidden();
        $this->apiAs($admin, ['read', 'reveal-license']);
        $rows = DB::table('idempotency_keys')->count();
        $response = $this->postJson(route('api.licenses.reveal', $license), ['expected_version' => 1]);
        $response->assertOk()->assertJsonPath('license_key', $key)->assertHeader('Pragma', 'no-cache');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($rows, DB::table('idempotency_keys')->count());
        $this->assertSame(1, AuditEvent::where('event', 'license.key.revealed')->count());
        $this->assertStringNotContainsString($key, json_encode(AuditEvent::all()));
    }

    public function test_idempotent_retry_returns_same_result_and_payload_mismatch_is_conflict(): void
    {
        $key = (string) Str::uuid();
        $input = ['software_product_id' => $this->product->id, 'asset_id' => $this->asset()->id, 'capacity' => 1];
        $first = $this->postCommand('api.licenses.store', [], $input, $key)->assertCreated();
        $retry = $this->postCommand('api.licenses.store', [], $input, $key)->assertCreated();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertDatabaseCount('license_instances', 1);
        $this->assertDatabaseCount('license_assignments', 1);
        $this->postCommand('api.licenses.store', [], $input + ['name' => 'Другой запрос'], $key)->assertConflict();
        $this->assertDatabaseCount('license_instances', 1);
    }

    public function test_viewer_and_read_only_operator_token_cannot_write(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);
        $this->apiAs($viewer);
        $this->getJson(route('api.software.index'))->assertOk();
        $this->postCommand('api.software.store', [], ['name' => 'Запрещено', 'kind' => 'third_party'])->assertForbidden();
        $this->apiAs($this->operator, ['read']);
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id])->assertForbidden();
        $this->assertDatabaseCount('license_instances', 0);
    }

    public function test_release_ownership_and_installation_target_are_checked_and_verification_is_explicit(): void
    {
        $other = SoftwareProduct::create(['name' => 'Другая программа']);
        $foreignRelease = SoftwareRelease::create(['software_product_id' => $other->id, 'version_name' => '1.0']);
        $this->postCommand('api.software.installations.store', $this->product, ['expected_version' => 1, 'software_release_id' => $foreignRelease->id, 'environment' => 'vm: test'])->assertUnprocessable();
        $this->postCommand('api.software.installations.store', $this->product, ['expected_version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('software_installations', 0);
        $this->assertSame(1, $this->product->fresh()->version);
        $this->postCommand('api.software.releases.store', $this->product, ['expected_version' => 1, 'version_name' => '2.0'])->assertCreated();
        $release = $this->product->releases()->firstOrFail();
        $this->postCommand('api.software.installations.store', $this->product, ['expected_version' => 2, 'software_release_id' => $release->id, 'environment' => 'container: web', 'verified_now' => true])->assertCreated();
        $installation = SoftwareInstallation::firstOrFail();
        $this->assertNotNull($installation->last_verified_at);
        $this->patchJson(route('api.software.installations.update', [$this->product, $installation]), ['expected_version' => 1, 'software_release_id' => null, 'environment' => 'container: next'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertNull($installation->fresh()->last_verified_at);
        $this->assertNull($installation->fresh()->software_release_id);
        $this->assertSame(2, $installation->fresh()->version);
    }

    public function test_product_update_checks_version_and_in_house_repository_is_internal_only(): void
    {
        $input = ['expected_version' => 1, 'name' => 'Свой сервис', 'kind' => 'in_house', 'owner_id' => $this->operator->id, 'repository_url' => 'https://example.test/team/app'];
        $this->patchJson(route('api.software.update', $this->product), $input, ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->patchJson(route('api.software.update', $this->product), $input, ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->getJson(route('api.software.show', $this->product))->assertOk()->assertJsonPath('repository_url', $input['repository_url']);
        $this->assertSame(2, $this->product->fresh()->version);
    }

    public function test_optional_key_can_be_added_later_but_operator_cannot_replace_existing_key(): void
    {
        $license = LicenseInstance::create(['code' => 'TEST-LATER', 'software_product_id' => $this->product->id]);
        $this->postCommand('api.licenses.key.store', $license, ['expected_version' => 1, 'license_key' => 'FIRST-KEY'])->assertOk();
        $this->postCommand('api.licenses.key.store', $license, ['expected_version' => 2, 'license_key' => 'REPLACEMENT'])->assertForbidden();
        $secret = $license->secret()->firstOrFail();
        $this->assertSame('FIRST-KEY', app(LicenseKeyCipher::class)->decrypt($secret->ciphertext, $secret->key_id));
        $this->assertSame(2, $license->fresh()->version);
    }

    public function test_assignment_cannot_release_another_licenses_row(): void
    {
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'asset_id' => $this->asset()->id])->assertCreated();
        $assignment = LicenseAssignment::firstOrFail();
        $other = LicenseInstance::create(['code' => 'TEST-OTHER', 'software_product_id' => $this->product->id]);
        $this->postCommand('api.licenses.release', [$other, $assignment], ['expected_version' => 1])->assertNotFound();
        $this->assertNull($assignment->fresh()->released_at);
        $this->assertSame(1, $other->fresh()->version);
    }

    public function test_internal_blade_pages_render_without_prefilling_or_exposing_key(): void
    {
        $this->postCommand('api.licenses.store', [], ['software_product_id' => $this->product->id, 'license_key' => 'NEVER-IN-ORDINARY-HTML', 'asset_id' => $this->asset()->id])->assertCreated();
        $license = LicenseInstance::firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->operator);
        foreach (['software.index' => [], 'software.show' => $this->product, 'licenses.index' => [], 'licenses.show' => $license] as $name => $parameters) {
            $this->get(route($name, $parameters))->assertOk()->assertDontSee('NEVER-IN-ORDINARY-HTML');
        }
    }

    private function apiAs(User $user, array $abilities = ['read', 'write', 'reveal-license']): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('catalog-tests', $abilities)->plainTextToken);
    }

    private function postCommand(string $route, mixed $parameters, array $input, ?string $key = null)
    {
        return $this->postJson(route($route, $parameters), $input, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function asset(): Asset
    {
        $category = Category::firstOrCreate(['slug' => 'test-computers'], ['name' => 'Тестовые ПК', 'domain' => 'it', 'kind' => 'computer', 'maintenance_policy' => 'unspecified']);

        return Asset::create(['code' => 'TEST-'.Str::uuid(), 'name' => 'Тестовый ПК', 'category_id' => $category->id]);
    }
}
