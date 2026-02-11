<?php

return [
    'jwt_secret' => getenv('JWT_SECRET') ?: '',
    'jwt_algo' => 'HS256',
    'access_token_ttl' => 900,       // 15 minutes
    'refresh_token_ttl' => 604800,   // 7 days
    'password_min_length' => 8,
    'bcrypt_cost' => 12,
    'cookie_name' => 'refresh_token',
    'cookie_path' => '/api/auth',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => filter_var(getenv('COOKIE_SECURE'), FILTER_VALIDATE_BOOLEAN),
];
