<?php

namespace App\Enums;

enum AccountMode: string
{
    case DEMO = 'DEMO';
    case LIVE = 'LIVE';
    case CHALLENGE = 'CHALLENGE';
    case VERIFICATION = 'VERIFICATION';
    case FUNDED = 'FUNDED';
}
