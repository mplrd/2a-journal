<?php

$appConfig = require __DIR__ . '/app.php';

$base = rtrim($appConfig['url'], '/');
if (!str_ends_with($base, '/api')) {
    $base .= '/api';
}

return [
    // The on/off flag lives in PlatformSettingsService ('robots_enabled',
    // DB > env ROBOTS_ENABLED > false). This config only carries the static
    // ingest URL + rate limit.
    'tradingview_base_url' => $base . '/webhooks/tradingview',
    // Rate limit for the public ingest endpoint. Anything beyond this rate is
    // likely a misconfigured TradingView template or abuse.
    'tradingview_rate_limit' => [
        'max_attempts' => (int) (getenv('TRADINGVIEW_RATE_MAX') ?: 120),
        'window_seconds' => (int) (getenv('TRADINGVIEW_RATE_WINDOW') ?: 60),
    ],
];
