<?php

namespace Tests\Unit\Services\Import;

use App\Services\Import\RowGroupingService;
use PHPUnit\Framework\TestCase;

class RowGroupingServiceTest extends TestCase
{
    private RowGroupingService $grouper;

    protected function setUp(): void
    {
        $this->grouper = new RowGroupingService();
    }

    public function testGroupsRowsByCompositeKey(): void
    {
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 0.5, 'pnl' => 25, 'closed_at' => '2026-01-15 10:30:00'],
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23480, 'size' => 0.3, 'pnl' => 24, 'closed_at' => '2026-01-15 11:00:00'],
            ['symbol' => 'GER40', 'direction' => 'SELL', 'entry_price' => 23500, 'exit_price' => 23450, 'size' => 1.0, 'pnl' => 50, 'closed_at' => '2026-01-16 09:15:00'],
        ];
        $key = ['symbol', 'direction', 'entry_price'];

        $groups = $this->grouper->group($rows, $key);

        $this->assertCount(2, $groups);
    }

    public function testSingleRowPositionHasOneExit(): void
    {
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'SELL', 'entry_price' => 23500, 'exit_price' => 23450, 'size' => 1.0, 'pnl' => 50, 'closed_at' => '2026-01-16 09:15:00'],
        ];

        $groups = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);

        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['exits']);
        $this->assertEquals(1.0, $groups[0]['total_size']);
        $this->assertEquals(50, $groups[0]['total_pnl']);
    }

    public function testMultipleRowsAggregatesCorrectly(): void
    {
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 0.5, 'pnl' => 25, 'closed_at' => '2026-01-15 10:30:00', 'pips' => 50],
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23480, 'size' => 0.3, 'pnl' => 24, 'closed_at' => '2026-01-15 11:00:00', 'pips' => 80],
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23350, 'size' => 0.2, 'pnl' => -10, 'closed_at' => '2026-01-15 14:30:00', 'pips' => -50],
        ];

        $groups = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);

        $this->assertCount(1, $groups);
        $pos = $groups[0];
        $this->assertEquals(1.0, $pos['total_size']);
        $this->assertEquals(39.0, $pos['total_pnl']);
        $this->assertCount(3, $pos['exits']);
    }

    public function testCalculatesWeightedAvgExitPrice(): void
    {
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 0.5, 'pnl' => 25, 'closed_at' => '2026-01-15 10:30:00'],
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23480, 'size' => 0.5, 'pnl' => 40, 'closed_at' => '2026-01-15 11:00:00'],
        ];

        $groups = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);

        // Weighted avg: (23450*0.5 + 23480*0.5) / (0.5+0.5) = 23465
        $this->assertEquals(23465.0, $groups[0]['avg_exit_price']);
    }

    public function testUsesLatestClosedAtForPosition(): void
    {
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 0.5, 'pnl' => 25, 'closed_at' => '2026-01-15 10:30:00'],
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23480, 'size' => 0.3, 'pnl' => 24, 'closed_at' => '2026-01-15 14:30:00'],
        ];

        $groups = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);

        $this->assertSame('2026-01-15 14:30:00', $groups[0]['closed_at']);
        $this->assertSame('2026-01-15 10:30:00', $groups[0]['opened_at']);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->grouper->group([], ['symbol', 'direction', 'entry_price']));
    }

    public function testPreservesCommentFromFirstNonEmptyRow(): void
    {
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 0.5, 'pnl' => 25, 'closed_at' => '2026-01-15 10:30:00', 'comment' => ''],
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23480, 'size' => 0.3, 'pnl' => 24, 'closed_at' => '2026-01-15 11:00:00', 'comment' => 'my note'],
        ];

        $groups = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);

        $this->assertSame('my note', $groups[0]['comment']);
    }

    public function testGeneratesExternalId(): void
    {
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 0.5, 'pnl' => 25, 'closed_at' => '2026-01-15 10:30:00'],
        ];

        $groups = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);

        $this->assertNotEmpty($groups[0]['external_id']);
        $this->assertSame(64, strlen($groups[0]['external_id'])); // SHA-256 hex
    }
}
