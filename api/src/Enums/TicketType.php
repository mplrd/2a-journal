<?php

namespace App\Enums;

enum TicketType: string
{
    case SUPPORT = 'SUPPORT';
    case BUG = 'BUG';
    case FEATURE = 'FEATURE';
}
