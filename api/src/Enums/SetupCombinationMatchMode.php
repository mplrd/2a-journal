<?php

namespace App\Enums;

enum SetupCombinationMatchMode: string
{
    // All requested setups must be present on the trade (logical AND).
    // The trade may carry additional setups beyond the request.
    case ALL = 'all';
}
