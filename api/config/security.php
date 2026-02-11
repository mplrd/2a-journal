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
    ],
];
