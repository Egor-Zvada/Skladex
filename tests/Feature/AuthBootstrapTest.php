<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const SETUP_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const PASSWORD = 'Bootstrap-Password-203!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['skladex.setup_token_hash' => hash('sha256', self::SETUP_TOKEN)]);
        // No globally pre-created account may bypass the actual bootstrap flow.
        $this->assertDatabaseCount('users', 0);
    }

    #[DataProvider('invalidSetupTokens')]
    public function test_missing_non_string_and_incorrect_keys_cannot_create_an_account(mixed $token, bool $present): void
    {
        $payload = $this->payload();
        if ($present) {
            $payload['setup_token'] = $token;
        } else {
            unset($payload['setup_token']);
        }

        $this->post(route('setup.store'), $payload)->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(0, AuditEvent::where('event', 'setup.completed')->count());
    }

    public static function invalidSetupTokens(): array
    {
        return [
            'missing' => [null, false],
            'null' => [null, true],
            'array' => [['unexpected' => 'value'], true],
            'incorrect' => [str_repeat('b', 64), true],
        ];
    }

    public function test_an_unconfigured_setup_key_cannot_open_bootstrap(): void
    {
        config(['skladex.setup_token_hash' => null]);
        $this->post(route('setup.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_setup_page_does_not_reflect_a_legacy_query_key_into_html(): void
    {
        $response = $this->get(route('setup', ['token' => self::SETUP_TOKEN]));
        $response->assertOk()->assertDontSee(self::SETUP_TOKEN, false);
        $html = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $html->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $fields = (new \DOMXPath($html))->query('//input[@name="setup_token"]');
        $this->assertSame(1, $fields->length);
        $field = $fields->item(0);
        $this->assertSame('password', $field->getAttribute('type'));
        $this->assertTrue($field->hasAttribute('data-setup-token'));
        $this->assertSame('', $field->getAttribute('value'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_valid_key_does_not_bypass_account_validation(): void
    {
        $payload = array_replace($this->payload(), ['email' => 'invalid-email', 'password' => 'short', 'password_confirmation' => 'short']);
        $this->postJson(route('setup.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_valid_key_creates_exactly_one_hashed_active_administrator_and_logs_in(): void
    {
        $session = $this->app['session']->driver();
        $session->start();
        $previousId = $session->getId();
        $response = $this->withCookie($session->getName(), $previousId)->post(route('setup.store'), $this->payload());
        $response->assertRedirect(route('dashboard'))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseCount('users', 1);
        $admin = User::sole();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertSame('bootstrap@example.invalid', $admin->email);
        $this->assertNotSame(self::PASSWORD, $admin->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->getRawOriginal('password')));
        $this->assertAuthenticatedAs($admin);
        $response->assertSessionHas(Auth::guard()->getName(), $admin->getAuthIdentifier());
        $this->assertNotSame($previousId, $session->getId());
        $event = AuditEvent::where('event', 'setup.completed')->sole();
        $this->assertStringNotContainsString(self::SETUP_TOKEN, json_encode($event->getAttributes()));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($event->getAttributes()));
    }

    public function test_valid_key_does_not_allow_a_second_bootstrap_after_logout(): void
    {
        $this->post(route('setup.store'), $this->payload())->assertRedirect(route('dashboard'));
        $firstId = User::sole()->id;
        $this->post(route('logout'))->assertRedirect(route('public.home'));
        $this->assertGuest();
        $this->get(route('setup'))->assertNotFound();
        $payload = array_replace($this->payload(), ['name' => 'Second administrator', 'email' => 'second@example.invalid']);
        $this->post(route('setup.store'), $payload)->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($firstId, User::sole()->id);
        $this->assertSame(1, AuditEvent::where('event', 'setup.completed')->count());
        $this->assertGuest();
    }

    public function test_inactive_account_cannot_log_in_with_the_correct_password(): void
    {
        User::create(['name' => 'Inactive operator', 'email' => 'inactive@example.invalid', 'password' => self::PASSWORD, 'role' => 'operator', 'is_active' => false]);
        $this->from(route('login'))->post(route('login.store'), ['email' => 'inactive@example.invalid', 'password' => self::PASSWORD])
            ->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(0, AuditEvent::where('event', 'auth.login')->count());
    }

    private function payload(): array
    {
        return [
            'name' => 'Первый администратор', 'email' => 'BOOTSTRAP@example.invalid',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'setup_token' => self::SETUP_TOKEN,
        ];
    }

    public function test_username_login_accepts_an_existing_user_password_without_registration_rules(): void
    {
        $admin = User::factory()->create(['username' => 'admin', 'email' => 'admin@example.invalid', 'password' => 'Test123!', 'role' => 'admin', 'is_active' => true]);
        $this->post(route('login.store'), ['email' => 'ADMIN', 'password' => 'Test123!'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => 'ADMIN@EXAMPLE.INVALID', 'password' => 'Test123!'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_username_does_not_bypass_password_or_active_flag(): void
    {
        User::factory()->create(['username' => 'admin', 'password' => 'Test123!', 'role' => 'admin', 'is_active' => false]);
        $this->postJson(route('login.store'), ['email' => 'admin', 'password' => 'Test123!'])->assertUnprocessable();
        $this->assertGuest();
        User::where('username', 'admin')->update(['is_active' => true]);
        $this->postJson(route('login.store'), ['email' => 'admin', 'password' => 'Wrong123!'])->assertUnprocessable();
        $this->assertGuest();
    }
}
