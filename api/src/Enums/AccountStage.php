<?php

namespace App\Enums;

enum AccountStage: string
{
    case CHALLENGE = 'CHALLENGE';
    case VERIFICATION = 'VERIFICATION';
    case FUNDED = 'FUNDED';
}
