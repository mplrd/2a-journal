<?php

namespace App\Enums;

enum TicketType: string
{
    case SUPPORT = 'SUPPORT';
    case BUG = 'BUG';
    case FEATURE = 'FEATURE';

    /**
     * Ordered whitelist of structured `details` keys allowed for this type.
     * Captured once at creation alongside the always-required free-text body.
     * SUPPORT has none (the body alone is enough).
     *
     * @return string[]
     */
    public function detailKeys(): array
    {
        return match ($this) {
            self::BUG => ['expected_behavior', 'reproduction_steps'],
            self::FEATURE => ['benefit', 'imagined_solution'],
            self::SUPPORT => [],
        };
    }
}
