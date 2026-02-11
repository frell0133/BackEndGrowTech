<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // ✅ bolehkan origin FE kamu (atau pakai env)
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:3000'),
        'https://frontendgrowtechtesting1-production.up.railway.app',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // kalau pakai cookie/sanctum session → true
    'supports_credentials' => false,

];
