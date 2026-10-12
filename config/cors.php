<?php

return [
    'paths' => ['api/mobile/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        env('MOBILE_APP_URL'),          // https://console.app.XEDIK
        'http://localhost:5173',         // dev PWA
        'http://localhost:4173',         // preview PWA
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
