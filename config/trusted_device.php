<?php

return [
    'cookie_name' => env('TRUSTED_DEVICE_COOKIE_NAME', 'gt_trusted_device'),
    'cookie_path' => env('TRUSTED_DEVICE_COOKIE_PATH', '/'),
    'cookie_domain' => env('TRUSTED_DEVICE_COOKIE_DOMAIN', env('SESSION_DOMAIN')),
    'secure' => env('TRUSTED_DEVICE_SECURE', env('SESSION_SECURE_COOKIE', app()->environment('production'))),
    'same_site' => env(
        'TRUSTED_DEVICE_SAME_SITE',
        env('SESSION_SAME_SITE', app()->environment('production') ? 'none' : 'lax')
    ),
    'partitioned' => env('TRUSTED_DEVICE_PARTITIONED', false),
    'days' => (int) env('TRUSTED_DEVICE_DAYS', 30),
    'admin_days' => (int) env('TRUSTED_DEVICE_ADMIN_DAYS', 7),
    'allow_admin' => env('TRUSTED_DEVICE_ALLOW_ADMIN', true),
    'bind_user_agent' => env('TRUSTED_DEVICE_BIND_USER_AGENT', true),
];
