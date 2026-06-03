<?php

namespace App\Enums;

enum RobotStatus: string
{
    case ACTIVE = 'ACTIVE';     // passes trades
    case PAUSED = 'PAUSED';     // receives + logs signals but places no trade (reversible)
    case ARCHIVED = 'ARCHIVED'; // soft-deleted; keeps history, no longer listed as active
}
