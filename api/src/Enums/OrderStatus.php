<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case EXECUTED = 'EXECUTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
}
