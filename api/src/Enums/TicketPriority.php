<?php

namespace App\Enums;

enum TicketPriority: string
{
    case LOW = 'LOW';
    case NORMAL = 'NORMAL';
    case HIGH = 'HIGH';
}
