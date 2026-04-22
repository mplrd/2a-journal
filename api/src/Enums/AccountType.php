<?php

namespace App\Enums;

enum AccountType: string
{
    case BROKER_DEMO = 'BROKER_DEMO';
    case BROKER_LIVE = 'BROKER_LIVE';
    case PROP_FIRM = 'PROP_FIRM';
}
