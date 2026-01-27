<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
    'http://localhost:3000',
    'http://10.45.196.166:3000'
    ],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];
