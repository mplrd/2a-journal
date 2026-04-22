<?php

namespace App\Enums;

enum EntityType: string
{
    case ORDER = 'ORDER';
    case TRADE = 'TRADE';
    case ACCOUNT = 'ACCOUNT';
    case POSITION = 'POSITION';
}
