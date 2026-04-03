<?php

return [
    'encryption_key' => base64_decode(getenv('BROKER_ENCRYPTION_KEY') ?: base64_encode(str_repeat('0', 32))),
    'ctrader' => [
        'client_id' => getenv('CTRADER_CLIENT_ID') ?: '',
        'client_secret' => getenv('CTRADER_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('CTRADER_REDIRECT_URI') ?: 'http://2a.journal.local/api/broker/ctrader/callback',
        'ws_host' => getenv('CTRADER_WS_HOST') ?: 'live.ctraderapi.com',
        'ws_port' => (int) (getenv('CTRADER_WS_PORT') ?: 5036),
        'oauth_authorize_url' => 'https://id.ctrader.com/my/settings/openapi/grantingaccess/',
        'oauth_token_url' => 'https://openapi.ctrader.com/apps/token',
    ],
    'metaapi' => [
        'base_url' => getenv('METAAPI_BASE_URL') ?: 'https://mt-client-api-v1.agiliumtrade.agiliumtrade.ai',
        'provisioning_url' => getenv('METAAPI_PROVISIONING_URL') ?: 'https://mt-provisioning-api-v1.agiliumtrade.agiliumtrade.ai',
    ],
];
