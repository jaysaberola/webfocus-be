<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'file-manager/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('CORS_ALLOWED_ORIGIN', 'https://cms4-nextjs.vercel.app'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
