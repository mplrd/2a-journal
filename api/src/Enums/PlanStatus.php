<?php

namespace App\Enums;

/**
 * Lifecycle of a trading plan (docs/83-trading-plans.md). ARCHIVED is a
 * soft-delete that keeps the plan out of the active list while preserving it;
 * archiving is refused while an active robot still references the plan.
 */
enum PlanStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ARCHIVED = 'ARCHIVED';
}
