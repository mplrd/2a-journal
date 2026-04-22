<?php

$origins = getenv('CORS_ORIGINS') ?: 'http://localhost:5173';

return [
    'origins' => array_map('trim', explode(',', $origins)),
    'methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
    'headers' => 'Content-Type, Authorization',
    'max_age' => 86400,
    'credentials' => true,
];
