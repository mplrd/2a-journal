<?php

return [
    'headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
    ],
    'rate_limits' => [
        'login' => ['max_attempts' => 10, 'window_seconds' => 900],
        'register' => ['max_attempts' => 5, 'window_seconds' => 900],
        'refresh' => ['max_attempts' => 10, 'window_seconds' => 900],
        'forgot_password' => ['max_attempts' => 3, 'window_seconds' => 900],
        // SSO: prevent flood-of-codes DoS on issuance and brute-force probing
        // on exchange. Per-IP, both share the standard middleware.
        'sso_issue' => ['max_attempts' => 30, 'window_seconds' => 300],
        'sso_exchange' => ['max_attempts' => 30, 'window_seconds' => 300],
    ],
    'lockout' => [
        'max_attempts' => 5,
        'lockout_seconds' => 900, // 15 minutes
    ],
];
