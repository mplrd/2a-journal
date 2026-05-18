<?php

namespace App\Enums;

enum WebhookEventStatus: string
{
    case RECEIVED = 'RECEIVED';
    case REJECTED = 'REJECTED';
    case PROCESSED = 'PROCESSED';
    case FAILED = 'FAILED';
    case DUPLICATE = 'DUPLICATE';
}
