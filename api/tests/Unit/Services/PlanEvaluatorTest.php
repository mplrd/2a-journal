<?php

namespace Tests\Unit\Services;

use App\Services\PlanEvaluator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The PlanEvaluator is the pure heart of the trading-plans feature
 * (docs/83-trading-plans.md): given one plan and a signal, it returns null
 * when the signal is applicable, or a short reason for the first failing
 * filter. No I/O — everything (risk %, current time) is passed in.
 *
 * Filters are combined with AND; each is inactive when unconfigured.
 */
class PlanEvaluatorTest extends TestCase
{
    private PlanEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new PlanEvaluator();
    }

    /** Convenience: a fixed Monday 10:00 UTC. */
    private function mondayTenUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-20 10:00:00', new DateTimeZone('UTC'));
    }

    private function plan(array $overrides = []): array
    {
        return array_merge([
            'symbol' => null,
            'allowed_direction' => null,
            'timezone' => null,
            'max_risk_percent' => null,
            'zones' => [],
            'windows' => [],
        ], $overrides);
    }

    // ── Instrument ────────────────────────────────────────────────
    // A zone is a pair of bare prices; without an instrument it means nothing.
    // A plan bound to one only ever applies to that instrument.

    public function testAPlanWithoutAnInstrumentAppliesToAnySymbol(): void
    {
        $plan = $this->plan(['symbol' => null]);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'DAX', 24500.0, null, $this->mondayTenUtc()));
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ', 24500.0, null, $this->mondayTenUtc()));
    }

    public function testAPlanBoundToAnInstrumentAcceptsIt(): void
    {
        $plan = $this->plan(['symbol' => 'NASDAQ']);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ', 25600.0, null, $this->mondayTenUtc()));
    }

    public function testAPlanBoundToAnInstrumentRejectsAnother(): void
    {
        $plan = $this->plan(['symbol' => 'NASDAQ']);
        $reason = $this->evaluator->evaluate($plan, 'BUY', 'DAX', 25600.0, null, $this->mondayTenUtc());
        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('DAX', $reason);
    }

    /**
     * The defect this filter exists for: a signal on an instrument the plan
     * never targeted, whose price happens to land in a zone, used to sail
     * through the filter and reach the broker.
     */
    public function testAForeignSymbolIsRejectedEvenWhenItsPriceFallsInAZone(): void
    {
        $plan = $this->plan([
            'symbol' => 'NASDAQ',
            'zones' => [['direction' => 'BUY', 'low_price' => 25560.0, 'high_price' => 25650.0]],
        ]);
        $this->assertNotNull(
            $this->evaluator->evaluate($plan, 'BUY', 'DAX', 25600.0, null, $this->mondayTenUtc())
        );
    }

    public function testTheSymbolComparisonIgnoresCaseAndSurroundingSpace(): void
    {
        $plan = $this->plan(['symbol' => 'NASDAQ']);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', ' nasdaq ', 25600.0, null, $this->mondayTenUtc()));
    }

    /**
     * The signal's symbol comes straight from the webhook payload, which bounds
     * its presence but not its length. The reason is stored (positions
     * .plan_adherence_reason, tradingview_alert_events.error_message), so it
     * must never carry an arbitrarily long string in from outside.
     */
    public function testAnOverlongSymbolIsClampedInTheReason(): void
    {
        $plan = $this->plan(['symbol' => 'NASDAQ']);
        $reason = $this->evaluator->evaluate($plan, 'BUY', str_repeat('A', 5000), 1.0, null, $this->mondayTenUtc());

        $this->assertNotNull($reason);
        $this->assertLessThanOrEqual(255, strlen($reason));
    }

    public function testTheInstrumentIsCheckedBeforeEveryOtherFilter(): void
    {
        // Direction and zone would both fail too; the reason must name the
        // instrument, since that is what actually rules the plan out.
        $plan = $this->plan([
            'symbol' => 'NASDAQ',
            'allowed_direction' => 'BUY',
            'zones' => [['direction' => 'SELL', 'low_price' => 1.0, 'high_price' => 2.0]],
        ]);
        $reason = $this->evaluator->evaluate($plan, 'SELL', 'DAX', 99999.0, null, $this->mondayTenUtc());
        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('DAX', $reason);
    }

    // ── No filters ────────────────────────────────────────────────

    public function testEmptyPlanIsApplicable(): void
    {
        $this->assertNull(
            $this->evaluator->evaluate($this->plan(), 'BUY', 'NASDAQ',24500.0, null, $this->mondayTenUtc())
        );
    }

    // ── Direction ─────────────────────────────────────────────────

    public function testDirectionMatchIsApplicable(): void
    {
        $plan = $this->plan(['allowed_direction' => 'BUY']);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',24500.0, null, $this->mondayTenUtc()));
    }

    public function testDirectionMismatchIsRejected(): void
    {
        $plan = $this->plan(['allowed_direction' => 'BUY']);
        $reason = $this->evaluator->evaluate($plan, 'SELL', 'NASDAQ',24500.0, null, $this->mondayTenUtc());
        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('direction', $reason);
    }

    public function testNullAllowedDirectionAllowsBothSides(): void
    {
        $plan = $this->plan(['allowed_direction' => null]);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, null, $this->mondayTenUtc()));
        $this->assertNull($this->evaluator->evaluate($plan, 'SELL', 'NASDAQ',1.0, null, $this->mondayTenUtc()));
    }

    // ── Price zones ───────────────────────────────────────────────

    public function testEntryInsideOneOfMultipleZonesIsApplicable(): void
    {
        $plan = $this->plan(['zones' => [
            ['direction' => 'BUY', 'low_price' => 24500.0, 'high_price' => 24550.0],
            ['direction' => 'BUY', 'low_price' => 24000.0, 'high_price' => 24400.0],
        ]]);
        // Falls in the second zone.
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',24100.0, null, $this->mondayTenUtc()));
    }

    public function testEntryOutsideAllZonesIsRejected(): void
    {
        $plan = $this->plan(['zones' => [
            ['direction' => 'BUY', 'low_price' => 24500.0, 'high_price' => 24550.0],
            ['direction' => 'BUY', 'low_price' => 24000.0, 'high_price' => 24400.0],
        ]]);
        $reason = $this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',24610.0, null, $this->mondayTenUtc());
        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('zone', $reason);
    }

    public function testZoneBoundsAreInclusive(): void
    {
        $plan = $this->plan(['zones' => [
            ['direction' => 'BUY', 'low_price' => 24000.0, 'high_price' => 24400.0],
        ]]);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',24000.0, null, $this->mondayTenUtc()));
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',24400.0, null, $this->mondayTenUtc()));
    }

    public function testZoneBoundsOrderIsNormalized(): void
    {
        // low_price > high_price on input must still describe [24000, 24400].
        $plan = $this->plan(['zones' => [
            ['direction' => 'BUY', 'low_price' => 24400.0, 'high_price' => 24000.0],
        ]]);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',24200.0, null, $this->mondayTenUtc()));
    }

    public function testZonesOnlyConstrainTheirOwnDirection(): void
    {
        // Only BUY zones defined; a SELL signal has no price constraint.
        $plan = $this->plan(['zones' => [
            ['direction' => 'BUY', 'low_price' => 24000.0, 'high_price' => 24400.0],
        ]]);
        $this->assertNull($this->evaluator->evaluate($plan, 'SELL', 'NASDAQ',99999.0, null, $this->mondayTenUtc()));
    }

    // ── Time windows ──────────────────────────────────────────────

    public function testInsideTimeWindowIsApplicable(): void
    {
        $plan = $this->plan([
            'timezone' => 'UTC',
            'windows' => [['days_mask' => 0b0000001, 'start_time' => '09:00:00', 'end_time' => '17:30:00']],
        ]);
        // Monday 10:00 UTC, window Monday 09:00–17:30.
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, null, $this->mondayTenUtc()));
    }

    public function testWrongDayIsRejected(): void
    {
        $plan = $this->plan([
            'timezone' => 'UTC',
            // Tuesday only (bit1).
            'windows' => [['days_mask' => 0b0000010, 'start_time' => '09:00:00', 'end_time' => '17:30:00']],
        ]);
        $reason = $this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, null, $this->mondayTenUtc());
        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('window', $reason);
    }

    public function testWrongTimeIsRejected(): void
    {
        $plan = $this->plan([
            'timezone' => 'UTC',
            'windows' => [['days_mask' => 0b0000001, 'start_time' => '09:00:00', 'end_time' => '17:30:00']],
        ]);
        // Monday 20:00 UTC — after the window.
        $now = new DateTimeImmutable('2026-07-20 20:00:00', new DateTimeZone('UTC'));
        $this->assertNotNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, null, $now));
    }

    public function testTimezoneConversionIsApplied(): void
    {
        $plan = $this->plan([
            'timezone' => 'Europe/Paris',
            'windows' => [['days_mask' => 0b0000001, 'start_time' => '09:00:00', 'end_time' => '17:30:00']],
        ]);
        // 08:00 UTC is BEFORE the 09:00 window, but in Europe/Paris (summer,
        // UTC+2) it is 10:00 Monday — inside. Proves the tz conversion runs.
        $now = new DateTimeImmutable('2026-07-20 08:00:00', new DateTimeZone('UTC'));
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, null, $now));
    }

    // ── Max risk per trade ────────────────────────────────────────

    public function testRiskAboveMaxIsRejected(): void
    {
        $plan = $this->plan(['max_risk_percent' => 1.0]);
        $reason = $this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, 2.5, $this->mondayTenUtc());
        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('risk', $reason);
    }

    public function testRiskBelowMaxIsApplicable(): void
    {
        $plan = $this->plan(['max_risk_percent' => 1.0]);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, 0.5, $this->mondayTenUtc()));
    }

    public function testUncomputableRiskSkipsTheFilter(): void
    {
        // riskPercent null (point value not configured) must NOT reject.
        $plan = $this->plan(['max_risk_percent' => 1.0]);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',1.0, null, $this->mondayTenUtc()));
    }

    // ── Combination (AND) ─────────────────────────────────────────

    public function testCombinedFiltersReturnFirstFailure(): void
    {
        // Direction passes, zone fails → the zone reason surfaces.
        $plan = $this->plan([
            'allowed_direction' => 'BUY',
            'zones' => [['direction' => 'BUY', 'low_price' => 24000.0, 'high_price' => 24400.0]],
        ]);
        $reason = $this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',30000.0, null, $this->mondayTenUtc());
        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('zone', $reason);
    }

    public function testAllFiltersPassingIsApplicable(): void
    {
        $plan = $this->plan([
            'allowed_direction' => 'BUY',
            'timezone' => 'UTC',
            'max_risk_percent' => 2.0,
            'zones' => [['direction' => 'BUY', 'low_price' => 24000.0, 'high_price' => 24400.0]],
            'windows' => [['days_mask' => 0b0000001, 'start_time' => '09:00:00', 'end_time' => '17:30:00']],
        ]);
        $this->assertNull($this->evaluator->evaluate($plan, 'BUY', 'NASDAQ',24200.0, 1.0, $this->mondayTenUtc()));
    }
}
