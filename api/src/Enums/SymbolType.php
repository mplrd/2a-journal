<?php

namespace App\Enums;

enum SymbolType: string
{
    case INDEX = 'INDEX';
    case FOREX = 'FOREX';
    case CRYPTO = 'CRYPTO';
    case STOCK = 'STOCK';
    case COMMODITY = 'COMMODITY';
}
