<?php

return [
    'enabled' => (bool) env('LDAP_ENABLED', false),
    // Bind credentials live outside the repository and config cache.
    'config_file' => env('LDAP_CONFIG_FILE', '/etc/skladex/ldap.json'),
    'ca_cache_directory' => storage_path('app/private/directory-ca'),
];
