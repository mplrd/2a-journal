<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\BingxFillReconstructor;
use PHPUnit\Framework\TestCase;

/**
 * The reconstructor takes a flat list of FILLED orders (already normalized
 * to a canonical shape by the connector) and rebuilds position lifecycles
 * per (symbol, positionSide) group. It's the heart of the fills-based sync
 * — pure logic, no IO. Tests cover the cycles the journal actually needs to
 * represent : single open→close, partial closes, scale-ins, hedge mode, and
 * the degenerate case of an orphan reduce-only fill.
 */
class BingxFillReconstructorTest extends TestCase
{
    private BingxFillReconstructor $reconstructor;

    protected function setUp(): void
    {
        $this->reconstructor = new BingxFillReconstructor();
    }

    /**
     * Build a normalized fill row matching the contract that the connector's
     * normalizeBingxFill() emits. Defaults are chosen so each fill on its
     * own is a valid open of a LONG BTC-USDT at $60k.
     */
    private function fill(array $overrides): array
    {
        return array_merge([
            'orderId' => '100',
            'symbol' => 'BTC-USDT',
            'positionSide' => 'LONG',
            'side' => 'BUY',
            'reduce_only' => false,
            'executed_qty' => 0.01,
            'avg_price' => 60000.0,
            'profit' => 0.0,
            'time' => 1716000000000,
        ], $overrides);
    }

    public function testSingleOpenThenFullCloseProducesOneClosedCycle(): void
    {
        $result = $this->reconstructor->reconstruct([
            $this->fill(['orderId' => '1', 'time' => 1000, 'executed_qty' => 0.01, 'avg_price' => 60000]),
            $this->fill(['orderId' => '2', 'time' => 2000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.01, 'avg_price' => 62000, 'profit' => 20.0]),
        ]);

        $this->assertCount(1, $result['closed']);
        $this->assertCount(0, $result['open']);

        $cycle = $result['closed'][0];
        $this->assertSame('BTC-USDT', $cycle['symbol']);
        $this->assertSame('BUY', $cycle['direction']);
        $this->assertEqualsWithDelta(60000.0, $cycle['entry_price'], 0.001);
        $this->assertEqualsWithDelta(62000.0, $cycle['exit_price'], 0.001);
        $this->assertEqualsWithDelta(0.01, $cycle['size'], 0.00001);
        $this->assertEqualsWithDelta(20.0, $cycle['pnl'], 0.001);
        $this->assertSame('bingx_position_1', $cycle['external_id']);
        $this->assertSame(1000, $cycle['opened_at']);
        $this->assertSame(2000, $cycle['closed_at']);
        $this->assertCount(1, $cycle['exits']);
        $this->assertSame('bingx_fill_2', $cycle['exits'][0]['external_id']);
    }

    public function testTwoEqualPartialClosesProduceTwoExitsThenFullyClosed(): void
    {
        $result = $this->reconstructor->reconstruct([
            $this->fill(['orderId' => '1', 'time' => 1000, 'executed_qty' => 0.02, 'avg_price' => 60000]),
            $this->fill(['orderId' => '2', 'time' => 2000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.01, 'avg_price' => 61000, 'profit' => 10.0]),
            $this->fill(['orderId' => '3', 'time' => 3000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.01, 'avg_price' => 63000, 'profit' => 30.0]),
        ]);

        $this->assertCount(1, $result['closed']);
        $this->assertCount(0, $result['open']);

        $cycle = $result['closed'][0];
        $this->assertCount(2, $cycle['exits']);
        $this->assertEqualsWithDelta(40.0, $cycle['pnl'], 0.001);
        // Weighted average exit price: (0.01*61000 + 0.01*63000) / 0.02 = 62000
        $this->assertEqualsWithDelta(62000.0, $cycle['exit_price'], 0.001);
        $this->assertSame(3000, $cycle['closed_at']);
    }

    public function testPartialCloseLeavingPositionOpenProducesOneOpenCycleWithExit(): void
    {
        $result = $this->reconstructor->reconstruct([
            $this->fill(['orderId' => '1', 'time' => 1000, 'executed_qty' => 0.02, 'avg_price' => 60000]),
            $this->fill(['orderId' => '2', 'time' => 2000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.005, 'avg_price' => 65000, 'profit' => 25.0]),
        ]);

        $this->assertCount(0, $result['closed']);
        $this->assertCount(1, $result['open']);

        $cycle = $result['open'][0];
        $this->assertEqualsWithDelta(0.02, $cycle['size'], 0.00001);
        $this->assertEqualsWithDelta(0.015, $cycle['remaining_size'], 0.00001);
        $this->assertEqualsWithDelta(25.0, $cycle['pnl'], 0.001);
        $this->assertCount(1, $cycle['exits']);
        $this->assertNull($cycle['closed_at']);
    }

    public function testScalingInRecalculatesWeightedEntryPrice(): void
    {
        $result = $this->reconstructor->reconstruct([
            $this->fill(['orderId' => '1', 'time' => 1000, 'executed_qty' => 0.01, 'avg_price' => 60000]),
            $this->fill(['orderId' => '2', 'time' => 1500, 'executed_qty' => 0.01, 'avg_price' => 62000]),
            $this->fill(['orderId' => '3', 'time' => 2000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.02, 'avg_price' => 65000, 'profit' => 80.0]),
        ]);

        $this->assertCount(1, $result['closed']);
        $cycle = $result['closed'][0];
        // Weighted average entry: (0.01*60000 + 0.01*62000) / 0.02 = 61000
        $this->assertEqualsWithDelta(61000.0, $cycle['entry_price'], 0.001);
        $this->assertEqualsWithDelta(0.02, $cycle['size'], 0.00001);
        $this->assertSame('bingx_position_1', $cycle['external_id']);
    }

    public function testMultiPartialThenFinalCloseProducesThreeExits(): void
    {
        $result = $this->reconstructor->reconstruct([
            $this->fill(['orderId' => 'O1', 'time' => 1000, 'executed_qty' => 0.10, 'avg_price' => 60000]),
            $this->fill(['orderId' => 'O2', 'time' => 1500, 'executed_qty' => 0.10, 'avg_price' => 62000]),
            $this->fill(['orderId' => 'C1', 'time' => 2000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.05, 'avg_price' => 65000, 'profit' => 200.0]),
            $this->fill(['orderId' => 'C2', 'time' => 2500, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.10, 'avg_price' => 66000, 'profit' => 500.0]),
            $this->fill(['orderId' => 'C3', 'time' => 3000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.05, 'avg_price' => 67000, 'profit' => 300.0]),
        ]);

        $this->assertCount(1, $result['closed']);
        $cycle = $result['closed'][0];
        $this->assertCount(3, $cycle['exits']);
        $this->assertEqualsWithDelta(0.20, $cycle['size'], 0.00001);
        $this->assertEqualsWithDelta(1000.0, $cycle['pnl'], 0.001);
        $this->assertSame(3000, $cycle['closed_at']);
        $this->assertSame('bingx_fill_C1', $cycle['exits'][0]['external_id']);
        $this->assertSame('bingx_fill_C2', $cycle['exits'][1]['external_id']);
        $this->assertSame('bingx_fill_C3', $cycle['exits'][2]['external_id']);
    }

    public function testHedgeModeLongAndShortInParallelAreIndependent(): void
    {
        $result = $this->reconstructor->reconstruct([
            // LONG open
            $this->fill(['orderId' => 'L1', 'positionSide' => 'LONG', 'side' => 'BUY', 'time' => 1000, 'executed_qty' => 0.01, 'avg_price' => 60000]),
            // SHORT open on the same symbol, same instant
            $this->fill(['orderId' => 'S1', 'positionSide' => 'SHORT', 'side' => 'SELL', 'time' => 1000, 'executed_qty' => 0.02, 'avg_price' => 60100]),
            // LONG full close
            $this->fill(['orderId' => 'L2', 'positionSide' => 'LONG', 'side' => 'SELL', 'reduce_only' => true, 'time' => 2000, 'executed_qty' => 0.01, 'avg_price' => 62000, 'profit' => 20.0]),
            // SHORT still open
        ]);

        $this->assertCount(1, $result['closed']);
        $this->assertCount(1, $result['open']);
        $this->assertSame('BUY', $result['closed'][0]['direction']);
        $this->assertSame('SELL', $result['open'][0]['direction']);
        $this->assertEqualsWithDelta(0.02, $result['open'][0]['remaining_size'], 0.00001);
    }

    public function testOrphanReduceOnlyFillIsIgnored(): void
    {
        // Reduce-only fill without a prior opening fill — happens when the
        // sync window doesn't reach back far enough to cover the original
        // open. We don't fabricate a cycle out of thin air.
        $result = $this->reconstructor->reconstruct([
            $this->fill(['orderId' => 'orphan', 'time' => 1000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.01, 'avg_price' => 65000, 'profit' => 50.0]),
        ]);

        $this->assertCount(0, $result['closed']);
        $this->assertCount(0, $result['open']);
    }

    public function testFillsAreSortedByTimeBeforeReconstruction(): void
    {
        // Caller might pass fills out of order across chunks/symbols — the
        // reconstructor sorts ASC by time per group before walking.
        $result = $this->reconstructor->reconstruct([
            $this->fill(['orderId' => 'close', 'time' => 2000, 'reduce_only' => true, 'side' => 'SELL', 'executed_qty' => 0.01, 'avg_price' => 62000, 'profit' => 20.0]),
            $this->fill(['orderId' => 'open', 'time' => 1000, 'executed_qty' => 0.01, 'avg_price' => 60000]),
        ]);

        $this->assertCount(1, $result['closed']);
        $cycle = $result['closed'][0];
        $this->assertSame('bingx_position_open', $cycle['external_id']);
        $this->assertSame(1000, $cycle['opened_at']);
        $this->assertSame(2000, $cycle['closed_at']);
    }
}
