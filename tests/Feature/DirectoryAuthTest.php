<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\DirectoryAuthService;
use App\Services\DirectoryGateway;
use App\Services\DirectoryUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class DirectoryAuthTest extends TestCase
{
    use RefreshDatabase;

    private const GUID = '00112233445566778899aabbccddeeff';

    private FakeDirectoryGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeDirectoryGateway;
        $this->app->instance(DirectoryGateway::class, $this->gateway);
    }

    private function identity(array $changes = []): array
    {
        return array_replace([
            'guid' => self::GUID, 'name' => 'Доменный сотрудник', 'email' => 'directory.user@example.invalid',
            'login' => 'directory.user', 'role' => 'operator',
        ], $changes);
    }

    private function domainUser(array $changes = []): User
    {
        $user = User::factory()->create(array_replace([
            'name' => 'Доменный сотрудник', 'username' => 'ad-'.self::GUID,
            'email' => 'directory.user@example.invalid', 'role' => 'operator', 'is_active' => true,
        ], $changes));
        $user->auth_provider = 'ldap';
        $user->directory_guid = self::GUID;
        $user->directory_checked_at = now()->subMinutes(2);
        $user->save();

        return $user;
    }

    private function sessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => base64_encode('{}'), 'last_activity' => time()]);
    }

    public function test_first_domain_login_creates_a_separate_guid_identity_without_storing_the_domain_password(): void
    {
        $this->gateway->authIdentity = $this->identity();
        $password = 'Synthetic-domain-password-203!';
        $this->post(route('login.store'), ['email' => 'VVE\\Directory.User', 'password' => $password])->assertRedirect(route('dashboard'));
        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ldap', $user->auth_provider);
        $this->assertSame(self::GUID, $user->directory_guid);
        $this->assertSame('ad-'.self::GUID, $user->username);
        $this->assertSame('operator', $user->role);
        $this->assertSame('vve\\directory.user', $this->gateway->lastIdentifier);
        $this->assertFalse(Hash::check($password, $user->password));
        $this->assertArrayNotHasKey('directory_guid', $user->toArray());
        $this->assertStringNotContainsString($password, AuditEvent::all()->toJson());
        $this->assertSame(1, AuditEvent::where('event', 'directory.authenticated')->count());
    }

    public function test_guid_remains_the_identity_after_directory_login_and_email_change(): void
    {
        $user = $this->domainUser();
        $token = $user->createToken('Old role', ['read', 'write'], now()->addDay());
        $remember = $user->remember_token;
        $this->sessionFor($user, 'old-directory-login');
        $this->gateway->authIdentity = $this->identity(['login' => 'renamed.user', 'email' => 'renamed@example.invalid', 'name' => 'Новое имя', 'role' => 'viewer']);
        $authenticated = app(DirectoryAuthService::class)->authenticate('renamed.user', 'Synthetic-new-login-203!');
        $this->assertSame($user->id, $authenticated->id);
        $this->assertSame(1, User::count());
        $this->assertSame('viewer', $user->fresh()->role);
        $this->assertSame('Новое имя', $user->fresh()->name);
        $this->assertSame('directory.user@example.invalid', $user->fresh()->email);
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-directory-login']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_directory_email_collision_never_links_or_modifies_the_local_administrator(): void
    {
        $local = User::factory()->create(['username' => 'admin', 'email' => 'collision@example.invalid', 'password' => 'Local-admin-only-203!', 'role' => 'admin', 'is_active' => true]);
        $before = $local->fresh()->getAttributes();
        $this->gateway->authIdentity = $this->identity(['email' => $local->email]);
        $this->post(route('login.store'), ['email' => 'directory.user', 'password' => 'Synthetic-domain-password-203!'])->assertRedirect(route('dashboard'));
        $domain = User::where('directory_guid', self::GUID)->sole();
        $this->assertNotSame($local->id, $domain->id);
        $this->assertSame('ad-'.self::GUID.'@directory.invalid', $domain->email);
        $this->assertSame($before, $local->fresh()->getAttributes());
        $this->assertAuthenticatedAs($domain);
    }

    public function test_a_local_login_collision_does_not_fall_through_to_directory_authentication(): void
    {
        User::factory()->create(['username' => 'admin', 'password' => 'Local-admin-only-203!', 'role' => 'admin', 'is_active' => true]);
        $this->gateway->authIdentity = $this->identity(['login' => 'admin', 'role' => 'admin']);
        $this->postJson(route('login.store'), ['email' => 'ADMIN', 'password' => 'Synthetic-domain-password-203!'])->assertUnprocessable();
        $this->assertGuest();
        $this->assertSame(0, $this->gateway->authCalls);
        $this->assertSame(1, User::count());
    }

    public function test_local_administrator_still_logs_in_when_the_directory_is_unavailable(): void
    {
        $local = User::factory()->create(['username' => 'admin', 'password' => 'Local-admin-only-203!', 'role' => 'admin', 'is_active' => true]);
        $this->gateway->unavailable = true;
        $this->post(route('login.store'), ['email' => 'admin', 'password' => 'Local-admin-only-203!'])->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($local);
        $this->assertSame(0, $this->gateway->authCalls);
        $this->assertSame(0, $this->gateway->lookupCalls);
    }

    public function test_a_locally_blocked_domain_user_is_never_automatically_reenabled(): void
    {
        $user = $this->domainUser(['is_active' => false]);
        $this->gateway->authIdentity = $this->identity(['role' => 'admin']);
        $this->postJson(route('login.store'), ['email' => 'directory.user', 'password' => 'Synthetic-domain-password-203!'])->assertUnprocessable();
        $this->assertGuest();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame('operator', $user->fresh()->role);
        $this->assertSame(1, User::count());
    }

    public function test_a_local_identity_with_a_directory_guid_is_not_converted_to_a_domain_account(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->directory_guid = self::GUID;
        $user->save();
        $this->gateway->authIdentity = $this->identity();
        $this->assertNull(app(DirectoryAuthService::class)->authenticate('directory.user', 'Synthetic-domain-password-203!'));
        $this->assertSame('local', $user->fresh()->auth_provider);
        $this->assertSame('admin', $user->fresh()->role);
    }

    public function test_a_domain_user_cannot_use_its_database_password_as_a_local_password(): void
    {
        $user = $this->domainUser(['password' => 'Synthetic-known-local-hash-203!']);
        $this->gateway->authIdentity = null;
        $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'Synthetic-known-local-hash-203!'])->assertUnprocessable();
        $this->assertGuest();
        $this->assertSame(1, $this->gateway->authCalls);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_group_removal_commits_deactivation_and_revokes_sessions_tokens_and_remember_cookie(): void
    {
        $user = $this->domainUser();
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $remember = $user->remember_token;
        $token = $user->createToken('Previous domain access', ['read'], now()->addDay());
        $this->sessionFor($user, 'revoked-domain-session');
        $this->sessionFor($other, 'preserved-local-session');
        $this->gateway->lookupIdentity = null;
        try {
            app(DirectoryAuthService::class)->ensureCurrent($user);
            $this->fail('Removed domain membership must deny access.');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertFalse($user->fresh()->is_active);
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'revoked-domain-session']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'preserved-local-session']);
        $this->assertSame(1, AuditEvent::where('event', 'directory.access.revoked')->count());
    }

    public function test_directory_outage_denies_an_expired_authorization_without_deactivating_the_user(): void
    {
        $user = $this->domainUser();
        $before = $user->directory_checked_at;
        $token = $user->createToken('Temporarily inaccessible', ['read'], now()->addDay());
        $this->gateway->unavailable = true;
        $this->actingAs($user)->get(route('dashboard'))->assertStatus(503);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertTrue($before->equalTo($user->fresh()->directory_checked_at));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertSame(0, AuditEvent::where('event', 'directory.access.revoked')->count());
    }

    public function test_refresh_cannot_switch_an_existing_account_to_a_different_guid(): void
    {
        $user = $this->domainUser();
        $this->gateway->lookupIdentity = $this->identity(['guid' => str_repeat('f', 32), 'role' => 'admin']);
        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(self::GUID, $user->fresh()->directory_guid);
        $this->assertSame('operator', $user->fresh()->role);
        $this->assertSame(1, User::count());
    }

    public function test_directory_authorization_is_rechecked_after_sixty_seconds(): void
    {
        $user = $this->domainUser();
        $user->directory_checked_at = now();
        $user->save();
        $this->gateway->lookupIdentity = $this->identity(['role' => 'viewer']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertSame(0, $this->gateway->lookupCalls);
        $this->travel(61)->seconds();
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $this->gateway->lookupCalls);
        $this->assertSame('viewer', $user->fresh()->role);
        $this->assertSame(self::GUID, $this->gateway->lastGuid);
        $this->travelBack();
    }

    public function test_role_change_revokes_the_current_bearer_token_before_its_request_can_proceed(): void
    {
        $user = $this->domainUser(['role' => 'admin']);
        $issued = $user->createToken('Previous admin', ['read', 'write'], now()->addDay());
        $this->gateway->lookupIdentity = $this->identity(['role' => 'viewer']);
        Auth::forgetGuards();
        $this->withToken($issued->plainTextToken)->getJson(route('api.assets.index'))->assertForbidden();
        $this->assertSame('viewer', $user->fresh()->role);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $issued->accessToken->id]);
    }

    public function test_domain_membership_does_not_bypass_api_token_scopes(): void
    {
        $user = $this->domainUser(['role' => 'admin']);
        $user->directory_checked_at = now();
        $user->save();
        $issued = $user->createToken('No read scope', ['write'], now()->addDay());
        Auth::forgetGuards();
        $this->withToken($issued->plainTextToken)->getJson(route('api.assets.index'))->assertForbidden();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $issued->accessToken->id]);
        $this->assertSame(0, $this->gateway->lookupCalls);
    }

    public function test_empty_password_is_rejected_before_any_directory_bind_is_attempted(): void
    {
        $this->postJson(route('login.store'), ['email' => 'directory.user', 'password' => ''])->assertUnprocessable();
        $this->assertSame(0, $this->gateway->authCalls);
        $this->assertSame(0, User::count());
        // The real adapter also rejects empty/NUL credentials before reading its configuration.
        $real = new DirectoryGateway;
        $this->assertNull($real->authenticate('directory.user', ''));
        $this->assertNull($real->authenticate('directory.user', "bad\0password"));
    }

    public function test_account_expiration_uses_integer_windows_filetime_and_never_expire_sentinels(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 00:00:00.123456', 'UTC'));
        $ticks = (now()->getTimestamp() + 11644473600) * 10000000 + 1234560;
        $method = (new \ReflectionClass(DirectoryGateway::class))->getMethod('accountExpired');
        $adapter = new DirectoryGateway;
        foreach ([null, 0, '0', '0000', '9223372036854775807', (string) ($ticks + 1), (string) ($ticks + 36000000000)] as $value) {
            $this->assertFalse($method->invoke($adapter, $value), 'A future or unlimited account must remain usable.');
        }
        foreach ([(string) $ticks, (string) ($ticks - 1), '1', '9223372036854775808', '-1', '', '1e18', []] as $value) {
            $this->assertTrue($method->invoke($adapter, $value), 'An expired or malformed expiry must fail closed.');
        }
        $this->travelBack();
    }

    public function test_domain_account_profile_cannot_change_its_password_locally(): void
    {
        $user = $this->domainUser(['password' => 'Synthetic-current-password-203!']);
        $user->directory_checked_at = now();
        $user->save();
        $before = $user->password;
        $this->actingAs($user)->post(route('settings.password'), [
            'current_password' => 'Synthetic-current-password-203!',
            'password' => 'Synthetic-new-password-203!', 'password_confirmation' => 'Synthetic-new-password-203!',
        ])->assertUnprocessable();
        $this->assertSame($before, $user->fresh()->password);
        $this->assertSame(0, AuditEvent::where('event', 'settings.password.changed')->count());
    }

    public function test_domain_identity_and_role_are_read_only_but_local_administrator_can_block_access(): void
    {
        $local = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $domain = $this->domainUser();
        $payload = [
            'expected_state' => SettingsController::userRevision($domain->fresh()),
            'name' => $domain->name, 'username' => $domain->username, 'email' => $domain->email,
            'role' => $domain->role, 'is_active' => true,
        ];
        $this->actingAs($local);
        foreach (['name' => 'Чужое имя', 'username' => 'renamed.user', 'email' => 'renamed@example.invalid', 'role' => 'admin'] as $field => $value) {
            $this->postJson(route('settings.users.update', $domain->id), array_replace($payload, [$field => $value]))->assertUnprocessable();
        }
        $issued = $domain->createToken('Domain before local block', ['read'], now()->addDay());
        $this->post(route('settings.users.update', $domain->id), array_replace($payload, ['is_active' => false]))->assertRedirect(route('settings.index'));
        $this->assertFalse($domain->fresh()->is_active);
        $this->assertSame('operator', $domain->fresh()->role);
        $this->assertSame($domain->email, $domain->fresh()->email);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $issued->accessToken->id]);
    }

    public function test_domain_administrators_cannot_disable_the_last_local_fallback_administrator(): void
    {
        $local = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $domain = $this->domainUser(['role' => 'admin']);
        $domain->directory_checked_at = now();
        $domain->save();
        $this->actingAs($domain)->post(route('settings.users.update', $local->id), [
            'expected_state' => SettingsController::userRevision($local->fresh()),
            'name' => $local->name, 'username' => $local->username, 'email' => $local->email,
            'role' => 'viewer', 'is_active' => false,
        ])->assertStatus(409);
        $this->assertTrue($local->fresh()->is_active);
        $this->assertSame('admin', $local->fresh()->role);
    }
}

class FakeDirectoryGateway extends DirectoryGateway
{
    public bool $unavailable = false;

    public ?array $authIdentity = null;

    public ?array $lookupIdentity = null;

    public int $authCalls = 0;

    public int $lookupCalls = 0;

    public ?string $lastIdentifier = null;

    public ?string $lastGuid = null;

    public function enabled(): bool
    {
        return true;
    }

    public function authenticate(string $identifier, #[\SensitiveParameter] string $password): ?array
    {
        $this->authCalls++;
        $this->lastIdentifier = $identifier;
        if ($this->unavailable) {
            throw new DirectoryUnavailable('Synthetic directory outage.');
        }

        return $this->authIdentity;
    }

    public function lookup(string $guid): ?array
    {
        $this->lookupCalls++;
        $this->lastGuid = $guid;
        if ($this->unavailable) {
            throw new DirectoryUnavailable('Synthetic directory outage.');
        }

        return $this->lookupIdentity;
    }
}
