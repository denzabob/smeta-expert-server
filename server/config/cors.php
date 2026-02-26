<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],  // Включает API и CSRF-роут Sanctum
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:5173',
        'https://rembro.ru',
        'http://rembro.ru',
        'https://www.rembro.ru',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,  // Обязательно для куки
];
