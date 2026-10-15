<?php

return [
    'timezone' => env('SKLADEX_TIMEZONE', 'Asia/Sakhalin'),
    'qr_base_url' => env('QR_BASE_URL', env('APP_URL')),
    'qr_allowed_hosts' => array_filter(array_map('trim', explode(',', env('QR_ALLOWED_HOSTS', 'skladex.vve.local,192.168.110.200,192.168.110.203,localhost')))),
    'allowed_hosts' => array_filter(array_map('trim', explode(',', env('APP_ALLOWED_HOSTS', 'skladex.vve.local,192.168.110.203,localhost,127.0.0.1')))),
    'license_master_key' => env('LICENSE_MASTER_KEY') ?: (is_file(env('LICENSE_MASTER_KEY_FILE', '/etc/skladex/license-master.key')) ? trim(file_get_contents(env('LICENSE_MASTER_KEY_FILE', '/etc/skladex/license-master.key'))) : null),
    'license_key_id' => env('LICENSE_KEY_ID', 'v1'),
    'setup_token_hash' => env('SETUP_TOKEN_HASH'),
    'require_https' => env('SKLADEX_REQUIRE_HTTPS', true),
    'max_labels' => 500,
];
