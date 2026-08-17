<?php

namespace App\Services;

use App\Enums\PlanAdherence;
use App\Exceptions\ValidationException;
use App\Repositories\TradingPlanRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Confronts one position — real or merely contemplated — with the plan it is
 * placed under, and returns the frozen verdict (docs/83, docs/101).
 *
 * This is the assembly work around the pure PlanEvaluator: load the plan, bring
 * the symbol back to the user's asset, price the risk of the signal and of what
 * the plan already carries, then compare. TradeService and OrderService each
 * held their own near-identical copy of it, and the entry-time preview
 * (docs/102) would have been a third. Three copies of a safeguard is three
 * places to forget a filter.
 *
 * Nothing here writes: callers decide what to do with the verdict.
 */
class PlanAdherenceEvaluator
{
    public function __construct(
        private TradingPlanRepository $planRepo,
        private PlanEvaluator $evaluator,
        private ?SignalRiskCalculator $riskCalculator = null,
        private ?PlanOpenRiskCalculator $openRiskCalculator = null,
        private ?SymbolResolver $symbolResolver = null,
    ) {}

    /**
     * @param ?string $at            evaluation instant, read as plan-local wall
     *                               clock; null = now (an order is judged at the
     *                               moment it is placed, a trade at its opening).
     * @param ?int    $excludePosition position to leave out of the plan's open
     *                               risk — re-evaluating a trade that is already
     *                               part of it.
     * @param string  $invalidPlanKey message key for an unknown/foreign/archived
     *                               plan, which differs between trades and orders.
     *
     * @return array{plan_id:?int, plan_adherence:?string, plan_adherence_reason:?string}
     */
    public function evaluate(
        int $userId,
        int $accountId,
        ?int $planId,
        string $direction,
        string $symbol,
        float $entryPrice,
        float $size,
        float $slPoints,
        ?string $at = null,
        ?int $excludePosition = null,
        string $invalidPlanKey = 'trades.error.invalid_plan',
    ): array {
        if ($planId === null) {
            return ['plan_id' => null, 'plan_adherence' => null, 'plan_adherence_reason' => null];
        }

        $plan = $this->loadOwnedActivePlan($userId, $planId, $invalidPlanKey);
        $symbol = $this->resolveSymbol($userId, $symbol);

        $reason = $this->evaluator->evaluate(
            $plan,
            $direction,
            $symbol,
            $entryPrice,
            $this->riskCalculator?->computePercent($userId, $accountId, $symbol, $size, $slPoints),
            $this->evaluationTime($at, $plan['timezone'] ?? null),
            $this->openRisk($userId, $accountId, $planId, $plan, $excludePosition),
        );

        return [
            'plan_id' => $planId,
            'plan_adherence' => $reason === null ? PlanAdherence::IN_PLAN->value : PlanAdherence::OUT_OF_PLAN->value,
            'plan_adherence_reason' => $reason,
        ];
    }

    private function loadOwnedActivePlan(int $userId, int $planId, string $invalidPlanKey): array
    {
        if (!$this->planRepo->isOwnedAndActive($userId, $planId)) {
            throw new ValidationException($invalidPlanKey, 'plan_id');
        }
        $plan = $this->planRepo->findByIdAssembled($planId);
        if ($plan === null) {
            throw new ValidationException($invalidPlanKey, 'plan_id');
        }
        return $plan;
    }

    /**
     * A signal or a synced position names the instrument the way its broker
     * does; the plan holds the user's own asset. Compare on the latter (docs/99).
     * Unresolvable goes through untouched: "not covered" is then the right
     * verdict, and naming what actually arrived is the useful half of it.
     */
    private function resolveSymbol(int $userId, string $symbol): string
    {
        $asset = $this->symbolResolver?->resolve($userId, $symbol);
        return $asset !== null ? (string) $asset['code'] : $symbol;
    }

    /** Only walk the plan's open positions when it actually caps their sum. */
    private function openRisk(int $userId, int $accountId, int $planId, array $plan, ?int $excludePosition): ?float
    {
        if (($plan['max_plan_risk_percent'] ?? null) === null) {
            return null;
        }
        return $this->openRiskCalculator?->computePercent($userId, $accountId, $planId, $excludePosition);
    }

    /** Plan-local wall clock, falling back to now on anything unparseable. */
    private function evaluationTime(?string $at, ?string $timezone): DateTimeImmutable
    {
        try {
            $tz = $timezone !== null ? new DateTimeZone($timezone) : null;
            return $at !== null ? new DateTimeImmutable($at, $tz) : new DateTimeImmutable('now');
        } catch (Throwable) {
            return new DateTimeImmutable('now');
        }
    }
}
