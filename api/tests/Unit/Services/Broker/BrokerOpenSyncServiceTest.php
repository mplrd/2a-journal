<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\ExitType;
use App\Enums\TradeStatus;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;
use App\Services\Broker\BrokerOpenSyncService;
use PHPUnit\Framework\TestCase;

class BrokerOpenSyncServiceTest extends TestCase
{
    private PositionRepository $positionRepo;
    private TradeRepository $tradeRepo;
    private BrokerOpenSyncService $service;

    protected function setUp(): void
    {
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->tradeRepo = $this->createMock(TradeRepository::class);
        $this->service = new BrokerOpenSyncService($this->positionRepo, $this->tradeRepo);
    }

    private function makeOpenSnapshot(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'BTCUSDT',
            'direction' => 'BUY',
            'entry_price' => 60000.0,
            'size' => 0.5,
            'sl_price' => 59000.0,
            'tp_price' => 62000.0,
            'opened_at' => '2026-05-07 08:00:00',
            'external_id' => 'ouinex_mp-1',
            'pnl' => null,
            'comment' => null,
        ], $overrides);
    }

    private function makeClosedSnapshot(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'BTCUSDT',
            'direction' => 'BUY',
            'entry_price' => 60000.0,
            'exit_price' => 61500.0,
            'size' => 0.5,
            'pnl' => 750.0,
            'opened_at' => '2026-05-07 08:00:00',
            'closed_at' => '2026-05-07 14:30:00',
            'external_id' => 'ouinex_mp-1',
        ], $overrides);
    }

    // ── INSERT path: new OPEN position not in DB ──────────────────

    public function testInsertsNewOpenPositionAsOpenTrade(): void
    {
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([]); // nothing yet in DB

        $this->positionRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                // Verify the position is built from the snapshot, not synthesized
                return $data['account_id'] === 5
                    && $data['user_id'] === 10
                    && $data['external_id'] === 'ouinex_mp-1'
                    && $data['direction'] === 'BUY'
                    && (float) $data['entry_price'] === 60000.0
                    && (float) $data['size'] === 0.5
                    && (float) $data['sl_price'] === 59000.0
                    && $data['position_type'] === 'TRADE'
                    && $data['import_batch_id'] === 99;
            }))
            ->willReturn(['id' => 1001]);

        $this->tradeRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                return $data['position_id'] === 1001
                    && $data['status'] === TradeStatus::OPEN->value
                    && (float) $data['remaining_size'] === 0.5
                    && $data['opened_at'] === '2026-05-07 08:00:00';
            }));

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()],
            closedSnapshot: [],
        );

        $this->assertSame(1, $stats['inserted']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['transitioned']);
    }

    // ── UPDATE path: position already in DB, still open in snapshot ──

    public function testUpdatesBrokerFieldsOfExistingOpenPositionPreservingMeta(): void
    {
        // The DB already has this position with a user-assigned setup + notes
        // and a trade OPEN. The snapshot reports the same external_id with a
        // slightly different SL (user moved it on Ouinex). Expected: update
        // the broker-driven fields (entry_price, size, sl_price, tp_price) but
        // NEVER touch setup, notes, or any meta the user owns.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001,
                    'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00',
                    'size' => '0.50000',
                    'sl_price' => '59000.00',
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->once())
            ->method('update')
            ->with(1001, $this->callback(function ($data) {
                // Whitelist: only broker-driven fields. Setup/notes MUST be absent.
                $allowed = ['entry_price', 'size', 'sl_price', 'tp_price', 'direction', 'symbol'];
                foreach (array_keys($data) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        return false;
                    }
                }
                return (float) $data['sl_price'] === 59500.0;
            }));

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(function ($data) {
                // remaining_size tracks broker size; status MUST remain OPEN.
                return (float) $data['remaining_size'] === 0.5
                    && !array_key_exists('status', $data);
            }));

        // Crucially: no create call (no duplication)
        $this->positionRepo->expects($this->never())->method('create');
        $this->tradeRepo->expects($this->never())->method('create');

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['sl_price' => 59500.0])],
            closedSnapshot: [],
        );

        $this->assertSame(0, $stats['inserted']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['transitioned']);
    }

    public function testUpdateRefreshesTheOpeningTimestampFromTheBroker(): void
    {
        // opened_at is broker-driven exactly like entry_price and size, but was
        // the one such field the update path never rewrote. A position already
        // known to the journal therefore kept its original timestamp forever —
        // so when the connector started reporting the user's local time instead
        // of UTC, every position already on file stayed two hours off, corrected
        // on every other column but that one.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001,
                    'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00',
                    'size' => '0.50000',
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(function ($data) {
                return $data['opened_at'] === '2026-08-05 07:29:00'
                    // Still an update, not a close.
                    && !array_key_exists('status', $data);
            }));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['opened_at' => '2026-08-05 07:29:00'])],
            closedSnapshot: [],
        );
    }

    public function testUpdateLeavesTheOpeningTimestampAloneWhenTheSnapshotHasNone(): void
    {
        // BingX's live snapshot carries no open time. Writing null there would
        // erase a timestamp the journal already holds.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001,
                    'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00',
                    'size' => '0.50000',
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => !array_key_exists('opened_at', $data)));

        $snapshot = $this->makeOpenSnapshot();
        unset($snapshot['opened_at']);

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$snapshot],
            closedSnapshot: [],
        );
    }

    // ── TRANSITION path: position was open, now appears in closed ──

    public function testTransitionsOpenToClosedWhenPositionAppearsInClosedSnapshot(): void
    {
        // DB has the position with a trade OPEN. Ouinex no longer reports it
        // in open_margin_positions, but it IS in closed_margin_positions for
        // this sync — meaning the position closed between syncs. Expected:
        // update the trade in place (OPEN → CLOSED) keeping setup/notes etc.,
        // do NOT delete and re-insert.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001,
                    'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00',
                    'size' => '0.50000',
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(function ($data) {
                return $data['status'] === TradeStatus::CLOSED->value
                    && (float) $data['avg_exit_price'] === 61500.0
                    && (float) $data['pnl'] === 750.0
                    && $data['closed_at'] === '2026-05-07 14:30:00'
                    && (float) $data['remaining_size'] === 0.0
                    && $data['exit_type'] === ExitType::MANUAL->value;
            }));

        // No position update needed for transition — only trade transitions.
        $this->positionRepo->expects($this->never())->method('update');
        $this->positionRepo->expects($this->never())->method('create');
        $this->tradeRepo->expects($this->never())->method('create');

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [], // not in live snapshot anymore
            closedSnapshot: [$this->makeClosedSnapshot()],
        );

        $this->assertSame(0, $stats['inserted']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(1, $stats['transitioned']);
    }

    public function testTransitionSumsEveryClosingRowOfTheSamePosition(): void
    {
        // A position closed in several goes (TP1 then the rest) shows up as
        // several rows sharing one external_id. Indexing them by id used to
        // keep only the last, so the trade inherited the final leg's P&L alone
        // and everything banked at TP1 silently vanished from the total.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ctrader_331' => [
                    'position_id' => 1001,
                    'external_id' => 'ctrader_331',
                    'entry_price' => '26386.34',
                    'size' => '2.50000',
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(function ($data) {
                // 103.27 banked at TP1 + 40.00 on the final leg.
                return abs((float) $data['pnl'] - 143.27) < 0.001
                    // Size-weighted across both legs: (1×26300 + 1.5×26350)/2.5
                    && abs((float) $data['avg_exit_price'] - 26330.0) < 0.001
                    // The position is done at the LAST fill, not the first.
                    && $data['closed_at'] === '2026-08-05 11:14:00'
                    && (float) $data['remaining_size'] === 0.0;
            }));

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::CTRADER,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [],
            closedSnapshot: [
                $this->makeClosedSnapshot([
                    'external_id' => 'ctrader_331', 'symbol' => 'GER40', 'direction' => 'SELL',
                    'entry_price' => 26386.34, 'exit_price' => 26300.0, 'size' => 1.0,
                    'pnl' => 103.27, 'closed_at' => '2026-08-05 08:01:12',
                ]),
                $this->makeClosedSnapshot([
                    'external_id' => 'ctrader_331', 'symbol' => 'GER40', 'direction' => 'SELL',
                    'entry_price' => 26386.34, 'exit_price' => 26350.0, 'size' => 1.5,
                    'pnl' => 40.0, 'closed_at' => '2026-08-05 11:14:00',
                ]),
            ],
        );

        $this->assertSame(1, $stats['transitioned']);
    }

    public function testTransitionRecordsEachClosingLegAsAPartialExit(): void
    {
        // The manual close path writes a partial_exits row for every exit, so
        // the broker path has to as well — otherwise a trade closed in two legs
        // keeps no trace of where the first one was taken. Dedup on
        // external_id: the TP1 was already recorded while the position was
        // still open, and must not be inserted twice.
        $partialExitRepo = $this->createMock(\App\Repositories\PartialExitRepository::class);
        $partialExitRepo->method('existingExternalIdsForTrade')
            ->willReturn(['ctrader_deal_11' => true]); // TP1 already banked

        $inserted = [];
        $partialExitRepo->method('create')
            ->willReturnCallback(function ($data) use (&$inserted) {
                $inserted[] = $data;
                return ['id' => count($inserted)];
            });

        $service = new BrokerOpenSyncService($this->positionRepo, $this->tradeRepo, $partialExitRepo);

        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ctrader_331' => [
                    'position_id' => 1001,
                    'external_id' => 'ctrader_331',
                    'entry_price' => '26386.34',
                    'size' => '2.50000',
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $service->apply(
            provider: \App\Enums\BrokerProvider::CTRADER,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [],
            closedSnapshot: [
                $this->makeClosedSnapshot([
                    'external_id' => 'ctrader_331', 'exit_external_id' => 'ctrader_deal_11',
                    'exit_price' => 26300.0, 'size' => 1.0, 'pnl' => 103.27,
                    'closed_at' => '2026-08-05 08:01:12',
                ]),
                $this->makeClosedSnapshot([
                    'external_id' => 'ctrader_331', 'exit_external_id' => 'ctrader_deal_12',
                    'exit_price' => 26350.0, 'size' => 1.5, 'pnl' => 40.0,
                    'closed_at' => '2026-08-05 11:14:00',
                ]),
            ],
        );

        $this->assertCount(1, $inserted, 'only the leg not already recorded should be inserted');
        $this->assertSame('ctrader_deal_12', $inserted[0]['external_id']);
        $this->assertEquals(1.5, $inserted[0]['size']);
        $this->assertEquals(40.0, $inserted[0]['pnl']);
    }

    // ── DEFENSIVE: orphan in DB, not in any snapshot ───────────────

    public function testLeavesOrphanOpenAloneWhenNotInAnySnapshot(): void
    {
        // DB has an open position but Ouinex doesn't report it in either
        // open or closed this sync. Could mean: API gap, pagination didn't
        // reach it, or a real anomaly. Defensive choice: don't delete, don't
        // mutate. Worst case the next sync picks it up correctly.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-orphan' => [
                    'position_id' => 2001,
                    'external_id' => 'ouinex_mp-orphan',
                    'entry_price' => '60000.00',
                    'size' => '0.10000',
                    'trade_id' => 6001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->never())->method('update');
        $this->positionRepo->expects($this->never())->method('create');
        $this->positionRepo->expects($this->never())->method('delete');
        $this->tradeRepo->expects($this->never())->method('update');
        $this->tradeRepo->expects($this->never())->method('create');
        $this->tradeRepo->expects($this->never())->method('delete');

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [],
            closedSnapshot: [],
        );

        $this->assertSame(0, $stats['inserted']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['transitioned']);
        $this->assertSame(1, $stats['skipped_orphans']);
    }

    // ── Mixed run: insert + update + transition in same call ──────

    public function testProcessesMixedSnapshotInOneCall(): void
    {
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-existing' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-existing',
                    'entry_price' => '60000', 'size' => '0.5',
                    'trade_id' => 5001, 'trade_status' => TradeStatus::OPEN->value,
                ],
                'ouinex_mp-now-closed' => [
                    'position_id' => 1002, 'external_id' => 'ouinex_mp-now-closed',
                    'entry_price' => '4000', 'size' => '1.0',
                    'trade_id' => 5002, 'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->once())->method('create')
            ->willReturn(['id' => 9999]);
        $this->positionRepo->expects($this->once())->method('update');
        $this->tradeRepo->expects($this->once())->method('create');
        $this->tradeRepo->expects($this->exactly(2))->method('update'); // update existing + transition

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [
                $this->makeOpenSnapshot(['external_id' => 'ouinex_mp-new']),       // INSERT
                $this->makeOpenSnapshot(['external_id' => 'ouinex_mp-existing']),  // UPDATE
            ],
            closedSnapshot: [
                $this->makeClosedSnapshot(['external_id' => 'ouinex_mp-now-closed']), // TRANSITION
            ],
        );

        $this->assertSame(1, $stats['inserted']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, $stats['transitioned']);
    }

    // ── Non-Ouinex positions must not be touched ──────────────────

    public function testNeverTouchesNonOuinexPositions(): void
    {
        // The DB also has manually-entered positions (no external_id) and
        // positions imported from a file (external_id 'ftmo_...'). The
        // service queries with prefix 'ouinex_' so the repo only returns
        // ouinex_-prefixed rows. This test verifies the contract: the
        // service uses the prefix to scope, doesn't iterate or touch
        // anything else.
        $this->positionRepo->expects($this->once())
            ->method('findOpenByExternalIdPrefixInAccount')
            ->with(5, 'ouinex_')
            ->willReturn([]);

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [],
            closedSnapshot: [],
        );
    }
}
