<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3000',
        'https://loyality.theaditech.in',
        'https://pos.vakaro.in',
        'https://store.vakaro.in',
        'http://192.168.1.17:3000',
        'http://192.168.1.16:3000',
         '#^http://192\.168\.\d{1,3}\.\d{1,3}:3000$#',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // Set to true if using Sanctum or Cookies
];
