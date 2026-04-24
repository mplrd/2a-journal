<?php

// Fail fast: a missing/empty key used to silently fall back to a hardcoded
// 32-zero-byte key, which made credential encryption cosmetic. Force every
// env (dev, test, prod) to provide a real 32-byte key, base64-encoded.
$encryptionKey = getenv('BROKER_ENCRYPTION_KEY');
if (!$encryptionKey) {
    throw new RuntimeException(
        'BROKER_ENCRYPTION_KEY is required. Generate one with: openssl rand -base64 32'
    );
}

return [
    'auto_sync_enabled' => filter_var(getenv('BROKER_AUTO_SYNC_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    'sync_interval_minutes' => (int) (getenv('BROKER_SYNC_INTERVAL_MINUTES') ?: 15),
    'max_consecutive_failures' => (int) (getenv('BROKER_SYNC_MAX_FAILURES') ?: 3),
    'encryption_key' => base64_decode($encryptionKey),
    'ctrader' => [
        'ws_host' => getenv('CTRADER_WS_HOST') ?: 'live.ctraderapi.com',
        'ws_port' => (int) (getenv('CTRADER_WS_PORT') ?: 5036),
    ],
    'metaapi' => [
        'base_url' => getenv('METAAPI_BASE_URL') ?: 'https://mt-client-api-v1.agiliumtrade.agiliumtrade.ai',
    ],
];
