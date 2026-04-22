<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    case PENDING = 'PENDING';
    case ACTIVE = 'ACTIVE';
    case ERROR = 'ERROR';
    case REVOKED = 'REVOKED';
}
