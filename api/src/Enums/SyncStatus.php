<?php

namespace App\Enums;

enum SyncStatus: string
{
    case STARTED = 'STARTED';
    case SUCCESS = 'SUCCESS';
    case PARTIAL = 'PARTIAL';
    case FAILED = 'FAILED';
}
