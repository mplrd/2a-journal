<?php

namespace App\Enums;

enum ImportStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case ROLLED_BACK = 'ROLLED_BACK';
}
