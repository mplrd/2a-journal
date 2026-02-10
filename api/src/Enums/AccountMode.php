<?php

namespace App\Enums;

enum AccountMode: string
{
    case DEMO = 'DEMO';
    case LIVE = 'LIVE';
    case CHALLENGE = 'CHALLENGE';
    case FUNDED = 'FUNDED';
}
