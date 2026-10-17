<?php

namespace App\Services;

use LDAP\Connection;

/**
 * Read-only Active Directory adapter. No password changes or directory writes.
 * LDAP filters are escaped; bind is forbidden until certificate-verified TLS.
 */
class DirectoryGateway
{
    public function enabled(): bool
    {
        return (bool) config('directory.enabled');
    }

    public function settings(): array
    {
        $path = config('directory.config_file');
        if (! is_string($path) || ! is_readable($path)) {
            throw new DirectoryUnavailable('Настройки доменного входа недоступны.');
        }
        try {
            $settings = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new DirectoryUnavailable('Настройки доменного входа недоступны.');
        }
        if (! is_array($settings) || ! ($settings['enabled'] ?? false) || ! is_array($settings['hosts'] ?? null) || ! $settings['hosts']
            || ! is_array($settings['groups'] ?? null) || ! $settings['groups']
            || ! in_array($settings['mode'] ?? '', ['ldaps', 'starttls'], true)
            || ! is_string($settings['base_dn'] ?? null) || $settings['base_dn'] === ''
            || ! is_string($settings['bind_dn'] ?? null) || $settings['bind_dn'] === ''
            || ! is_string($settings['bind_password'] ?? null) || $settings['bind_password'] === ''
            || str_contains($settings['bind_password'], "\0")
            || ! is_string($settings['ca_file'] ?? null) || ! is_file($settings['ca_file']) || ! is_readable($settings['ca_file'])
            || ! is_int($settings['port'] ?? 636) || ($settings['port'] ?? 636) < 1 || ($settings['port'] ?? 636) > 65535) {
            throw new DirectoryUnavailable('Проверьте защищённые настройки доменного входа.');
        }
        foreach ($settings['hosts'] as $host) {
            if (! is_string($host) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}\z/', $host)) {
                throw new DirectoryUnavailable('Некорректный адрес контроллера.');
            }
        }
        foreach ($settings['groups'] as $group) {
            if (! is_array($group) || ! is_string($group['dn'] ?? null) || $group['dn'] === ''
                || ! in_array($group['role'] ?? '', ['viewer', 'operator', 'admin'], true)
                || ! is_int($group['primary_group_id'] ?? null) || $group['primary_group_id'] < 1) {
                throw new DirectoryUnavailable('Некорректное сопоставление доменных групп.');
            }
        }

        return $settings;
    }

    public function authenticate(string $identifier, #[\SensitiveParameter] string $password): ?array
    {
        if ($password === '' || str_contains($password, "\0") || $identifier === '' || str_contains($identifier, "\0")) {
            return null; // Empty passwords must never become anonymous LDAP binds.
        }
        $settings = $this->settings();
        if (str_contains($identifier, '\\')) {
            [$domain, $identifier] = explode('\\', $identifier, 2);
            if (strcasecmp($domain, $settings['netbios'] ?? '') !== 0) {
                return null;
            }
        }
        $ldap = $this->connect($settings);
        try {
            $value = ldap_escape($identifier, '', LDAP_ESCAPE_FILTER);
            $identity = $this->find($ldap, $settings, '(|(sAMAccountName='.$value.')(userPrincipalName='.$value.')(mail='.$value.'))');
            if (! $identity) {
                return null;
            }
            if (! @ldap_bind($ldap, $identity['dn'], $password)) {
                return null;
            }
            unset($identity['dn']);

            return $identity;
        } finally {
            ldap_unbind($ldap);
        }
    }

    /** Refresh authorization without retaining or reusing a user's password. */
    public function lookup(string $guid): ?array
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/', $guid)) {
            return null;
        }
        $settings = $this->settings();
        $ldap = $this->connect($settings);
        try {
            $binary = hex2bin($guid);
            $filter = '(objectGUID='.ldap_escape($binary, '', LDAP_ESCAPE_FILTER).')';
            $identity = $this->find($ldap, $settings, $filter);
            if ($identity) {
                unset($identity['dn']);
            }

            return $identity;
        } finally {
            ldap_unbind($ldap);
        }
    }

    /** Read-only diagnostics: each controller must pass TLS, service bind and base-DN read. */
    public function checkConnection(): array
    {
        $settings = $this->settings();
        $results = [];
        foreach (array_slice($settings['hosts'], 0, 3) as $host) {
            $connection = null;
            $ok = false;
            try {
                $connection = $this->connect(array_replace($settings, ['hosts' => [$host]]));
                $search = @ldap_read($connection, $settings['base_dn'], '(objectClass=*)', ['objectClass'], 0, 1, 5);
                $ok = $search !== false && ldap_count_entries($connection, $search) === 1;
            } catch (\Throwable $e) {
                // Never serialize connection arguments, credentials or directory entries.
            } finally {
                if ($connection instanceof Connection) {
                    @ldap_unbind($connection);
                }
            }
            $results[] = ['host' => $host, 'ok' => $ok];
        }

        return $results;
    }

    private function connect(#[\SensitiveParameter] array $settings): Connection
    {
        if (! extension_loaded('ldap')) {
            throw new DirectoryUnavailable('Доменный вход временно недоступен.');
        }
        // OpenLDAP requires these TLS options before creating the connection.
        if (! ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $settings['ca_file'])
            || ! ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND)) {
            throw new DirectoryUnavailable('Не удалось включить проверку сертификата контроллера.');
        }
        foreach (array_slice($settings['hosts'], 0, 3) as $host) {
            $connection = null;
            try {
                $scheme = $settings['mode'] === 'ldaps' ? 'ldaps' : 'ldap';
                $port = (int) ($settings['port'] ?? ($scheme === 'ldaps' ? 636 : 389));
                $connection = ldap_connect($scheme.'://'.$host.':'.$port);
                if (! ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3)
                    || ! ldap_set_option($connection, LDAP_OPT_REFERRALS, 0)
                    || ! ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, min(10, max(1, (int) ($settings['timeout'] ?? 5))))) {
                    throw new DirectoryUnavailable;
                }
                if ($scheme === 'ldap' && ! @ldap_start_tls($connection)) {
                    throw new DirectoryUnavailable;
                }
                if (! @ldap_bind($connection, $settings['bind_dn'], $settings['bind_password'])) {
                    throw new DirectoryUnavailable;
                }

                return $connection;
            } catch (\Throwable $e) {
                if ($connection instanceof Connection) {
                    @ldap_unbind($connection);
                }
            }
        }
        throw new DirectoryUnavailable('Контроллеры домена временно недоступны. Локальный вход администратора сохранён.');
    }

    private function membership(array $group): string
    {
        return '(|(memberOf:1.2.840.113556.1.4.1941:='.ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER).')(primaryGroupID='.(int) $group['primary_group_id'].'))';
    }

    private function find(Connection $ldap, array $settings, string $identityFilter): ?array
    {
        $allowed = '(|'.implode('', array_map(fn ($group) => $this->membership($group), $settings['groups'])).')';
        $filter = '(&(objectCategory=person)(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2))'.$identityFilter.$allowed.')';
        $search = @ldap_search($ldap, $settings['base_dn'], $filter, ['objectGUID', 'displayName', 'cn', 'mail', 'sAMAccountName', 'userAccountControl', 'accountExpires'], 0, 2, 5);
        if ($search === false) {
            throw new DirectoryUnavailable('Не удалось проверить доменную учётную запись.');
        }
        $entries = ldap_get_entries($ldap, $search);
        if ($entries['count'] !== 1) {
            return null;
        }
        $entry = $entries[0];
        if (strlen($entry['objectguid'][0] ?? '') !== 16 || (((int) ($entry['useraccountcontrol'][0] ?? 2)) & 2)
            || $this->accountExpired($entry['accountexpires'][0] ?? null)) {
            return null;
        }
        $role = null;
        $priority = ['viewer' => 1, 'operator' => 2, 'admin' => 3];
        foreach ($settings['groups'] as $group) {
            $match = @ldap_read($ldap, $entry['dn'], $this->membership($group), ['objectGUID'], 0, 1, 5);
            if ($match === false) {
                throw new DirectoryUnavailable('Не удалось проверить доменную группу.');
            }
            if (ldap_count_entries($ldap, $match) === 1 && ($role === null || $priority[$group['role']] > $priority[$role])) {
                $role = $group['role'];
            }
        }
        if ($role === null) {
            return null;
        }

        return [
            'guid' => bin2hex($entry['objectguid'][0]), 'dn' => $entry['dn'], 'role' => $role,
            'name' => $entry['displayname'][0] ?? $entry['cn'][0] ?? $entry['samaccountname'][0] ?? 'Пользователь домена',
            'email' => $entry['mail'][0] ?? null, 'login' => $entry['samaccountname'][0] ?? null,
        ];
    }

    private function accountExpired(mixed $filetime): bool
    {
        // AD uses unsigned 100-nanosecond intervals since 1601. Avoid floating-point
        // conversion: 0 and the signed-64-bit maximum explicitly mean "never".
        if ($filetime === null) {
            return false;
        }
        if ((! is_string($filetime) && ! is_int($filetime)) || ! preg_match('/\A[0-9]{1,19}\z/', (string) $filetime)) {
            return true;
        }
        $digits = ltrim((string) $filetime, '0');
        if ($digits === '' || $digits === '9223372036854775807') {
            return false;
        }
        if (strlen($digits) === 19 && strcmp($digits, '9223372036854775807') > 0) {
            return true;
        }
        $clock = now();
        $nowFiletime = ($clock->getTimestamp() + 11644473600) * 10000000 + (int) $clock->format('u') * 10;

        return (int) $digits <= $nowFiletime;
    }
}
