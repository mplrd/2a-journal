<?php

namespace App\Enums;

enum TriggerType: string
{
    case MANUAL = 'MANUAL';
    case SYSTEM = 'SYSTEM';
    case WEBHOOK = 'WEBHOOK';
    case BROKER_API = 'BROKER_API';
}
