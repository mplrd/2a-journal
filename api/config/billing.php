<?php

return [
    'secret_key' => getenv('STRIPE_SECRET_KEY') ?: '',
    'webhook_secret' => getenv('STRIPE_WEBHOOK_SECRET') ?: '',
    'price_id' => getenv('STRIPE_PRICE_ID') ?: '',
    'frontend_url' => getenv('FRONTEND_URL') ?: 'http://2a.journal.local',
    'grace_days' => (int) (getenv('BILLING_GRACE_DAYS') ?: 14),
];
