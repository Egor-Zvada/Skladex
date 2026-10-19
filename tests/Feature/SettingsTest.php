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
use Illuminate\Support\Str;
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
        return array_merge(['expected_state' => SettingsController::userRevision($user->fresh()), 'name' => $user->name, 'username' => $user->username, 'email' => $user->email, 'role' => $user->role, 'is_active' => $user->is_active], $extra);
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
        $this->assertNull($user->username);
        $this->assertTrue(Hash::check('LongNewPassword-123', $user->password));
        $audit = AuditEvent::where('event', 'settings.user.created')->firstOrFail();
        $this->assertStringNotContainsString('LongNewPassword-123', json_encode($audit->data));
        $this->assertStringNotContainsString($user->password, json_encode($audit->data));
    }

    public function test_optional_username_is_saved_shown_and_can_be_cleared(): void
    {
        $this->actingAs($this->admin)->post(route('settings.users.store'), ['name' => 'Новый сотрудник', 'username' => 'new.user-1', 'email' => 'named@example.test', 'password' => 'LongNewPassword-123', 'password_confirmation' => 'LongNewPassword-123', 'role' => 'viewer', 'is_active' => 1])->assertRedirect(route('settings.index'));
        $user = User::where('email', 'named@example.test')->firstOrFail();
        $this->assertSame('new.user-1', $user->username);
        $this->get(route('settings.index'))->assertOk()->assertSee('new.user-1');
        $this->actingAs($user)->get(route('settings.index'))->assertOk()->assertSee('new.user-1')->assertDontSee('Создать пользователя');
        $this->actingAs($this->admin)->post(route('settings.users.update', $user->id), $this->userInput($user, ['username' => '']))->assertRedirect(route('settings.index'));
        $this->assertNull($user->fresh()->username);
    }

    public function test_invalid_or_duplicate_usernames_do_not_create_or_rename_users(): void
    {
        $occupied = User::factory()->create(['username' => 'reserved.user']);
        $input = ['name' => 'Новый сотрудник', 'email' => 'invalid@example.test', 'password' => 'LongNewPassword-123', 'password_confirmation' => 'LongNewPassword-123', 'role' => 'viewer', 'is_active' => 1];
        $this->actingAs($this->admin);
        foreach (['ab', 'Admin', 'имя', 'with space', 'name@example.test', str_repeat('a', 65), $occupied->username] as $username) {
            $this->postJson(route('settings.users.store'), array_merge($input, ['username' => $username]))->assertUnprocessable()->assertJsonValidationErrors('username');
        }
        $this->postJson(route('settings.users.update', $this->operator->id), $this->userInput($this->operator, ['username' => $occupied->username]))->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertNull($this->operator->fresh()->username);
        $this->assertSame(3, User::count());
    }

    public function test_unchanged_username_is_allowed_and_omission_preserves_existing_login(): void
    {
        $this->operator->update(['username' => 'operator.one']);
        $this->operator->createToken('Existing reader', ['read'], now()->addDay());
        $this->actingAs($this->admin)->post(route('settings.users.update', $this->operator->id), $this->userInput($this->operator))->assertRedirect(route('settings.index'));
        $this->operator = $this->operator->fresh();
        $input = $this->userInput($this->operator, ['name' => 'Уточнённое имя']);
        unset($input['username']);
        $this->post(route('settings.users.update', $this->operator->id), $input)->assertRedirect(route('settings.index'));
        $this->assertSame('operator.one', $this->operator->fresh()->username);
        $this->assertSame(1, $this->operator->tokens()->count());
    }

    public function test_username_and_email_changes_revoke_user_sessions_and_tokens(): void
    {
        config(['session.driver' => 'database']);
        $this->operator->update(['username' => 'operator.one']);
        DB::table('sessions')->insert(['id' => 'unrelated-admin-session', 'user_id' => $this->admin->id, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
        $this->actingAs($this->admin);
        foreach (['username' => 'operator.renamed', 'email' => 'renamed@example.test'] as $field => $value) {
            $this->operator = $this->operator->fresh();
            $remember = $this->operator->remember_token;
            $this->operator->createToken('Previous '.$field, ['read'], now()->addDay());
            $sessionId = 'operator-before-'.$field;
            DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $this->operator->id, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
            $this->post(route('settings.users.update', $this->operator->id), $this->userInput($this->operator, [$field => $value]))->assertRedirect(route('settings.index'));
            $this->assertSame($value, $this->operator->fresh()->getAttribute($field));
            $this->assertSame(0, $this->operator->tokens()->count());
            $this->assertNotSame($remember, $this->operator->fresh()->remember_token);
            $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
            $this->assertDatabaseHas('sessions', ['id' => 'unrelated-admin-session']);
        }
    }

    public function test_username_change_invalidates_old_form_even_without_timestamp_change(): void
    {
        $input = $this->userInput($this->operator);
        $this->operator->createToken('Must remain after rejected stale command', ['read'], now()->addDay());
        DB::table('users')->where('id', $this->operator->id)->update(['username' => 'changed.elsewhere']);
        $this->actingAs($this->admin)->post(route('settings.users.update', $this->operator->id), $input)->assertStatus(409);
        $this->assertSame('changed.elsewhere', $this->operator->fresh()->username);
        $this->assertSame(1, $this->operator->tokens()->count());
    }

    public function test_changing_own_username_also_ends_the_current_admin_session(): void
    {
        $this->admin->createToken('Previous', ['read'], now()->addDay());
        $this->actingAs($this->admin)->post(route('settings.users.update', $this->admin->id), $this->userInput($this->admin, ['username' => 'admin.renamed']))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame('admin.renamed', $this->admin->fresh()->username);
        $this->assertSame(0, $this->admin->tokens()->count());
    }

    public function test_normal_user_creation_still_rejects_an_eight_character_password(): void
    {
        $this->actingAs($this->admin)->postJson(route('settings.users.store'), ['name' => 'Новый сотрудник', 'username' => 'regular.user', 'email' => 'short@example.test', 'password' => 'Eight123', 'password_confirmation' => 'Eight123', 'role' => 'viewer', 'is_active' => 1])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'short@example.test']);
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

    public function test_token_html_form_accepts_string_expiry_and_replay_neither_reissues_nor_reveals_secret(): void
    {
        $this->freezeTime();
        // A browser submits number inputs as strings, unlike the integer PHP fixtures above.
        $input = ['name' => 'Browser report reader', 'abilities' => ['read'], 'expires_in_days' => '30', 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($this->admin)->post(route('settings.tokens.store'), $input)->assertRedirect(route('settings.index'));
        $plain = session('created_api_token');
        $this->assertIsString($plain);
        $token = $this->admin->tokens()->sole();
        $this->assertSame(['read'], $token->abilities);
        $this->assertSame(now()->addDays(30)->toDateTimeString(), $token->expires_at->toDateTimeString());
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $token->token);
        $command = DB::table('idempotency_keys')->where('user_id', $this->admin->id)->where('key', $input['idempotency_key'])->sole();
        $this->assertSame('complete', $command->status);
        $this->assertStringNotContainsString($plain, $command->response_body);
        $this->assertStringNotContainsString($token->token, $command->response_body);

        $this->get(route('settings.index'))->assertOk()->assertSee($plain);
        $this->assertNull(session('created_api_token'));
        $this->post(route('settings.tokens.store'), $input)->assertRedirect(route('settings.index'))->assertSessionMissing('created_api_token');
        $this->get(route('settings.index'))->assertOk()->assertDontSee($plain);
        $this->assertSame(1, $this->admin->tokens()->count());
        $this->assertSame(1, AuditEvent::where('event', 'settings.api-token.created')->count());

        $this->post(route('settings.tokens.revoke', $token->id), ['idempotency_key' => (string) Str::uuid()])->assertRedirect(route('settings.index'));
        $this->assertSame(0, $this->admin->tokens()->count());
        $this->post(route('settings.tokens.store'), $input)->assertRedirect(route('settings.index'))->assertSessionMissing('created_api_token');
        $this->assertSame(0, $this->admin->tokens()->count());
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
        $this->post(route('settings.aliases.store'), ['kind' => 'asset-code', 'alias' => str_repeat('x', 201), 'target_type' => 'asset', 'target_uuid' => $a->uuid])->assertSessionHasErrors('alias');
        $this->assertSame(0, QrAlias::count());
    }
}
