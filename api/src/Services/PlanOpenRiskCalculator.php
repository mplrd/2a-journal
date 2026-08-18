<?php

namespace App\Services;

use App\Repositories\PositionRepository;

/**
 * Risk already exposed under a trading plan, on one account, as a percentage of
 * that account's capital (docs/83-trading-plans.md).
 *
 * `max_risk_percent` caps a single signal. Respecting 1% per trade twenty times
 * over still puts 20% at risk, and a robot can build that in minutes — so the
 * plan also carries `max_plan_risk_percent`, and someone has to add up what is
 * already on the table. PlanEvaluator stays pure and only compares numbers;
 * this is where the I/O lives.
 *
 * Three possible answers, and they are deliberately distinct:
 *
 *   float  the exposure, in percent of the account capital.
 *   INF    at least one position carries NO STOP. Its loss is not bounded, so
 *          neither is the total. Counting it as zero would under-count, and an
 *          under-counted safeguard lets signals through; turning the cap off
 *          would leave the user believing an envelope that no longer holds.
 *          The evaluator refuses and says so.
 *   null   the exposure is UNMEASURABLE. A different animal from unbounded:
 *          nothing says the risk is large, we simply cannot price it. The
 *          evaluator then skips the filter rather than block on a technical
 *          gap, the rule already in force for the per-trade cap.
 *
 * The point value is NOT one of the ways to land on null, contrary to what the
 * per-trade cap's own comments long claimed: both symbols.point_value and
 * symbol_account_settings.point_value are NOT NULL DEFAULT 1, and SymbolService
 * refuses a value <= 0 on both write paths. What is left is a symbol outside the
 * user's assets — unreachable from the UI, whose symbol field is a picker over
 * them — and an account whose capital is <= 0, which is a blown account, where
 * a percentage of nothing means nothing and the per-trade cap is inert too.
 */
class PlanOpenRiskCalculator
{
    public function __construct(
        private PositionRepository $positionRepo,
        private SignalRiskCalculator $riskCalculator,
    ) {}

    /**
     * @param ?int $excludePositionId position to leave out — re-evaluating an
     *                                open trade, whose own risk is about to be
     *                                counted again as the incoming signal's.
     */
    public function computePercent(
        int $userId,
        int $accountId,
        int $planId,
        ?int $excludePositionId = null,
    ): ?float {
        $positions = $this->positionRepo->findStillExposedByPlanAndAccount(
            $planId,
            $accountId,
            $excludePositionId,
        );

        $total = 0.0;
        foreach ($positions as $position) {
            // Checked before pricing it: SignalRiskCalculator answers null both
            // for "no stop" and for "no point value", and those two must not
            // end up telling the caller the same thing.
            $slPoints = (float) ($position['sl_points'] ?? 0);
            if ($slPoints <= 0) {
                return INF;
            }

            $risk = $this->riskCalculator->computePercent(
                $userId,
                $accountId,
                (string) $position['symbol'],
                (float) $position['size'],
                $slPoints,
            );
            if ($risk === null) {
                return null;
            }
            $total += $risk;
        }

        return $total;
    }
}
