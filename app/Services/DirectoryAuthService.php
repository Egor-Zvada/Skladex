<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DirectoryAuthService
{
    public function __construct(private DirectoryGateway $gateway) {}

    public function authenticate(string $identifier, #[\SensitiveParameter] string $password): ?User
    {
        if (! $this->gateway->enabled()) {
            return null;
        }
        $revision = $this->gateway->revision();
        $identity = $this->gateway->authenticate($identifier, $password);
        if (! $identity) {
            return null;
        }

        return DB::transaction(function () use ($identity, $revision): ?User {
            $this->mutex();
            if (! $this->gateway->enabled() || ! hash_equals($revision, $this->gateway->revision())) {
                throw new DirectoryUnavailable('Настройки доменного входа изменились. Повторите вход.');
            }
            $user = User::where('directory_guid', $identity['guid'])->lockForUpdate()->first();
            if ($user && (! $user->is_active || $user->auth_provider !== 'ldap')) {
                return null; // An administrator's local block is never auto-reversed.
            }
            if (! $user) {
                $email = $identity['email'] ?? '';
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || User::where('email', mb_strtolower($email))->exists()) {
                    $email = 'ad-'.$identity['guid'].'@directory.invalid';
                }
                $user = new User([
                    'name' => mb_substr($identity['name'], 0, 100), 'email' => mb_strtolower($email),
                    'username' => 'ad-'.$identity['guid'], 'password' => Str::random(80),
                    'role' => $identity['role'], 'is_active' => true,
                ]);
                $user->auth_provider = 'ldap';
                $user->directory_guid = $identity['guid'];
            } elseif ($user->role !== $identity['role']) {
                $user->role = $identity['role'];
                $this->revoke($user);
            }
            $user->name = mb_substr($identity['name'], 0, 100);
            $user->directory_checked_at = now();
            $user->save();
            Audit::record('directory.authenticated', $user, ['provider' => 'ldap', 'role' => $user->role]);

            return $user;
        }, 3);
    }

    public function ensureCurrent(User $user): void
    {
        if ($user->auth_provider !== 'ldap') {
            return;
        }
        abort_unless($user->is_active, 403, 'Доступ отключён.');
        if (! $this->gateway->enabled()) {
            abort(503, 'Доменный вход временно отключён.');
        }
        if ($user->directory_checked_at && $user->directory_checked_at->greaterThan(now()->subSeconds(60))) {
            return;
        }
        try {
            $revision = $this->gateway->revision();
            $identity = $this->gateway->lookup((string) $user->directory_guid);
        } catch (DirectoryUnavailable $e) {
            abort(503, 'Не удалось подтвердить доменный доступ. Попробуйте позже.');
        }
        DB::transaction(function () use ($user, $identity, $revision): void {
            $this->mutex();
            abort_unless($this->gateway->enabled() && hash_equals($revision, $this->gateway->revision()), 503, 'Настройки доменного входа изменились. Повторите запрос.');
            $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->is_active && $current->auth_provider === 'ldap', 403, 'Доступ отключён.');
            if (! $identity || $identity['guid'] !== $current->directory_guid) {
                $current->is_active = false;
                $this->revoke($current);
                $current->save();
                Audit::record('directory.access.revoked', $current, ['provider' => 'ldap']);
            } else {
                if ($current->role !== $identity['role']) {
                    $current->role = $identity['role'];
                    $this->revoke($current);
                }
                $current->directory_checked_at = now();
                $current->save();
            }
        }, 3);
        $user->refresh();
        abort_unless($user->is_active, 403, 'Доменная учётная запись отключена или исключена из разрешённых групп.');
    }

    private function mutex(): void
    {
        DB::table('code_counters')->insertOrIgnore(['prefix' => 'AUTH-SETTINGS', 'next_value' => 1]);
        DB::table('code_counters')->where('prefix', 'AUTH-SETTINGS')->lockForUpdate()->first();
    }

    private function revoke(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        $user->remember_token = Str::random(60);
    }
}
