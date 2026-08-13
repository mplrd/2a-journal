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
    // Requêtes qu'une connexion peut dépenser chez son broker en une journée
    // UTC ; 0 désactive le plafond. Dernier garde-fou de l'évolution #22 : FTMO
    // a désactivé un compte de trading réel le 2026-08-07 au-delà de ~2 000
    // requêtes/jour. 1 500 laisse de la marge sous ce seuil, très au-dessus des
    // 648/jour mesurés au rythme actuel.
    'daily_request_budget' => (int) (getenv('BROKER_DAILY_REQUEST_BUDGET') ?: 1500),
    'encryption_key' => base64_decode($encryptionKey),
    'ctrader' => [
        'ws_host' => getenv('CTRADER_WS_HOST') ?: 'live.ctraderapi.com',
        'ws_port' => (int) (getenv('CTRADER_WS_PORT') ?: 5036),
        'oauth_token_url' => getenv('CTRADER_OAUTH_TOKEN_URL') ?: 'https://openapi.ctrader.com/apps/token',
    ],
    'metaapi' => [
        'base_url' => getenv('METAAPI_BASE_URL') ?: 'https://mt-client-api-v1.agiliumtrade.agiliumtrade.ai',
    ],
    'ouinex' => [
        'graphql_url' => getenv('OUINEX_GRAPHQL_URL') ?: 'https://live-api.ouinex.com/graphql',
    ],
    'bingx' => [
        // BingX recommends calling .com first; .pro is the fallback but
        // typical retail traffic never needs it. Keep .com as default.
        'base_url' => getenv('BINGX_BASE_URL') ?: 'https://open-api.bingx.com',
    ],
];
