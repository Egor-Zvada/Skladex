<?php

namespace App\Services;

use App\Models\DirectoryConfiguration;
use App\Models\User;
use App\Support\Access;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DirectorySettingsService
{
    private function defaults(): array
    {
        return ['enabled' => false, 'hosts' => [], 'mode' => 'ldaps', 'port' => 636, 'base_dn' => '', 'bind_dn' => '', 'bind_password' => '', 'netbios' => '', 'ca_pem' => '', 'groups' => [], 'timeout' => 5];
    }

    public function raw(): array
    {
        $record = DirectoryConfiguration::find(1);
        if ($record) {
            try {
                $payload = $record->payload;
                if (! is_array($payload)) {
                    throw new \RuntimeException;
                }

                return $this->structured($payload);
            } catch (\Throwable $e) {
                throw new DirectoryUnavailable('Сохранённые настройки доменного входа недоступны.');
            }
        }
        $path = config('directory.config_file');
        if (! is_string($path) || ! is_readable($path)) {
            return $this->defaults();
        }
        try {
            $payload = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new \RuntimeException;
            }
            $payload['enabled'] = (bool) config('directory.enabled') && (bool) ($payload['enabled'] ?? false);
            $ca = $payload['ca_file'] ?? null;
            $payload['ca_pem'] = is_string($ca) && is_readable($ca) ? file_get_contents($ca) : '';

            return $this->structured($payload);
        } catch (\Throwable $e) {
            throw new DirectoryUnavailable('Исходные настройки доменного входа недоступны.');
        }
    }

    public function enabled(): bool
    {
        try {
            return (bool) $this->raw()['enabled'];
        } catch (DirectoryUnavailable $e) {
            // A broken directory configuration must not hide the local admin login.
            return false;
        }
    }

    private function structured(#[\SensitiveParameter] array $payload): array
    {
        $raw = array_replace($this->defaults(), $payload);
        if (! is_bool($raw['enabled']) || ! is_int($raw['port']) || ! is_array($raw['hosts']) || ! is_array($raw['groups'])) {
            throw new DirectoryUnavailable;
        }
        foreach (['mode', 'base_dn', 'bind_dn', 'bind_password', 'netbios', 'ca_pem'] as $field) {
            if (! is_string($raw[$field])) {
                throw new DirectoryUnavailable;
            }
        }
        foreach ($raw['hosts'] as $host) {
            if (! is_string($host)) {
                throw new DirectoryUnavailable;
            }
        }
        foreach ($raw['groups'] as $group) {
            if (! is_array($group) || ! is_string($group['dn'] ?? null) || ! in_array($group['role'] ?? null, ['viewer', 'operator', 'admin'], true)) {
                throw new DirectoryUnavailable;
            }
        }

        return $raw;
    }

    public function version(): int
    {
        return (int) (DirectoryConfiguration::whereKey(1)->value('version') ?? 0);
    }

    public function revision(): string
    {
        $record = DirectoryConfiguration::find(1);
        if ($record) {
            $value = [$record->version, $record->getRawOriginal('payload')];
        } else {
            try {
                $value = [0, $this->raw()];
            } catch (DirectoryUnavailable $e) {
                $path = config('directory.config_file');
                $value = [0, is_string($path) && is_readable($path) ? hash_file('sha256', $path) : null];
            }
        }

        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function forForm(): array
    {
        try {
            $raw = $this->raw();
            if (! is_array($raw['hosts']) || ! is_array($raw['groups']) || ! is_string($raw['mode'])
                || ! is_string($raw['base_dn']) || ! is_string($raw['bind_dn']) || ! is_string($raw['netbios']) || ! is_string($raw['ca_pem'])) {
                throw new DirectoryUnavailable;
            }
            foreach ($raw['hosts'] as $host) {
                if (! is_string($host)) {
                    throw new DirectoryUnavailable;
                }
            }
            foreach ($raw['groups'] as $group) {
                if (! is_array($group) || ! is_string($group['dn'] ?? null) || ! in_array($group['role'] ?? null, ['viewer', 'operator', 'admin'], true)) {
                    throw new DirectoryUnavailable;
                }
            }
            $source = $this->version() ? 'database' : (is_readable((string) config('directory.config_file')) ? 'file' : 'empty');
        } catch (DirectoryUnavailable $e) {
            $raw = $this->defaults();
            $source = 'invalid';
        }

        return [
            'enabled' => (bool) $raw['enabled'], 'hosts' => $raw['hosts'], 'mode' => $raw['mode'], 'port' => $raw['port'],
            'base_dn' => $raw['base_dn'], 'bind_dn' => $raw['bind_dn'], 'netbios' => $raw['netbios'], 'ca_pem' => $raw['ca_pem'],
            'groups' => array_map(fn ($group) => ['dn' => $group['dn'], 'role' => $group['role']], $raw['groups']),
            'has_password' => is_string($raw['bind_password']) && $raw['bind_password'] !== '',
            'version' => $this->version(), 'source' => $source,
        ];
    }

    public function current(): array
    {
        try {
            return $this->materialize($this->raw());
        } catch (ValidationException $e) {
            throw new DirectoryUnavailable('Проверьте доверенный CA доменного входа.');
        }
    }

    /** Internal settings only: never serialize the return value into responses or logs. */
    public function candidate(#[\SensitiveParameter] array $input): array
    {
        $data = Validator::make($input, [
            'enabled' => 'required|boolean', 'hosts' => 'required|string|max:1000',
            'mode' => ['required', Rule::in(['ldaps', 'starttls'])], 'port' => 'required|integer|min:1|max:65535',
            'base_dn' => 'required|string|max:2048|not_regex:/[\p{Cc}\p{Cf}]/u',
            'bind_dn' => 'required|string|max:2048|not_regex:/[\p{Cc}\p{Cf}]/u',
            'bind_password' => 'nullable|string|max:1024|not_regex:/\x00/',
            'netbios' => 'required|string|max:15|regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{0,14}\z/',
            'ca_pem' => 'nullable|string|max:65536',
            'groups' => 'required|array|min:1|max:20', 'groups.*' => 'array:dn,role',
            'groups.*.dn' => 'required|string|max:2048|distinct:ignore_case|not_regex:/[\p{Cc}\p{Cf}]/u',
            'groups.*.role' => ['required', Rule::in(['viewer', 'operator', 'admin'])],
        ])->validate();
        $hosts = array_values(array_filter(array_map(fn ($host) => strtolower(trim($host)), preg_split('/\R/u', $data['hosts']))));
        if (count($hosts) < 1 || count($hosts) > 3 || count(array_unique($hosts)) !== count($hosts)) {
            throw ValidationException::withMessages(['hosts' => 'Укажите от одного до трёх различных контроллеров, по одному в строке.']);
        }
        foreach ($hosts as $host) {
            if (! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host)) {
                throw ValidationException::withMessages(['hosts' => 'Нужны DNS-имена или IPv4 без протокола, порта и пути.']);
            }
        }
        foreach (['base_dn' => $data['base_dn']] + array_combine(array_map(fn ($key) => 'groups.'.$key.'.dn', array_keys($data['groups'])), array_column($data['groups'], 'dn')) as $field => $dn) {
            if (! function_exists('ldap_explode_dn') || @ldap_explode_dn($dn, 0) === false) {
                throw ValidationException::withMessages([$field => 'Укажите полный DN, например CN=Skladex-Admins,OU=Groups,DC=example,DC=local.']);
            }
        }
        try {
            $previous = $this->raw();
        } catch (DirectoryUnavailable $e) {
            $previous = $this->defaults();
        }
        $password = ($data['bind_password'] ?? '') !== '' ? $data['bind_password'] : $previous['bind_password'];
        $providedCa = str_replace(["\r\n", "\r"], "\n", trim($data['ca_pem'] ?? ''));
        $ca = $providedCa !== '' ? $providedCa."\n" : $previous['ca_pem'];
        if ($ca !== '' && ($providedCa !== '' || (bool) $data['enabled'])) {
            $this->validateCa($ca);
        }
        if ((bool) $data['enabled'] && $data['bind_dn'] !== $previous['bind_dn'] && ($data['bind_password'] ?? '') === '') {
            throw ValidationException::withMessages(['bind_password' => 'При смене служебной учётной записи введите её пароль.']);
        }
        if ((bool) $data['enabled'] && ($password === '' || $ca === '')) {
            throw ValidationException::withMessages(['bind_password' => 'Для включения нужны служебный пароль и доверенный CA.']);
        }

        return [
            'enabled' => (bool) $data['enabled'], 'hosts' => $hosts, 'mode' => $data['mode'], 'port' => (int) $data['port'],
            'base_dn' => trim($data['base_dn']), 'bind_dn' => trim($data['bind_dn']), 'bind_password' => $password,
            'netbios' => strtoupper($data['netbios']), 'groups' => array_values($data['groups']), 'ca_pem' => $ca, 'timeout' => 5,
        ];
    }

    public function probe(User $actor, #[\SensitiveParameter] array $input): array
    {
        Access::check($actor, 'configure');
        $candidate = $actor->auth_provider === 'local' ? $this->candidate($input) : $this->raw();
        if ($actor->auth_provider === 'local' && (! is_string($input['bind_password'] ?? null) || $input['bind_password'] === '')) {
            try {
                $saved = $this->raw();
            } catch (DirectoryUnavailable $e) {
                $saved = $this->defaults();
            }
            $changedTrust = false;
            foreach (['hosts', 'mode', 'port', 'base_dn', 'bind_dn', 'ca_pem'] as $key) {
                $before = $saved[$key] ?? null;
                $after = $candidate[$key] ?? null;
                if ($key === 'ca_pem') {
                    $before = str_replace(["\r\n", "\r"], "\n", trim((string) $before));
                    $after = str_replace(["\r\n", "\r"], "\n", trim((string) $after));
                }
                $changedTrust = $changedTrust || $before !== $after;
            }
            if ($changedTrust) {
                $fresh = User::findOrFail($actor->id);
                Access::check($fresh, 'configure');
                $password = $input['current_password'] ?? null;
                if ($fresh->auth_provider !== 'local' || ! is_string($password) || strlen($password) > 1024 || ! Hash::check($password, $fresh->getAuthPassword())) {
                    throw ValidationException::withMessages(['current_password' => 'Для проверки нового подключения с сохранённым служебным паролем подтвердите свой текущий пароль.']);
                }
            }
        }
        if (($candidate['bind_password'] ?? '') === '' || ($candidate['ca_pem'] ?? '') === '') {
            throw ValidationException::withMessages(['bind_password' => 'Для проверки нужны служебный пароль и доверенный CA.']);
        }

        return app(DirectoryGateway::class)->probeConfiguration($this->materialize($candidate));
    }

    public function save(User $actor, #[\SensitiveParameter] array $input): DirectoryConfiguration
    {
        Access::check($actor, 'configure');
        abort_unless($actor->auth_provider === 'local', 403, 'Подключение AD изменяет локальный администратор.');
        $validation = Validator::make($input, ['expected_version' => 'required|integer|min:0', 'current_password' => 'required|string|max:1024'])->validate();
        abort_unless($this->version() === (int) $validation['expected_version'], 409, 'Настройки изменились. Обновите страницу.');
        if (! Hash::check($validation['current_password'], $actor->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => 'Текущий пароль не подходит.']);
        }
        $revision = $this->revision();
        $candidate = $this->candidate($input);
        if ($candidate['enabled']) {
            $checked = app(DirectoryGateway::class)->probeConfiguration($this->materialize($candidate));
            if (! ($checked['ok'] ?? false)) {
                throw ValidationException::withMessages(['hosts' => 'Подключение или разрешённые группы не прошли проверку. Настройки не сохранены.']);
            }
            $candidate['groups'] = $checked['groups'];
        }

        return DB::transaction(function () use ($actor, $validation, $candidate, $revision) {
            DB::table('code_counters')->insertOrIgnore(['prefix' => 'AUTH-SETTINGS', 'next_value' => 1]);
            DB::table('code_counters')->where('prefix', 'AUTH-SETTINGS')->lockForUpdate()->first();
            $fresh = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            Access::check($fresh, 'configure');
            abort_unless($fresh->auth_provider === 'local', 403);
            if (! Hash::check($validation['current_password'], $fresh->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'Пароль уже изменился.']);
            }
            abort_unless($this->version() === (int) $validation['expected_version'] && hash_equals($revision, $this->revision()), 409, 'Настройки изменились. Обновите страницу и проверьте актуальную конфигурацию.');
            $record = DirectoryConfiguration::firstOrNew(['id' => 1]);
            $record->payload = $candidate;
            $record->version = ($record->version ?? 0) + 1;
            $record->updated_by = $fresh->id;
            $record->save();
            $users = User::where('auth_provider', 'ldap')->orderBy('id')->lockForUpdate()->get();
            foreach ($users as $user) {
                $user->directory_checked_at = null;
                $user->remember_token = Str::random(60);
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
            }
            Audit::record('directory.settings.updated', $record, ['version' => $record->version, 'enabled' => $candidate['enabled'], 'group_count' => count($candidate['groups']), 'revoked_users' => $users->count()]);

            return $record;
        }, 3);
    }

    private function validateCa(#[\SensitiveParameter] string $pem): void
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----\s*[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----/', $pem, $matches);
        $remaining = str_replace($matches[0], '', $pem);
        if (! $matches[0] || count($matches[0]) > 10 || trim($remaining) !== '') {
            throw ValidationException::withMessages(['ca_pem' => 'Нужен PEM только с сертификатами CA, без приватных ключей.']);
        }
        foreach ($matches[0] as $certificate) {
            $parsed = @openssl_x509_parse($certificate);
            if (! $parsed || ! str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
                || ($parsed['validTo_time_t'] ?? 0) <= time() || ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > time()) {
                throw ValidationException::withMessages(['ca_pem' => 'Один из сертификатов не является действующим сертификатом CA.']);
            }
        }
    }

    private function materialize(#[\SensitiveParameter] array $settings): array
    {
        $pem = $settings['ca_pem'] ?? '';
        if (! is_string($pem) || $pem === '') {
            throw new DirectoryUnavailable('Доверенный CA не настроен.');
        }
        $this->validateCa($pem);
        $directory = config('directory.ca_cache_directory', storage_path('app/private/directory-ca'));
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new DirectoryUnavailable('Доверенный CA недоступен.');
        }
        if (is_link($directory)) {
            throw new DirectoryUnavailable('Некорректное хранилище CA.');
        }
        $path = $directory.'/'.hash('sha256', $pem).'.pem';
        if (! file_exists($path)) {
            $temporary = @tempnam($directory, '.ca-');
            if ($temporary === false) {
                throw new DirectoryUnavailable('Доверенный CA недоступен.');
            }
            try {
                if (@file_put_contents($temporary, $pem, LOCK_EX) !== strlen($pem) || ! @chmod($temporary, 0600) || ! @rename($temporary, $path)) {
                    throw new DirectoryUnavailable('Не удалось подготовить доверенный CA.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        if (is_link($path) || ! is_file($path) || ! hash_equals(hash('sha256', $pem), (string) @hash_file('sha256', $path))) {
            throw new DirectoryUnavailable('Проверка доверенного CA не пройдена.');
        }
        $settings['ca_file'] = $path;

        return $settings;
    }
}
