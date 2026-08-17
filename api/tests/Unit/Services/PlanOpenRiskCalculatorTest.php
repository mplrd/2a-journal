<?php

namespace Tests\Unit\Services;

use App\Repositories\PositionRepository;
use App\Services\PlanOpenRiskCalculator;
use App\Services\SignalRiskCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Risk already exposed under a plan, on one account (docs/83-trading-plans.md).
 *
 * A cap of 1% per trade says nothing about total exposure: twenty entries each
 * within the cap put 20% at risk. This is the other half of the cumulative
 * filter — the evaluator is pure and only compares numbers, someone has to go
 * and add them up.
 */
class PlanOpenRiskCalculatorTest extends TestCase
{
    private PositionRepository $positionRepo;
    private SignalRiskCalculator $riskCalculator;
    private PlanOpenRiskCalculator $calculator;

    protected function setUp(): void
    {
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->riskCalculator = $this->createMock(SignalRiskCalculator::class);
        $this->calculator = new PlanOpenRiskCalculator($this->positionRepo, $this->riskCalculator);
    }

    private function position(int $id, string $symbol, float $size, ?float $slPoints): array
    {
        return ['id' => $id, 'symbol' => $symbol, 'size' => $size, 'sl_points' => $slPoints];
    }

    public function testNothingExposedUnderThePlanIsZeroNotUnknown(): void
    {
        // Zero and "can't tell" are different answers: zero still lets the cap
        // constrain the incoming signal, unknown switches the filter off.
        $this->positionRepo->method('findStillExposedByPlanAndAccount')->willReturn([]);
        $this->assertSame(0.0, $this->calculator->computePercent(1, 100, 7));
    }

    public function testItSumsTheRiskOfEveryPositionStillExposed(): void
    {
        $this->positionRepo->method('findStillExposedByPlanAndAccount')->willReturn([
            $this->position(1, 'NASDAQ', 2.0, 50.0),
            $this->position(2, 'DAX', 1.0, 30.0),
        ]);
        $this->riskCalculator->method('computePercent')->willReturnOnConsecutiveCalls(1.25, 0.75);

        $this->assertSame(2.0, $this->calculator->computePercent(1, 100, 7));
    }

    public function testOnePositionWhoseRiskIsNotComputableMakesTheWholeTotalUnknown(): void
    {
        // Skipping it would silently under-count, and under-counting a safeguard
        // lets signals through — the wrong way round. Unknown is the honest
        // answer, and the evaluator turns the filter off rather than blocking.
        $this->positionRepo->method('findStillExposedByPlanAndAccount')->willReturn([
            $this->position(1, 'NASDAQ', 2.0, 50.0),
            $this->position(2, 'EXOTIC', 1.0, null),
        ]);
        $this->riskCalculator->method('computePercent')->willReturnOnConsecutiveCalls(1.25, null);

        $this->assertNull($this->calculator->computePercent(1, 100, 7));
    }

    public function testTheEditedPositionIsLeftOutOfItsOwnTotal(): void
    {
        // Re-evaluating an open trade against its plan: it is already part of
        // the exposure, and its risk is about to be added again as the signal's.
        $this->positionRepo->expects($this->once())
            ->method('findStillExposedByPlanAndAccount')
            ->with(7, 100, 42)
            ->willReturn([$this->position(1, 'NASDAQ', 2.0, 50.0)]);
        $this->riskCalculator->method('computePercent')->willReturn(1.25);

        $this->assertSame(1.25, $this->calculator->computePercent(1, 100, 7, 42));
    }

    public function testEachPositionIsPricedOnTheAccountItSitsOn(): void
    {
        // A percentage only means something against a capital, and the point
        // value can be overridden per account — so the account must travel.
        $this->positionRepo->method('findStillExposedByPlanAndAccount')
            ->willReturn([$this->position(1, 'NASDAQ', 2.0, 50.0)]);
        $this->riskCalculator->expects($this->once())
            ->method('computePercent')
            ->with(1, 100, 'NASDAQ', 2.0, 50.0)
            ->willReturn(1.25);

        $this->assertSame(1.25, $this->calculator->computePercent(1, 100, 7));
    }
}
