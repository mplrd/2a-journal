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

    public function testKeepsTheBrokerExternalIdInsteadOfHashingANewOne(): void
    {
        // A broker sync groups BY external_id, so the id is the position's
        // identity — hashing a fresh one from symbol/entry/close/size threw it
        // away and gave the same position two identities: ctrader_<positionId>
        // when the live diff inserts it as open, a hash when the import creates
        // it as closed. They never recognise each other, so a position seen
        // open and then closed lands twice. Worse, the hash folds in the close
        // date and total size, so a position closing in stages changes identity
        // at every sync.
        $rows = [
            ['symbol' => 'NASDAQ', 'direction' => 'SELL', 'entry_price' => 29950.23, 'exit_price' => 29200.0, 'size' => 5.0, 'pnl' => 3568.65, 'closed_at' => '2026-08-05 18:20:00', 'external_id' => 'ctrader_442'],
        ];

        $groups = $this->grouper->group($rows, ['external_id']);

        $this->assertSame('ctrader_442', $groups[0]['external_id']);
    }

    public function testKeepsOneIdentityWhenAPositionClosesInSeveralLegs(): void
    {
        // Same position, two closing legs: one row, one identity — and the
        // identity must not depend on how far the close has progressed.
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'SELL', 'entry_price' => 26386.34, 'exit_price' => 26300.0, 'size' => 1.0, 'pnl' => 103.27, 'closed_at' => '2026-08-05 10:01:12', 'external_id' => 'ctrader_331'],
            ['symbol' => 'GER40', 'direction' => 'SELL', 'entry_price' => 26386.34, 'exit_price' => 26350.0, 'size' => 1.5, 'pnl' => 40.0, 'closed_at' => '2026-08-05 13:14:00', 'external_id' => 'ctrader_331'],
        ];

        $groups = $this->grouper->group($rows, ['external_id']);

        $this->assertCount(1, $groups);
        $this->assertSame('ctrader_331', $groups[0]['external_id']);
    }

    public function testStillSynthesizesAnIdForRowsThatCarryNone(): void
    {
        // Spreadsheet imports have no broker id: the deterministic hash stays
        // their only way of being recognised across two imports of one file.
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 1.0, 'pnl' => 50, 'closed_at' => '2026-01-15 10:30:00'],
        ];

        $first = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);
        $second = $this->grouper->group($rows, ['symbol', 'direction', 'entry_price']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first[0]['external_id']);
        $this->assertSame($first[0]['external_id'], $second[0]['external_id']);
    }

    public function testCarriesThePerExitExternalIdIntoEachExit(): void
    {
        // Broker rows share one external_id per position, so the exits can only
        // be told apart — and dedup'd across syncs — by the per-leg id the
        // connector attaches.
        $rows = [
            ['symbol' => 'GER40', 'direction' => 'SELL', 'entry_price' => 26386.34, 'exit_price' => 26300.0, 'size' => 1.0, 'pnl' => 103.27, 'closed_at' => '2026-08-05 08:01:12', 'external_id' => 'ctrader_331', 'exit_external_id' => 'ctrader_deal_11'],
            ['symbol' => 'GER40', 'direction' => 'SELL', 'entry_price' => 26386.34, 'exit_price' => 26350.0, 'size' => 1.5, 'pnl' => 40.0, 'closed_at' => '2026-08-05 11:14:00', 'external_id' => 'ctrader_331', 'exit_external_id' => 'ctrader_deal_12'],
        ];

        $groups = $this->grouper->group($rows, ['external_id']);

        $this->assertCount(1, $groups);
        $this->assertSame(
            ['ctrader_deal_11', 'ctrader_deal_12'],
            array_column($groups[0]['exits'], 'external_id'),
        );
    }

    public function testLeavesTheExitExternalIdNullForFileImports(): void
    {
        // Spreadsheet rows carry no per-leg id — the key must simply be absent-
        // safe rather than blowing up or inventing one.
        $groups = $this->grouper->group(
            [['symbol' => 'GER40', 'direction' => 'BUY', 'entry_price' => 23400, 'exit_price' => 23450, 'size' => 1.0, 'pnl' => 50, 'closed_at' => '2026-01-15 10:30:00']],
            ['symbol', 'direction', 'entry_price'],
        );

        $this->assertNull($groups[0]['exits'][0]['external_id']);
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
