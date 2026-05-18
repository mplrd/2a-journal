<?php

namespace App\Enums;

enum WebhookStatus: string
{
    case ACTIVE = 'ACTIVE';
    case REVOKED = 'REVOKED';
}
