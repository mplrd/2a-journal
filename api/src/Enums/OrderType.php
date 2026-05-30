<?php

namespace App\Enums;

enum OrderType: string
{
    case MARKET = 'MARKET';
    case LIMIT = 'LIMIT';
    case STOP = 'STOP';
}
