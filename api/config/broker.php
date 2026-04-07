<?php

return [
    'encryption_key' => base64_decode(getenv('BROKER_ENCRYPTION_KEY') ?: base64_encode(str_repeat('0', 32))),
    'ctrader' => [
        'ws_host' => getenv('CTRADER_WS_HOST') ?: 'live.ctraderapi.com',
        'ws_port' => (int) (getenv('CTRADER_WS_PORT') ?: 5036),
    ],
    'metaapi' => [
        'base_url' => getenv('METAAPI_BASE_URL') ?: 'https://mt-client-api-v1.agiliumtrade.agiliumtrade.ai',
    ],
];
