<?php

$allowedOrigins = array_filter([
    env('FRONTEND_URL'),
    env('ADMIN_URL'),
]);

// Allow local development origins only in non-production environments
if (in_array(env('APP_ENV', 'production'), ['local', 'testing'])) {
    $allowedOrigins = array_merge($allowedOrigins, [
        'http://127.0.0.1:8088',
        'http://localhost:8088',
        'http://127.0.0.1:8000',
        'http://localhost:8000',
    ]);
}

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique($allowedOrigins)),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
