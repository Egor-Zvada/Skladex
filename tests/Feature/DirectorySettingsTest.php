<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\DirectoryConfiguration;
use App\Models\User;
use App\Services\DirectoryGateway;
use App\Services\DirectorySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectorySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'Synthetic-config-admin-203!';

    private const BIND_PASSWORD = '  Synthetic-bind-password-203!  ';

    private const DOMAIN_GUID = '1234567890abcdef1234567890abcdef';

    private static ?string $certificate = null;

    private string $fixtureDirectory;

    private string $legacyFile;

    private User $admin;

    private DirectorySettingsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDirectory = sys_get_temp_dir().'/skladex-directory-settings-'.Str::uuid();
        mkdir($this->fixtureDirectory, 0700, true);
        $this->legacyFile = $this->fixtureDirectory.'/legacy.json';
        file_put_contents($this->fixtureDirectory.'/legacy-ca.pem', $this->certificate());
        chmod($this->fixtureDirectory.'/legacy-ca.pem', 0600);
        file_put_contents($this->legacyFile, json_encode([
            'enabled' => true,
            'hosts' => ['dc1.example.invalid', 'dc2.example.invalid'],
            'mode' => 'ldaps', 'port' => 636, 'timeout' => 5,
            'base_dn' => 'DC=example,DC=invalid',
            'bind_dn' => 'inventory-bind@example.invalid',
            'bind_password' => self::BIND_PASSWORD,
            'ca_file' => $this->fixtureDirectory.'/legacy-ca.pem',
            'netbios' => 'EXAMPLE',
            'groups' => [[
                'dn' => 'CN=Skladex Administrators,OU=Groups,DC=example,DC=invalid',
                'role' => 'admin', 'primary_group_id' => 1500,
                'domain_sid' => 'S-1-5-21-111-222-333',
            ]],
        ], JSON_THROW_ON_ERROR));
        chmod($this->legacyFile, 0600);
        config([
            'directory.enabled' => true,
            'directory.config_file' => $this->legacyFile,
            'directory.ca_cache_directory' => $this->fixtureDirectory.'/ca-cache',
        ]);
        $this->admin = User::factory()->create([
            'username' => 'directory-settings-admin', 'role' => 'admin',
            'is_active' => true, 'password' => self::ADMIN_PASSWORD,
        ]);
        $this->gateway = new DirectorySettingsGateway;
        $this->app->instance(DirectoryGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureDirectory) && is_dir($this->fixtureDirectory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->fixtureDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->fixtureDirectory);
        }
        parent::tearDown();
    }

    private function certificate(int $days = 2): string
    {
        if ($days !== 2 || self::$certificate === null) {
            $configuration = $this->fixtureDirectory.'/test-openssl.cnf';
            file_put_contents($configuration, "[req]\ndistinguished_name=dn\n[dn]\nCN=Skladex synthetic test CA\n[v3_ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
            $options = ['config' => $configuration, 'digest_alg' => 'sha256'];
            $key = openssl_pkey_new($options + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $request = openssl_csr_new(['commonName' => 'Skladex synthetic test CA'], $key, $options);
            $certificate = openssl_csr_sign($request, null, $key, $days, $options + ['x509_extensions' => 'v3_ca']);
            openssl_x509_export($certificate, $pem);
            if ($days === 2) {
                self::$certificate = $pem;
            }

            return $pem;
        }

        return self::$certificate;
    }

    private function input(array $changes = []): array
    {
        return array_replace([
            'expected_version' => app(DirectorySettingsService::class)->version(),
            'enabled' => true,
            'hosts' => "dc1.example.invalid\ndc2.example.invalid",
            'mode' => 'ldaps', 'port' => 636,
            'base_dn' => 'DC=example,DC=invalid',
            'bind_dn' => 'inventory-bind@example.invalid',
            'bind_password' => '', 'ca_pem' => '',
            'netbios' => 'EXAMPLE',
            'groups' => [[
                'dn' => 'CN=Skladex Administrators,OU=Groups,DC=example,DC=invalid',
                'role' => 'admin',
            ]],
            'current_password' => self::ADMIN_PASSWORD,
        ], $changes);
    }

    private function domainAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->auth_provider = 'ldap';
        $user->directory_guid = self::DOMAIN_GUID;
        $user->directory_checked_at = now();
        $user->save();

        return $user;
    }

    public function test_administrator_can_find_current_directory_settings_without_revealing_the_bind_secret(): void
    {
        $this->actingAs($this->admin)->get(route('settings.index'))
            ->assertOk()->assertSee(route('settings.directory.edit'), false);
        $response = $this->get(route('settings.directory.edit'));
        $response->assertOk()->assertSee('dc1.example.invalid')->assertSee('Skladex Administrators');
        $response->assertDontSee(self::BIND_PASSWORD)->assertDontSee(trim(self::BIND_PASSWORD));
        $form = app(DirectorySettingsService::class)->forForm();
        $this->assertTrue($form['has_password']);
        $this->assertArrayNotHasKey('bind_password', $form);
        $this->assertSame(0, app(DirectorySettingsService::class)->version());
        $this->assertSame(0, DirectoryConfiguration::count());
    }

    public function test_nonadministrators_cannot_read_change_or_probe_directory_settings(): void
    {
        foreach (['operator', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            $this->actingAs($user)->get(route('settings.index'))
                ->assertOk()->assertDontSee(route('settings.directory.edit'), false);
            $this->get(route('settings.directory.edit'))->assertForbidden();
            $this->postJson(route('settings.directory.update'), $this->input())->assertForbidden();
            $this->postJson(route('settings.directory.check'), $this->input())->assertForbidden();
        }
        $this->assertSame(0, $this->gateway->probeCalls);
        $this->assertSame(0, DirectoryConfiguration::count());
    }

    public function test_a_successful_enabled_save_encrypts_configuration_and_resolves_group_metadata(): void
    {
        $beforeLegacy = hash_file('sha256', $this->legacyFile);
        $this->actingAs($this->admin)->post(route('settings.directory.update'), $this->input())
            ->assertRedirect(route('settings.directory.edit'));
        $saved = DirectoryConfiguration::sole();
        $settings = app(DirectorySettingsService::class)->raw();
        $this->assertSame(1, $saved->version);
        $this->assertSame($this->admin->id, $saved->updated_by);
        $this->assertSame(['dc1.example.invalid', 'dc2.example.invalid'], $settings['hosts']);
        $this->assertSame(self::BIND_PASSWORD, $settings['bind_password']);
        $this->assertSame(1500, $settings['groups'][0]['primary_group_id']);
        $this->assertSame('S-1-5-21-111-222-333', $settings['groups'][0]['domain_sid']);
        $this->assertArrayNotHasKey('payload', $saved->toArray());
        $ciphertext = DB::table('directory_configurations')->value('payload');
        $this->assertStringNotContainsString(self::BIND_PASSWORD, $ciphertext);
        $this->assertStringNotContainsString('inventory-bind@example.invalid', $ciphertext);
        $this->assertStringNotContainsString(self::BIND_PASSWORD, AuditEvent::all()->toJson());
        $this->assertSame($beforeLegacy, hash_file('sha256', $this->legacyFile));
        $this->assertSame(1, $this->gateway->probeCalls);
    }

    public function test_empty_password_and_certificate_preserve_existing_values_but_a_new_password_keeps_its_spaces(): void
    {
        $this->actingAs($this->admin)->post(route('settings.directory.update'), $this->input(['enabled' => false]))->assertRedirect();
        $this->assertSame(self::BIND_PASSWORD, app(DirectorySettingsService::class)->raw()['bind_password']);
        $this->assertSame($this->certificate(), app(DirectorySettingsService::class)->raw()['ca_pem']);
        $newPassword = '  Synthetic-replacement-bind-secret-203!  ';
        $this->post(route('settings.directory.update'), $this->input(['enabled' => false, 'bind_password' => $newPassword]))->assertRedirect();
        $this->assertSame($newPassword, app(DirectorySettingsService::class)->raw()['bind_password']);
        $this->get(route('settings.directory.edit'))->assertOk()->assertDontSee($newPassword)->assertDontSee(trim($newPassword));
        $this->assertStringNotContainsString(trim($newPassword), AuditEvent::all()->toJson());
        $this->assertSame(0, $this->gateway->probeCalls);
    }

    public function test_validation_failure_never_flashes_either_submitted_password(): void
    {
        $secret = 'Synthetic-invalid-request-bind-secret-203!';
        $confirmation = 'Synthetic-invalid-confirmation-203!';
        $this->actingAs($this->admin)->from(route('settings.directory.edit'))
            ->post(route('settings.directory.update'), $this->input([
                'mode' => 'ldap', 'bind_password' => $secret, 'current_password' => $confirmation,
            ]))->assertSessionHasErrors();
        $oldInput = json_encode(session('_old_input', []));
        $this->assertStringNotContainsString($secret, $oldInput);
        $this->assertStringNotContainsString($confirmation, $oldInput);
        $this->get(route('settings.directory.edit'))->assertOk()->assertDontSee($secret)->assertDontSee($confirmation);
        $this->assertStringNotContainsString($secret, AuditEvent::all()->toJson());
        $this->assertSame(0, DirectoryConfiguration::count());
    }

    public function test_a_stale_configuration_form_cannot_overwrite_a_newer_configuration(): void
    {
        $stale = $this->input(['enabled' => false]);
        $this->actingAs($this->admin)->post(route('settings.directory.update'), $stale)->assertRedirect();
        $raw = app(DirectorySettingsService::class)->raw();
        $auditCount = AuditEvent::count();
        $this->postJson(route('settings.directory.update'), array_replace($stale, [
            'bind_password' => 'Synthetic-stale-secret-203!', 'netbios' => 'CHANGED',
        ]))->assertStatus(409);
        $this->assertSame($raw, app(DirectorySettingsService::class)->raw());
        $this->assertSame(1, app(DirectorySettingsService::class)->version());
        $this->assertSame($auditCount, AuditEvent::count());
    }

    public function test_saving_requires_the_current_local_administrator_password(): void
    {
        $this->actingAs($this->admin)->postJson(route('settings.directory.update'), $this->input([
            'enabled' => false, 'current_password' => 'Synthetic-wrong-password-203!',
        ]))->assertUnprocessable();
        $this->assertSame(0, DirectoryConfiguration::count());
        $this->assertSame(0, app(DirectorySettingsService::class)->version());
        $this->assertTrue(app(DirectorySettingsService::class)->enabled());
    }

    public function test_unsafe_transport_hosts_and_role_mapping_are_rejected_before_any_network_probe(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $invalid = [
            ['mode' => 'ldap'],
            ['mode' => 'plain'],
            ['hosts' => 'ldaps://dc1.example.invalid'],
            ['hosts' => 'dc1.example.invalid:636'],
            ['hosts' => 'dc1.example.invalid/path'],
            ['hosts' => "dc1.example.invalid\nhttps://other.invalid"],
            ['hosts' => ['dc1.example.invalid']],
            ['port' => 0],
            ['port' => 65536],
            ['groups' => []],
            ['groups' => [['dn' => 'CN=Role,DC=example,DC=invalid', 'role' => 'owner']]],
            ['groups' => [['dn' => "CN=Bad\0Group,DC=example,DC=invalid", 'role' => 'admin']]],
            ['ca_pem' => $this->certificate()."\n-----BEGIN PRIVATE KEY-----\ninvalid\n-----END PRIVATE KEY-----"],
        ];
        $this->actingAs($this->admin);
        foreach ($invalid as $change) {
            $this->postJson(route('settings.directory.update'), $this->input($change))->assertUnprocessable();
        }
        $this->assertSame(0, $this->gateway->probeCalls);
        $this->assertSame(0, DirectoryConfiguration::count());
    }

    public function test_a_failed_tls_or_group_probe_cannot_activate_or_persist_a_candidate(): void
    {
        $this->gateway->successful = false;
        $beforeLegacy = hash_file('sha256', $this->legacyFile);
        $this->actingAs($this->admin)->postJson(route('settings.directory.update'), $this->input([
            'hosts' => 'unavailable.example.invalid', 'bind_password' => 'Synthetic-uncommitted-secret-203!',
        ]))->assertUnprocessable();
        $this->assertSame(0, DirectoryConfiguration::count());
        $this->assertSame($beforeLegacy, hash_file('sha256', $this->legacyFile));
        $this->assertSame(self::BIND_PASSWORD, app(DirectorySettingsService::class)->raw()['bind_password']);
    }

    public function test_connection_check_uses_unsaved_candidate_without_changing_configuration_users_or_audit(): void
    {
        $beforeLegacy = hash_file('sha256', $this->legacyFile);
        $beforeUsers = User::orderBy('id')->get()->toJson();
        $beforeAudit = AuditEvent::count();
        $candidateSecret = 'Synthetic-read-only-probe-secret-203!';
        $response = $this->actingAs($this->admin)->postJson(route('settings.directory.check'), $this->input([
            'hosts' => 'candidate.example.invalid', 'bind_password' => $candidateSecret,
            'idempotency_key' => 'directory-probe-command-203',
        ]));
        $response->assertOk()->assertJsonPath('ok', true)->assertDontSee($candidateSecret);
        $this->assertSame(['candidate.example.invalid'], $this->gateway->lastCandidate['hosts']);
        $this->assertSame($candidateSecret, $this->gateway->lastCandidate['bind_password']);
        $this->assertSame($beforeLegacy, hash_file('sha256', $this->legacyFile));
        $this->assertSame($beforeUsers, User::orderBy('id')->get()->toJson());
        $this->assertSame($beforeAudit, AuditEvent::count());
        $this->assertSame(0, DirectoryConfiguration::count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(self::BIND_PASSWORD, app(DirectorySettingsService::class)->raw()['bind_password']);
    }

    public function test_a_domain_administrator_can_probe_only_saved_hosts_and_cannot_change_the_integration(): void
    {
        $domain = $this->domainAdmin();
        $this->actingAs($domain)->get(route('settings.directory.edit'))->assertOk();
        $this->postJson(route('settings.directory.check'), $this->input([
            'hosts' => 'attacker.example.invalid', 'bind_dn' => 'attacker@example.invalid',
            'bind_password' => 'Synthetic-injected-bind-secret-203!',
        ]))->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(['dc1.example.invalid', 'dc2.example.invalid'], $this->gateway->lastCandidate['hosts']);
        $this->assertSame(self::BIND_PASSWORD, $this->gateway->lastCandidate['bind_password']);
        $this->postJson(route('settings.directory.update'), $this->input(['enabled' => false]))->assertForbidden();
        $this->assertSame(0, DirectoryConfiguration::count());
    }

    public function test_reusing_the_saved_bind_secret_with_changed_hosts_requires_local_reauthentication(): void
    {
        $unconfirmed = $this->input(['hosts' => 'changed.example.invalid']);
        unset($unconfirmed['current_password']);
        $this->actingAs($this->admin)->postJson(route('settings.directory.check'), $unconfirmed)->assertUnprocessable();
        $this->assertSame(0, $this->gateway->probeCalls);
        $this->postJson(route('settings.directory.check'), array_replace($unconfirmed, [
            'current_password' => self::ADMIN_PASSWORD,
        ]))->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(['changed.example.invalid'], $this->gateway->lastCandidate['hosts']);
        $unchanged = $this->input();
        unset($unchanged['current_password']);
        $this->postJson(route('settings.directory.check'), $unchanged)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(2, $this->gateway->probeCalls);
        $this->assertSame(0, DirectoryConfiguration::count());
    }

    public function test_corrupt_legacy_group_settings_leave_an_accessible_recovery_form(): void
    {
        $legacy = json_decode(file_get_contents($this->legacyFile), true, 64, JSON_THROW_ON_ERROR);
        $legacy['groups'] = ['malformed', ['dn' => ['nested'], 'role' => 'owner']];
        file_put_contents($this->legacyFile, json_encode($legacy, JSON_THROW_ON_ERROR));
        $form = app(DirectorySettingsService::class)->forForm();
        $this->assertSame('invalid', $form['source']);
        $this->actingAs($this->admin)->get(route('settings.directory.edit'))->assertOk();
        $this->assertSame(0, DirectoryConfiguration::count());
        $this->assertSame(0, $this->gateway->probeCalls);
    }

    public function test_an_expired_legacy_ca_does_not_prevent_disabling_the_integration(): void
    {
        $expired = $this->certificate(0);
        $parsed = openssl_x509_parse($expired);
        $this->assertLessThanOrEqual(time(), $parsed['validTo_time_t']);
        file_put_contents($this->fixtureDirectory.'/legacy-ca.pem', $expired);
        $this->actingAs($this->admin)->post(route('settings.directory.update'), $this->input(['enabled' => false]))->assertRedirect();
        $this->assertFalse(app(DirectorySettingsService::class)->enabled());
        $this->assertSame($expired, app(DirectorySettingsService::class)->raw()['ca_pem']);
        $this->assertSame(0, $this->gateway->probeCalls);
    }

    public function test_disabling_in_ui_overrides_the_legacy_flag_and_revokes_only_domain_credentials(): void
    {
        $domain = $this->domainAdmin();
        $domainToken = $domain->createToken('Domain access before settings change', ['read'], now()->addDay())->accessToken;
        $localToken = $this->admin->createToken('Local fallback', ['read'], now()->addDay())->accessToken;
        DB::table('sessions')->insert([
            ['id' => 'domain-settings-before', 'user_id' => $domain->id, 'payload' => base64_encode('{}'), 'last_activity' => time()],
            ['id' => 'local-settings-before', 'user_id' => $this->admin->id, 'payload' => base64_encode('{}'), 'last_activity' => time()],
        ]);
        $this->actingAs($this->admin)->post(route('settings.directory.update'), $this->input(['enabled' => false]))->assertRedirect();
        $this->assertFalse(app(DirectorySettingsService::class)->enabled());
        $this->assertNull($domain->fresh()->directory_checked_at);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $domainToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'domain-settings-before']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $localToken->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'local-settings-before']);
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame('admin', $this->admin->fresh()->role);
        $this->assertSame(0, $this->gateway->probeCalls);
    }
}

class DirectorySettingsGateway extends DirectoryGateway
{
    public int $probeCalls = 0;

    public bool $successful = true;

    public ?array $lastCandidate = null;

    public function probeConfiguration(#[\SensitiveParameter] array $candidate): array
    {
        $this->probeCalls++;
        $this->lastCandidate = $candidate;

        return [
            'ok' => $this->successful,
            'controllers' => array_map(fn ($host) => ['host' => $host, 'ok' => $this->successful], $candidate['hosts']),
            'groups' => array_map(fn ($group) => array_replace($group, [
                'primary_group_id' => 1500, 'domain_sid' => 'S-1-5-21-111-222-333',
            ]), $candidate['groups']),
        ];
    }

    public function lookup(string $guid): ?array
    {
        return [
            'guid' => $guid, 'name' => 'Доменный администратор',
            'email' => 'domain-admin@example.invalid', 'login' => 'domain-admin', 'role' => 'admin',
        ];
    }
}
