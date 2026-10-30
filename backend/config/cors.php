<?php

return [
    'paths' => ['api/*', 'up'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5000',
        'http://127.0.0.1:5000',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Correlation-ID'],
    'max_age' => 0,
    'supports_credentials' => false,
];
