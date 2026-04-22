<?php

return [
    'enabled' => filter_var(getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'driver' => getenv('MAIL_DRIVER') ?: 'log',
    'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@2a-journal.local',
    'from_name' => getenv('MAIL_FROM_NAME') ?: 'Trading Journal',
    'frontend_url' => getenv('FRONTEND_URL') ?: 'http://localhost:5173',
    'resend_api_key' => getenv('RESEND_API_KEY') ?: '',
];
