<?php

return [
    'jwt_secret' => getenv('JWT_SECRET') ?: '',
    'jwt_algo' => 'HS256',
    'access_token_ttl' => 900,       // 15 minutes
    'refresh_token_ttl' => 604800,   // 7 days
    'password_min_length' => 8,
    'bcrypt_cost' => 12,
    'cookie_name' => 'refresh_token',
    'cookie_path' => getenv('REFRESH_COOKIE_PATH') ?: '/api/auth',
    'cookie_httponly' => true,
    'cookie_samesite' => getenv('REFRESH_COOKIE_SAMESITE') ?: 'Lax',
    'cookie_secure' => filter_var(getenv('REFRESH_COOKIE_SECURE'), FILTER_VALIDATE_BOOLEAN),
    'email_verification_enabled' => filter_var(getenv('EMAIL_VERIFICATION_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'verification_token_ttl' => 86400,  // 24 hours
    'reset_token_ttl' => 3600,          // 1 hour
];
