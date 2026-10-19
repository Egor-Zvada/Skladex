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
        return app(DirectorySettingsService::class)->enabled();
    }

    public function revision(): string
    {
        return app(DirectorySettingsService::class)->revision();
    }

    public function settings(): array
    {
        $settings = app(DirectorySettingsService::class)->current();
        if (! ($settings['enabled'] ?? false)) {
            throw new DirectoryUnavailable('Доменный вход временно отключён.');
        }
        $this->validateConfiguration($settings);

        return $settings;
    }

    private function validateConfiguration(#[\SensitiveParameter] array $settings): void
    {
        if (! is_array($settings['hosts'] ?? null) || ! $settings['hosts'] || count($settings['hosts']) > 3
            || ! is_array($settings['groups'] ?? null) || ! $settings['groups']
            || count($settings['groups']) > 20
            || ! in_array($settings['mode'] ?? '', ['ldaps', 'starttls'], true)
            || ! is_string($settings['base_dn'] ?? null) || $settings['base_dn'] === ''
            || str_contains($settings['base_dn'], "\0")
            || ! is_string($settings['bind_dn'] ?? null) || $settings['bind_dn'] === ''
            || str_contains($settings['bind_dn'], "\0")
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
                || str_contains($group['dn'], "\0")
                || ! in_array($group['role'] ?? '', ['viewer', 'operator', 'admin'], true)) {
                throw new DirectoryUnavailable('Некорректное сопоставление доменных групп.');
            }
        }
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

    /** Read-only diagnostics work for a saved disabled configuration as well. */
    public function checkConnection(): array
    {
        return $this->probeConfiguration(app(DirectorySettingsService::class)->current())['controllers'];
    }

    /**
     * Only public diagnostics leave this method. Group RID values come from AD,
     * never from editable form fields or the legacy JSON.
     */
    public function probeConfiguration(#[\SensitiveParameter] array $candidate): array
    {
        $this->validateConfiguration($candidate);
        $results = [];
        $resolved = [];
        foreach ($candidate['hosts'] as $host) {
            $connection = null;
            $ok = false;
            try {
                $connection = $this->connect(array_replace($candidate, ['hosts' => [$host]]));
                $groups = $this->resolveGroups($connection, $candidate);
                if ($resolved && $resolved !== $groups) {
                    throw new DirectoryUnavailable('Контроллеры возвращают разные доменные группы.');
                }
                $resolved = $groups;
                $ok = true;
            } catch (\Throwable $e) {
                // Never serialize connection arguments, credentials or directory entries.
            } finally {
                if ($connection instanceof Connection) {
                    @ldap_unbind($connection);
                }
            }
            $results[] = ['host' => $host, 'ok' => $ok];
        }

        return ['controllers' => $results, 'groups' => $resolved, 'ok' => (bool) $resolved && in_array(true, array_column($results, 'ok'), true)];
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

    private function nestedMembership(array $group): string
    {
        return '(memberOf:1.2.840.113556.1.4.1941:='.ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER).')';
    }

    private function find(Connection $ldap, array $settings, string $identityFilter): ?array
    {
        $groups = $this->resolveGroups($ldap, $settings);
        $filter = '(&(objectCategory=person)(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2))'.$identityFilter.')';
        $search = @ldap_search($ldap, $settings['base_dn'], $filter, ['objectGUID', 'objectSid', 'primaryGroupID', 'displayName', 'cn', 'mail', 'sAMAccountName', 'userAccountControl', 'accountExpires'], 0, 2, 5);
        if ($search === false) {
            throw new DirectoryUnavailable('Не удалось проверить доменную учётную запись.');
        }
        $entries = ldap_get_entries($ldap, $search);
        if ($entries === false || ($entries['count'] ?? 0) !== 1) {
            return null;
        }
        $entry = $entries[0];
        if (strlen($entry['objectguid'][0] ?? '') !== 16 || (((int) ($entry['useraccountcontrol'][0] ?? 2)) & 2)
            || $this->accountExpired($entry['accountexpires'][0] ?? null)) {
            return null;
        }
        $role = null;
        $priority = ['viewer' => 1, 'operator' => 2, 'admin' => 3];
        foreach ($groups as $group) {
            $match = @ldap_read($ldap, $entry['dn'], $this->nestedMembership($group), ['objectGUID'], 0, 1, 5);
            if ($match === false) {
                throw new DirectoryUnavailable('Не удалось проверить доменную группу.');
            }
            $member = ldap_count_entries($ldap, $match) === 1 || $this->primaryMembership($entry, $group);
            if ($member && ($role === null || $priority[$group['role']] > $priority[$role])) {
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

    private function resolveGroups(Connection $ldap, #[\SensitiveParameter] array $settings): array
    {
        $base = $this->readOne($ldap, $settings['base_dn'], '(objectClass=*)', ['objectSid']);
        $domainSid = $this->sid($base['objectsid'][0] ?? null);
        if (! $domainSid) {
            // An OU may be used as the user-search base while the permitted groups
            // live elsewhere in the same domain.
            $root = $this->readOne($ldap, '', '(objectClass=*)', ['defaultNamingContext']);
            $context = $root['defaultnamingcontext'][0] ?? null;
            if (! is_string($context) || ! $this->dnWithin($settings['base_dn'], $context)) {
                throw new DirectoryUnavailable('База поиска должна принадлежать домену контроллера.');
            }
            $domain = $this->readOne($ldap, $context, '(objectClass=domainDNS)', ['objectSid']);
            $domainSid = $this->sid($domain['objectsid'][0] ?? null);
        }
        if (! is_string($domainSid) || ! preg_match('/\AS-1-5-21-[0-9]+-[0-9]+-[0-9]+\z/', $domainSid)) {
            throw new DirectoryUnavailable('Не удалось проверить домен базы поиска.');
        }
        $groups = [];
        foreach ($settings['groups'] as $group) {
            $entry = $this->readOne($ldap, $group['dn'], '(objectClass=group)', ['objectSid']);
            $sid = $this->sid($entry['objectsid'][0] ?? null);
            if (! $sid || ! preg_match('/\A'.preg_quote($domainSid, '/').'-([0-9]+)\z/', $sid, $match) || (int) $match[1] < 1) {
                throw new DirectoryUnavailable('Разрешённая группа должна принадлежать тому же домену.');
            }
            $groups[] = ['dn' => $entry['dn'], 'role' => $group['role'], 'primary_group_id' => (int) $match[1], 'domain_sid' => $domainSid];
        }

        return $groups;
    }

    private function readOne(Connection $ldap, string $dn, string $filter, array $attributes): array
    {
        $search = @ldap_read($ldap, $dn, $filter, $attributes, 0, 1, 5);
        if ($search === false) {
            throw new DirectoryUnavailable('Не удалось проверить базу поиска или разрешённые группы.');
        }
        $entries = ldap_get_entries($ldap, $search);
        if ($entries === false || ($entries['count'] ?? 0) !== 1) {
            throw new DirectoryUnavailable('База поиска или разрешённая группа не найдена.');
        }

        return $entries[0];
    }

    private function dnWithin(string $dn, string $context): bool
    {
        $parts = ldap_explode_dn($dn, 0);
        $parent = ldap_explode_dn($context, 0);
        if ($parts === false || $parent === false || $parts['count'] < $parent['count']) {
            return false;
        }
        for ($offset = 1; $offset <= $parent['count']; $offset++) {
            if (strcasecmp($parts[$parts['count'] - $offset], $parent[$parent['count'] - $offset]) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function primaryMembership(array $entry, array $group): bool
    {
        $sid = $this->sid($entry['objectsid'][0] ?? null);
        if (! $sid || ! isset($group['domain_sid'], $group['primary_group_id'])) {
            return false;
        }
        $rid = $entry['primarygroupid'][0] ?? null;

        return (is_string($rid) || is_int($rid))
            && preg_match('/\A[0-9]+\z/', (string) $rid)
            && (int) $rid === (int) $group['primary_group_id']
            && substr($sid, 0, strrpos($sid, '-')) === $group['domain_sid'];
    }

    private function sid(mixed $binary): ?string
    {
        if (! is_string($binary) || strlen($binary) < 12 || ord($binary[0]) !== 1) {
            return null;
        }
        $count = ord($binary[1]);
        if ($count < 1 || $count > 15 || strlen($binary) !== 8 + 4 * $count) {
            return null;
        }
        $authority = 0;
        for ($index = 2; $index < 8; $index++) {
            $authority = ($authority << 8) | ord($binary[$index]);
        }
        $parts = array_values(unpack('V'.$count, substr($binary, 8)));

        return 'S-1-'.$authority.'-'.implode('-', $parts);
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
