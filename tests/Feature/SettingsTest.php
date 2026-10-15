<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Location;
use App\Models\QrAlias;
use App\Models\User;
use App\Services\QrResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.encrypt' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'password' => 'CurrentPassword-123']);
        $this->operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'password' => 'OperatorPassword-123']);
    }

    private function userInput(User $user, array $extra = []): array
    {
        return array_merge(['expected_state' => SettingsController::userRevision($user->fresh()), 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'is_active' => $user->is_active], $extra);
    }

    private function fixtures(): array
    {
        $category = Category::create(['name' => 'Тест IT', 'slug' => 'settings-test', 'domain' => 'it', 'kind' => 'standard', 'maintenance_policy' => 'unspecified']);
        $a = Asset::create(['code' => 'SKX-A-SETTINGS-1', 'name' => 'Первый', 'category_id' => $category->id, 'status' => 'stock', 'condition' => 'unknown', 'version' => 1]);
        $b = Asset::create(['code' => 'SKX-A-SETTINGS-2', 'name' => 'Второй', 'category_id' => $category->id, 'status' => 'stock', 'condition' => 'unknown', 'version' => 1]);
        $room = Location::create(['code' => 'SKX-R-SETTINGS-1', 'name' => 'Кабинет']);

        return [$a, $b, $room];
    }

    public function test_operator_can_open_profile_and_change_own_password_but_cannot_manage(): void
    {
        $this->actingAs($this->operator)->get(route('settings.index'))->assertOk()->assertSee('Мой профиль')->assertDontSee('Создать пользователя')->assertDontSee('Создать API-токен');
        $this->post(route('settings.users.store'), [])->assertForbidden();
        $this->post(route('settings.tokens.store'), [])->assertForbidden();
        $this->post(route('settings.aliases.store'), [])->assertForbidden();
        $this->post(route('settings.password'), ['current_password' => 'OperatorPassword-123', 'password' => 'NewOperatorPassword-123', 'password_confirmation' => 'NewOperatorPassword-123'])->assertRedirect(route('settings.index'));
        $this->assertTrue(Hash::check('NewOperatorPassword-123', $this->operator->fresh()->password));
    }

    public function test_wrong_current_password_and_short_new_password_do_not_change_hash(): void
    {
        $old = $this->operator->password;
        $this->actingAs($this->operator)->post(route('settings.password'), ['current_password' => 'WrongPassword', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors(['current_password', 'password']);
        $this->assertSame($old, $this->operator->fresh()->password);
    }

    public function test_admin_creates_user_with_hash_and_never_audits_password(): void
    {
        $this->actingAs($this->admin)->post(route('settings.users.store'), ['name' => 'Новый сотрудник', 'email' => 'NEW@example.test', 'password' => 'LongNewPassword-123', 'password_confirmation' => 'LongNewPassword-123', 'role' => 'viewer', 'is_active' => 1])->assertRedirect(route('settings.index'));
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('LongNewPassword-123', $user->password));
        $audit = AuditEvent::where('event', 'settings.user.created')->firstOrFail();
        $this->assertStringNotContainsString('LongNewPassword-123', json_encode($audit->data));
        $this->assertStringNotContainsString($user->password, json_encode($audit->data));
    }

    public function test_admin_cannot_disable_or_demote_self_even_if_another_admin_exists(): void
    {
        User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->admin)->post(route('settings.users.update', $this->admin->id), $this->userInput($this->admin, ['is_active' => false]))->assertStatus(409);
        $this->post(route('settings.users.update', $this->admin->id), $this->userInput($this->admin, ['role' => 'operator']))->assertStatus(409);
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_last_active_admin_is_preserved(): void
    {
        $this->actingAs($this->admin)->post(route('settings.users.update', $this->admin->id), $this->userInput($this->admin, ['role' => 'viewer', 'is_active' => false]))->assertStatus(409);
        $this->assertSame(1, User::where('role', 'admin')->where('is_active', true)->count());
    }

    public function test_stale_user_form_is_rejected_even_with_same_updated_at(): void
    {
        $input = $this->userInput($this->operator);
        DB::table('users')->where('id', $this->operator->id)->update(['role' => 'viewer']);
        $this->actingAs($this->admin)->post(route('settings.users.update', $this->operator->id), $input)->assertStatus(409);
        $this->assertSame('viewer', $this->operator->fresh()->role);
    }

    public function test_disabling_user_revokes_tokens_and_reactivation_does_not_restore_them(): void
    {
        $this->operator->createToken('Previous', ['read'], now()->addDay());
        $this->actingAs($this->admin)->post(route('settings.users.update', $this->operator->id), $this->userInput($this->operator, ['is_active' => false]))->assertRedirect();
        $this->assertFalse($this->operator->fresh()->is_active);
        $this->assertSame(0, $this->operator->tokens()->count());
        $this->post(route('settings.users.update', $this->operator->id), $this->userInput($this->operator->fresh(), ['is_active' => true]))->assertRedirect();
        $this->assertSame(0, $this->operator->tokens()->count());
    }

    public function test_password_change_revokes_existing_api_token_and_does_not_log_credentials(): void
    {
        $issued = $this->admin->createToken('Old', ['read'], now()->addDay());
        $this->actingAs($this->admin)->post(route('settings.password'), ['current_password' => 'CurrentPassword-123', 'password' => 'DifferentPassword-123', 'password_confirmation' => 'DifferentPassword-123'])->assertRedirect();
        $this->assertSame(0, $this->admin->tokens()->count());
        $audit = AuditEvent::where('event', 'settings.password.changed')->firstOrFail();
        $serialized = json_encode($audit->data);
        $this->assertStringNotContainsString('DifferentPassword-123', $serialized);
        $this->assertStringNotContainsString('CurrentPassword-123', $serialized);
        $this->assertStringNotContainsString($issued->plainTextToken, $serialized);
    }

    public function test_token_is_scoped_expiring_hashed_and_revealed_only_once(): void
    {
        $this->actingAs($this->admin)->post(route('settings.tokens.store'), ['name' => 'Report reader', 'abilities' => ['read'], 'expires_in_days' => 30])->assertRedirect(route('settings.index'));
        $plain = session('created_api_token');
        $this->assertIsString($plain);
        $token = $this->admin->tokens()->firstOrFail();
        $this->assertSame(['read'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertNotSame($plain, $token->token);
        $this->get(route('settings.index'))->assertOk()->assertSee($plain);
        $this->get(route('settings.index'))->assertOk()->assertDontSee($plain);
        $audit = AuditEvent::where('event', 'settings.api-token.created')->firstOrFail();
        $this->assertStringNotContainsString($plain, json_encode($audit->data));
        $this->assertStringNotContainsString($token->token, json_encode($audit->data));
    }

    public function test_token_does_not_allow_wildcard_unknown_scopes_or_long_expiry(): void
    {
        $this->actingAs($this->admin)->post(route('settings.tokens.store'), ['name' => 'Too broad', 'abilities' => ['read', '*'], 'expires_in_days' => 366])->assertSessionHasErrors(['abilities.1', 'expires_in_days']);
        $this->post(route('settings.tokens.store'), ['name' => 'Unsupported', 'abilities' => ['manage-users'], 'expires_in_days' => 30])->assertSessionHasErrors(['abilities.0']);
        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_plain_token_is_not_created_when_session_encryption_is_disabled(): void
    {
        config(['session.encrypt' => false]);
        $this->actingAs($this->admin)->post(route('settings.tokens.store'), ['name' => 'Reader', 'abilities' => ['read'], 'expires_in_days' => 30])->assertStatus(503);
        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_admin_revokes_own_token_but_cannot_revoke_another_admins_token(): void
    {
        $own = $this->admin->createToken('Own', ['read'], now()->addDay())->accessToken;
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $foreign = $other->createToken('Other', ['read'], now()->addDay())->accessToken;
        $this->actingAs($this->admin)->post(route('settings.tokens.revoke', $foreign->id))->assertNotFound();
        $this->post(route('settings.tokens.revoke', $own->id))->assertRedirect();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $own->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);
    }

    public function test_legacy_eq_code_is_explicitly_mapped_not_derived_from_numeric_id(): void
    {
        [$a,$b] = $this->fixtures();
        $this->actingAs($this->admin)->post(route('settings.aliases.store'), ['kind' => 'asset-code', 'alias' => 'EQ-000001', 'target_type' => 'asset', 'target_uuid' => $b->uuid])->assertRedirect();
        $this->assertDatabaseHas('qr_aliases', ['alias' => 'asset-code:EQ-000001', 'target_id' => $b->id, 'target_type' => 'asset']);
        $this->assertSame($b->id, app(QrResolver::class)->resolve('EQ-000001')?->id);
    }

    public function test_alias_is_never_reassigned_and_same_target_repeat_is_safe(): void
    {
        [$a,$b] = $this->fixtures();
        $data = ['kind' => 'asset-code', 'alias' => 'EQ-000250', 'target_type' => 'asset', 'target_uuid' => $a->uuid];
        $this->actingAs($this->admin)->post(route('settings.aliases.store'), $data)->assertRedirect();
        $this->post(route('settings.aliases.store'), $data)->assertRedirect();
        $this->post(route('settings.aliases.store'), array_merge($data, ['target_uuid' => $b->uuid]))->assertStatus(409);
        $this->assertSame(1, QrAlias::count());
        $this->assertSame($a->id, QrAlias::first()->target_id);
    }

    public function test_alias_cannot_shadow_an_actual_code_of_another_target_or_wrong_type(): void
    {
        [$a,$b,$room] = $this->fixtures();
        $this->actingAs($this->admin)->post(route('settings.aliases.store'), ['kind' => 'asset-code', 'alias' => $a->code, 'target_type' => 'asset', 'target_uuid' => $b->uuid])->assertStatus(409);
        $this->post(route('settings.aliases.store'), ['kind' => 'location-id', 'alias' => $a->code, 'target_type' => 'location', 'target_uuid' => $room->uuid])->assertStatus(409);
        $this->post(route('settings.aliases.store'), ['kind' => 'asset-code', 'alias' => 'EQ-9', 'target_type' => 'location', 'target_uuid' => $room->uuid])->assertSessionHasErrors('target_type');
        $this->assertSame(0, QrAlias::count());
    }

    public function test_unicode_location_ids_are_preserved_without_latin_cyrillic_substitution(): void
    {
        [,, $room] = $this->fixtures();
        $other = Location::create(['code' => 'SKX-R-SETTINGS-2', 'name' => 'Другой кабинет']);
        $this->actingAs($this->admin)->post(route('settings.aliases.store'), ['kind' => 'location-id', 'alias' => 'Сe-Cab-009', 'target_type' => 'location', 'target_uuid' => $room->uuid])->assertRedirect();
        $this->post(route('settings.aliases.store'), ['kind' => 'location-id', 'alias' => 'Ce-Cab-009', 'target_type' => 'location', 'target_uuid' => $other->uuid])->assertRedirect();
        $this->assertSame(2, QrAlias::count());
        $this->assertSame($room->id, app(QrResolver::class)->resolve('Сe-Cab-009')?->id);
        $this->assertSame($other->id, app(QrResolver::class)->resolve('Ce-Cab-009')?->id);
    }

    public function test_alias_controls_and_overlength_input_are_rejected(): void
    {
        [$a] = $this->fixtures();
        $this->actingAs($this->admin)->post(route('settings.aliases.store'), ['kind' => 'asset-code', 'alias' => 'EQ-'.chr(0).'123', 'target_type' => 'asset', 'target_uuid' => $a->uuid])->assertSessionHasErrors('alias');
        $this->post(route('settings.aliases.store'),['kind' => 'asset-code', 'alias' => str_repeat('x',201), 'target_type' => 'asset', 'target_uuid' => $a->uuid])->assertSessionHasErrors('alias');
        $this->assertSame(0,QrAlias::count());
    }
}
