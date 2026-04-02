<?php

$trustedDeviceSecure = env('TRUSTED_DEVICE_SECURE');

if ($trustedDeviceSecure === null || $trustedDeviceSecure === '') {
    $trustedDeviceSecure = env('APP_ENV', 'production') === 'production';
}

$trustedDeviceSameSite = env('TRUSTED_DEVICE_SAME_SITE');

if (!$trustedDeviceSameSite) {
    $trustedDeviceSameSite = env('APP_ENV', 'production') === 'production' ? 'none' : 'lax';
}

return [
    'cookie_name' => env('TRUSTED_DEVICE_COOKIE_NAME', 'gt_trusted_device'),
    'cookie_path' => env('TRUSTED_DEVICE_COOKIE_PATH', '/'),
    'cookie_domain' => env('TRUSTED_DEVICE_COOKIE_DOMAIN', null),

    'secure' => filter_var($trustedDeviceSecure, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
        ?? ((string) $trustedDeviceSecure === '1'),

    'same_site' => strtolower((string) $trustedDeviceSameSite),

    'days' => (int) env('TRUSTED_DEVICE_DAYS', 30),
    'admin_days' => (int) env('TRUSTED_DEVICE_ADMIN_DAYS', 7),
    'allow_admin' => filter_var(env('TRUSTED_DEVICE_ALLOW_ADMIN', true), FILTER_VALIDATE_BOOLEAN),
];