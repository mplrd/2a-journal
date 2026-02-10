<?php

namespace App\Enums;

enum TradeStatus: string
{
    case OPEN = 'OPEN';
    case SECURED = 'SECURED';
    case CLOSED = 'CLOSED';
}
