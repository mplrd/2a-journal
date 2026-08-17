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
 * Returns null as soon as ONE position's risk cannot be computed. Dropping it
 * from the sum would under-count, and an under-counted safeguard lets signals
 * through — the wrong way for the error to fall. Unknown is the honest answer,
 * and the evaluator then skips the filter rather than blocking a signal on a
 * technical gap (the rule already in force for the per-trade cap).
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
            $risk = $this->riskCalculator->computePercent(
                $userId,
                $accountId,
                (string) $position['symbol'],
                (float) $position['size'],
                (float) ($position['sl_points'] ?? 0),
            );
            if ($risk === null) {
                return null;
            }
            $total += $risk;
        }

        return $total;
    }
}
