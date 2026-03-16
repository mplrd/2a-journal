<?php

namespace App\Enums;

enum TradingSession: string
{
    case ASIA = 'ASIA';
    case EUROPE = 'EUROPE';
    case US = 'US';
    case OFF = 'OFF';
}
