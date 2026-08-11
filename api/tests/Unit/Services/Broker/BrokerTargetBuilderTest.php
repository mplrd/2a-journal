<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\BrokerTargetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The one place that turns a broker snapshot's objectives into the
 * positions.targets JSON. Shared by the open-position diff and the pending
 * order diff — an order's take profit was normalized and then dropped,
 * because only the position path knew how to write one.
 */
class BrokerTargetBuilderTest extends TestCase
{
    /**
     * Decoded, with every number cast back to float.
     *
     * json_encode writes a whole float as an integer literal (4000.0 becomes
     * 4000), so a round trip changes the PHP type. That is a property of JSON,
     * not of the builder, and the frontend reads these through Number()
     * anyway — the tests compare values, not the type JSON happened to pick.
     *
     * @return list<array<string, mixed>>
     */
    private function decode(?string $json): array
    {
        $this->assertNotNull($json);

        return array_map(
            fn(array $target) => array_map(
                fn($value) => is_int($value) ? (float) $value : $value,
                $target,
            ),
            json_decode($json, true),
        );
    }

    public function testBuildsNothingWithoutAnyObjective(): void
    {
        $this->assertNull(BrokerTargetBuilder::fromSnapshot([
            'entry_price' => 58000.0,
            'size' => 0.5,
        ]));
    }

    public function testIgnoresATakeProfitOfZero(): void
    {
        // Connectors report "no take profit" as 0 rather than omitting it.
        $this->assertNull(BrokerTargetBuilder::fromSnapshot([
            'entry_price' => 58000.0,
            'size' => 0.5,
            'tp_price' => 0.0,
        ]));
    }

    public function testASingleTakeProfitCoversTheWholeRow(): void
    {
        // The shape the trade form writes, so a synced objective renders like
        // a typed one. points is the distance to entry, the unit the form edits.
        $targets = $this->decode(BrokerTargetBuilder::fromSnapshot([
            'entry_price' => 58000.0,
            'size' => 0.5,
            'tp_price' => 62000.0,
        ]));

        $this->assertSame([[
            'id' => 'tp1',
            'label' => 'TP1',
            'points' => 4000.0,
            'price' => 62000.0,
            'size' => 0.5,
        ]], $targets);
    }

    public function testASingleTakeProfitIsSizedOnWhatRemains(): void
    {
        // Same rule as the cTrader staged plan: an objective closes the row as
        // it stands now, not as it was before it was trimmed.
        $targets = $this->decode(BrokerTargetBuilder::fromSnapshot([
            'entry_price' => 58000.0,
            'size' => 2.5,
            'remaining_size' => 1.0,
            'tp_price' => 62000.0,
        ]));

        $this->assertSame(1.0, $targets[0]['size']);
    }

    public function testStagedLevelsAreNumberedInTheOrderTheyArrive(): void
    {
        // The normalizer has already sorted them nearest-first.
        $targets = $this->decode(BrokerTargetBuilder::fromSnapshot([
            'entry_price' => 26386.34,
            'size' => 2.5,
            'targets' => [
                ['price' => 26450.0, 'size' => 1.0],
                ['price' => 26600.0, 'size' => 1.0],
                ['price' => 26900.0, 'size' => 0.5],
            ],
        ]));

        $this->assertSame(['TP1', 'TP2', 'TP3'], array_column($targets, 'label'));
        $this->assertSame([1.0, 1.0, 0.5], array_column($targets, 'size'));
    }

    public function testPointsAreADistanceWhicheverWayTheTradeGoes(): void
    {
        // A short takes profit below its entry: the distance is still positive.
        $targets = $this->decode(BrokerTargetBuilder::fromSnapshot([
            'entry_price' => 26415.24,
            'size' => 1.0,
            'tp_price' => 26000.0,
        ]));

        $this->assertSame(415.24, $targets[0]['points']);
    }
}
