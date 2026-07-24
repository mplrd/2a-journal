<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure decision core of the trading-plans feature (docs/83-trading-plans.md).
 *
 * Confronts a single plan with one OPEN signal and returns null when the
 * signal is applicable, or a short English reason for the FIRST failing
 * filter (stored in the alert event's error_message for audit).
 *
 * No I/O: the assembled plan, the signal direction/price, the pre-computed
 * risk percentage (null when it couldn't be computed) and the evaluation
 * time are all passed in — which keeps the class exhaustively unit-testable.
 *
 * Filters are OPTIONAL and combined with AND; each is inactive when its
 * configuration is absent. The plan array is the DB-native shape assembled by
 * TradingPlanRepository:
 *   [
 *     'allowed_direction' => 'BUY'|'SELL'|null,
 *     'timezone'          => 'Europe/Paris'|null,
 *     'max_risk_percent'  => float|string|null,
 *     'zones'   => [['direction'=>'BUY','low_price'=>..,'high_price'=>..], ...],
 *     'windows' => [['days_mask'=>int,'start_time'=>'HH:MM:SS','end_time'=>'HH:MM:SS'], ...],
 *   ]
 */
class PlanEvaluator
{
    public function evaluate(
        array $plan,
        string $direction,
        float $entryPrice,
        ?float $riskPercent,
        DateTimeImmutable $now,
    ): ?string {
        return $this->checkDirection($plan, $direction)
            ?? $this->checkZones($plan, $direction, $entryPrice)
            ?? $this->checkWindows($plan, $now)
            ?? $this->checkRisk($plan, $riskPercent);
    }

    /** The signal side must match the plan's allowed side (NULL = both). */
    private function checkDirection(array $plan, string $direction): ?string
    {
        $allowed = $plan['allowed_direction'] ?? null;
        if ($allowed !== null && $allowed !== $direction) {
            return "direction {$direction} not allowed (plan allows {$allowed})";
        }
        return null;
    }

    /**
     * Only zones matching the signal's direction constrain it. If the plan
     * lists >=1 zone for that direction, the entry must fall in at least one
     * (bounds inclusive, order normalized). No zone for the direction = no
     * price constraint on that direction.
     */
    private function checkZones(array $plan, string $direction, float $entryPrice): ?string
    {
        $zones = array_filter(
            $plan['zones'] ?? [],
            static fn (array $z): bool => ($z['direction'] ?? null) === $direction,
        );
        if ($zones === []) {
            return null;
        }

        foreach ($zones as $zone) {
            $low = min((float) $zone['low_price'], (float) $zone['high_price']);
            $high = max((float) $zone['low_price'], (float) $zone['high_price']);
            if ($entryPrice >= $low && $entryPrice <= $high) {
                return null;
            }
        }

        return sprintf('entry %s outside %s zones', $this->trimNumber($entryPrice), $direction);
    }

    /**
     * If the plan defines >=1 window, the signal time (converted to the plan
     * timezone) must fall in at least one. Windows are same-day (start < end);
     * days_mask has bit 0 = Monday … bit 6 = Sunday.
     */
    private function checkWindows(array $plan, DateTimeImmutable $now): ?string
    {
        $windows = $plan['windows'] ?? [];
        if ($windows === []) {
            return null;
        }

        $tz = $plan['timezone'] ?? null;
        $local = $tz !== null ? $now->setTimezone(new DateTimeZone($tz)) : $now;
        $dayBit = 1 << ((int) $local->format('N') - 1); // ISO: Mon=1 → bit0
        $seconds = (int) $local->format('H') * 3600
            + (int) $local->format('i') * 60
            + (int) $local->format('s');

        foreach ($windows as $window) {
            if (((int) $window['days_mask'] & $dayBit) === 0) {
                continue;
            }
            $start = $this->timeToSeconds((string) $window['start_time']);
            $end = $this->timeToSeconds((string) $window['end_time']);
            if ($seconds >= $start && $seconds < $end) {
                return null;
            }
        }

        return 'outside trading windows';
    }

    /**
     * If the plan caps risk and the signal's risk was computable, reject when
     * it exceeds the cap. A null riskPercent (point value not configured, or
     * capital unknown) skips the filter rather than blocking the signal.
     */
    private function checkRisk(array $plan, ?float $riskPercent): ?string
    {
        $max = $plan['max_risk_percent'] ?? null;
        if ($max === null || $riskPercent === null) {
            return null;
        }
        if ($riskPercent > (float) $max) {
            return sprintf('risk %.3f%% exceeds plan max %.3f%%', $riskPercent, (float) $max);
        }
        return null;
    }

    private function timeToSeconds(string $time): int
    {
        [$h, $m, $s] = array_pad(explode(':', $time), 3, '0');
        return (int) $h * 3600 + (int) $m * 60 + (int) $s;
    }

    /** Compact price rendering for the reason string (24610.00000 → 24610). */
    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.5f', $value), '0'), '.');
    }
}
