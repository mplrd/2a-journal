<?php

$appConfig = require __DIR__ . '/app.php';

$base = rtrim($appConfig['url'], '/');
if (!str_ends_with($base, '/api')) {
    $base .= '/api';
}

return [
    'tradingview_enabled' => filter_var(getenv('TRADINGVIEW_WEBHOOKS_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    'tradingview_base_url' => $base . '/webhooks/tradingview',
    // Rate limit for the public ingest endpoint. Anything beyond this rate is
    // likely a misconfigured TradingView template or abuse.
    'tradingview_rate_limit' => [
        'max_attempts' => (int) (getenv('TRADINGVIEW_RATE_MAX') ?: 120),
        'window_seconds' => (int) (getenv('TRADINGVIEW_RATE_WINDOW') ?: 60),
    ],
];
