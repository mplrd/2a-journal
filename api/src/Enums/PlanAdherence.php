<?php

namespace App\Enums;

/**
 * Whether a trade fell inside the trading plan it was taken under, evaluated and
 * frozen at write time (docs/83-trading-plans.md). NULL on a trade means no plan
 * was attached — this enum only qualifies trades that reference a plan.
 */
enum PlanAdherence: string
{
    case IN_PLAN = 'IN_PLAN';
    case OUT_OF_PLAN = 'OUT_OF_PLAN';
}
