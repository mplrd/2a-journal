<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\ExitType;
use App\Enums\TradeStatus;
use App\Repositories\PartialExitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\TradeRepository;
use App\Services\Broker\BrokerOpenSyncService;
use PHPUnit\Framework\TestCase;

class BrokerOpenSyncServiceTest extends TestCase
{
    private PositionRepository $positionRepo;
    private TradeRepository $tradeRepo;
    private PartialExitRepository $partialExitRepo;
    private BrokerOpenSyncService $service;

    protected function setUp(): void
    {
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->tradeRepo = $this->createMock(TradeRepository::class);
        // No partial-exit repository by default: the legacy connectors emit no
        // exits[] and the tests below that do not exercise them must keep
        // behaving as they always have.
        $this->service = new BrokerOpenSyncService($this->positionRepo, $this->tradeRepo);
        $this->partialExitRepo = $this->createMock(PartialExitRepository::class);
    }

    /** The service as the broker connectors that walk fills get it. */
    private function useServiceWithPartialExits(): void
    {
        $this->service = new BrokerOpenSyncService(
            $this->positionRepo,
            $this->tradeRepo,
            $this->partialExitRepo,
        );
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
                // `targets` is on the list because a broker take profit fills
                // it — but only while empty, which the dedicated tests cover.
                $allowed = ['entry_price', 'size', 'sl_price', 'tp_price', 'direction', 'symbol', 'targets'];
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

    // ── Broker take profit → positions.targets ──────────────────────

    public function testInsertStoresTheBrokerTakeProfitAsATarget(): void
    {
        // The connectors normalize tp_price and nothing consumed it: there is
        // no tp_price column, objectives live in the positions.targets JSON.
        // The target keeps the shape the trade form writes — id, label, points,
        // price, size — so the UI renders a synced objective like any other.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')->willReturn([]);
        $this->tradeRepo->method('create')->willReturn(['id' => 5001]);

        $this->positionRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                $targets = json_decode($data['targets'] ?? '[]', true);
                return count($targets) === 1
                    && (float) $targets[0]['price'] === 62000.0
                    // Distance from entry, the unit the form edits in.
                    && abs((float) $targets[0]['points'] - 2000.0) < 0.001
                    && (float) $targets[0]['size'] === 0.5;
            }))
            ->willReturn(['id' => 1001]);

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()], // entry 60000, tp 62000, size 0.5
            closedSnapshot: [],
        );
    }

    public function testInsertNumbersStagedTakeProfitsInOrder(): void
    {
        // A connector that resolves a staged plan hands over `targets`, each
        // level with its own size. They become TP1, TP2, TP3 in the order they
        // arrive — the normalizer has already sorted them nearest-first.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')->willReturn([]);
        $this->tradeRepo->method('create')->willReturn(['id' => 5001]);

        $this->positionRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                $targets = json_decode($data['targets'] ?? '[]', true);
                return array_column($targets, 'label') === ['TP1', 'TP2', 'TP3']
                    && array_column($targets, 'id') === ['tp1', 'tp2', 'tp3']
                    && array_column($targets, 'size') === [0.2, 0.2, 0.1]
                    // Distance to entry, per level.
                    && abs($targets[0]['points'] - 500.0) < 0.001
                    && abs($targets[2]['points'] - 2500.0) < 0.001;
            }))
            ->willReturn(['id' => 1001]);

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot([
                'targets' => [
                    ['price' => 60500.0, 'size' => 0.2],
                    ['price' => 61500.0, 'size' => 0.2],
                    ['price' => 62500.0, 'size' => 0.1],
                ],
            ])],
            closedSnapshot: [],
        );
    }

    public function testUpdateNeverOverwritesTargetsTheUserEntered(): void
    {
        // Same contract as setup and notes: what the user typed is theirs. A
        // broker TP only fills the slot when it is empty.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001,
                    'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00',
                    'size' => '0.50000',
                    'direction' => 'BUY',
                    'targets' => '[{"id":"tp1","label":"TP1","points":1500,"price":61500,"size":0.25}]',
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->once())
            ->method('update')
            ->with(1001, $this->callback(fn($data) => !array_key_exists('targets', $data)));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()],
            closedSnapshot: [],
        );
    }

    public function testUpdateFillsEmptyTargetsFromTheBroker(): void
    {
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001,
                    'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00',
                    'size' => '0.50000',
                    'direction' => 'BUY',
                    'targets' => null,
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->once())
            ->method('update')
            ->with(1001, $this->callback(function ($data) {
                $targets = json_decode($data['targets'] ?? '[]', true);
                return count($targets) === 1 && (float) $targets[0]['price'] === 62000.0;
            }));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()],
            closedSnapshot: [],
        );
    }

    public function testUpdateRealignsAnObjectiveTheBrokerOwns(): void
    {
        // Moving a take profit on the platform has to reach the journal. The
        // guard used to be "write only into an empty slot", which froze a
        // synced objective exactly like a hand-typed one: once written, it was
        // never read again and the two drifted apart for good.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => '[{"id":"tp1","label":"TP1","points":1000,"price":61000,"size":0.5,"source":"broker"}]',
                    'trade_id' => 5001, 'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->once())
            ->method('update')
            ->with(1001, $this->callback(function ($data) {
                $targets = json_decode($data['targets'] ?? '[]', true);
                // The snapshot's 62000 replaces the stored 61000.
                return count($targets) === 1 && (float) $targets[0]['price'] === 62000.0;
            }));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()], // tp 62000
            closedSnapshot: [],
        );
    }

    public function testUpdateNeverTouchesAnObjectiveTheUserTyped(): void
    {
        // The other half of the contract, unchanged: what the user typed is
        // theirs. An objective with no broker marker is left exactly as it is.
        $typed = '[{"id":"tp1","label":"TP1","points":500,"price":60500,"size":0.5}]';

        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => $typed,
                    'trade_id' => 5001, 'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->once())
            ->method('update')
            ->with(1001, $this->callback(fn($data) => !array_key_exists('targets', $data)));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()],
            closedSnapshot: [],
        );
    }

    public function testUpdateClearsABrokerObjectiveTheBrokerHasRemoved(): void
    {
        // Deleting the take profit on the platform must delete it here too,
        // not leave the last known level standing.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => '[{"id":"tp1","label":"TP1","points":2000,"price":62000,"size":0.5,"source":"broker"}]',
                    'trade_id' => 5001, 'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->positionRepo->expects($this->once())
            ->method('update')
            ->with(1001, $this->callback(
                fn($data) => array_key_exists('targets', $data) && $data['targets'] === null,
            ));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['tp_price' => null])],
            closedSnapshot: [],
        );
    }

    // ── Realized P&L of a partial taken on the broker ───────────────

    public function testBanksTheRunningRealizedPnlWhenTheBrokerReportsAPartial(): void
    {
        $this->useServiceWithPartialExits();
        // A partial exit the sync discovers was written to partial_exits and
        // stopped there: trades.pnl stayed NULL until the position eventually
        // closed. Everything filtering on `pnl IS NOT NULL` — the KPI cards,
        // the P&L calendar — was therefore blind to money already banked.
        // Observed on 2026-08-11: a take profit of 406.13 taken at 20:29
        // appeared nowhere on that day's calendar.
        //
        // The manual path has always kept a running total ("realized metrics
        // are computed on EVERY exit so the running P&L is visible immediately
        // for swing trades"). The sync now does the same.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->partialExitRepo->method('existingExternalIdsForTrade')->willReturn([]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['pnl' => '120.50'],
            ['pnl' => '406.13'],
        ]);

        $banked = null;
        $this->tradeRepo->method('update')->willReturnCallback(
            function ($id, $data) use (&$banked) {
                if (array_key_exists('pnl', $data)) {
                    $banked = (float) $data['pnl'];
                }
                return null;
            },
        );

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot([
                'exits' => [[
                    'exit_price' => 60500.0, 'size' => 0.2, 'pnl' => 406.13,
                    'closed_at' => '2026-08-11 20:29:51', 'external_id' => 'ctrader_deal_1',
                ]],
            ])],
            closedSnapshot: [],
        );

        // Every leg on the trade, not just the one that arrived.
        $this->assertSame(526.63, $banked);
    }

    public function testBanksLegsThatAnEarlierPassAlreadyRecorded(): void
    {
        $this->useServiceWithPartialExits();
        // The rollup used to fire only in the pass that inserted a leg. Any
        // trade whose legs were already on file — every one of them when the
        // rollup shipped after the legs did — kept `pnl` NULL for good: no
        // later pass inserts anything, so nothing ever triggered it again.
        //
        // Observed on the test environment on 2026-08-12: trade 10059 carried
        // a 406.13 take profit taken the day before and `pnl` NULL, so
        // `pnl IS NOT NULL` hid it from every statistic, the calendar included.
        // The money was nowhere. Reconciling on each pass makes it self-healing.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001, 'sl_points' => null,
                    'pnl' => null, 'pnl_percent' => null, 'risk_reward' => null,
                    'trade_status' => TradeStatus::SECURED->value,
                ],
            ]);

        // Nothing new to insert: the leg is already on file.
        $this->partialExitRepo->method('existingExternalIdsForTrade')
            ->willReturn(['ctrader_deal_1' => true]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([['pnl' => '406.13']]);

        $banked = null;
        $this->tradeRepo->method('update')->willReturnCallback(
            function ($id, $data) use (&$banked) {
                if (array_key_exists('pnl', $data)) {
                    $banked = (float) $data['pnl'];
                }
                return null;
            },
        );

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot([
                'exits' => [[
                    'exit_price' => 60500.0, 'size' => 0.2, 'pnl' => 406.13,
                    'closed_at' => '2026-08-11 20:29:51', 'external_id' => 'ctrader_deal_1',
                ]],
            ])],
            closedSnapshot: [],
        );

        $this->assertSame(406.13, $banked);
    }

    public function testBanksThePercentAndRiskRewardAlongsideTheRealizedTotal(): void
    {
        $this->useServiceWithPartialExits();
        // Win, loss and breakeven are classified on `pnl_percent`, never on
        // `pnl`. Banking the total alone put the trade in the win-rate
        // denominator and in none of its buckets — a trade counted but
        // classified nowhere. The three figures the manual path writes on every
        // exit are written here too, from the same formulas.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001, 'sl_points' => '1000.00',
                    'pnl' => null, 'pnl_percent' => null, 'risk_reward' => null,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->partialExitRepo->method('existingExternalIdsForTrade')->willReturn([]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([['pnl' => '300.00']]);

        $written = null;
        $this->tradeRepo->method('update')->willReturnCallback(
            function ($id, $data) use (&$written) {
                if (array_key_exists('pnl', $data)) {
                    $written = $data;
                }
                return null;
            },
        );

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot([
                'exits' => [[
                    'exit_price' => 60500.0, 'size' => 0.2, 'pnl' => 300.0,
                    'closed_at' => '2026-08-11 20:29:51', 'external_id' => 'ctrader_deal_1',
                ]],
            ])],
            closedSnapshot: [],
        );

        $this->assertSame(300.0, (float) $written['pnl']);
        // 300 / (60000 * 0.5) * 100
        $this->assertSame(1.0, (float) $written['pnl_percent']);
        // 300 / (0.5 * 1000)
        $this->assertSame(0.6, (float) $written['risk_reward']);
    }

    public function testDoesNotRewriteTheTradeWhenTheRealizedTotalHasNotMoved(): void
    {
        $this->useServiceWithPartialExits();
        // The scheduler ticks every minute and re-reports the same legs. The
        // rollup now runs on every pass, so what keeps it from being a write
        // per tick per position is the comparison, not a gate on new arrivals:
        // an unchanged figure is not written back.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001, 'sl_points' => null,
                    'pnl' => '406.13', 'pnl_percent' => '1.3538', 'risk_reward' => null,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        // Already on file, so insertPartialExits skips it.
        $this->partialExitRepo->method('existingExternalIdsForTrade')
            ->willReturn(['ctrader_deal_1' => true]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([['pnl' => '406.13']]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => !array_key_exists('pnl', $data)));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot([
                'exits' => [[
                    'exit_price' => 60500.0, 'size' => 0.2, 'pnl' => 406.13,
                    'closed_at' => '2026-08-11 20:29:51', 'external_id' => 'ctrader_deal_1',
                ]],
            ])],
            closedSnapshot: [],
        );
    }

    public function testDoesNotBankAnythingOnAPositionThatHasTakenNothingOff(): void
    {
        $this->useServiceWithPartialExits();
        // A position with no leg has realized nothing, and `pnl` NULL is what
        // says so. Writing a zero would enrol every untouched open position in
        // the statistics as a breakeven trade.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001, 'sl_points' => null,
                    'pnl' => null, 'pnl_percent' => null, 'risk_reward' => null,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->partialExitRepo->method('existingExternalIdsForTrade')->willReturn([]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => !array_key_exists('pnl', $data)));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()],
            closedSnapshot: [],
        );
    }

    public function testTheBrokersClosingTotalIsNotReplacedByTheSumOfItsLegs(): void
    {
        $this->useServiceWithPartialExits();
        // On a close the broker states the position's total, which accounts for
        // swap and commission the individual legs may not carry. That figure is
        // authoritative and the rollup must not overwrite it.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);
        $this->partialExitRepo->method('existingExternalIdsForTrade')->willReturn([]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([['pnl' => '700.00']]);

        $seen = [];
        $this->tradeRepo->method('update')->willReturnCallback(
            function ($id, $data) use (&$seen) {
                if (array_key_exists('pnl', $data)) {
                    $seen[] = (float) $data['pnl'];
                }
                return null;
            },
        );

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [],
            closedSnapshot: [$this->makeClosedSnapshot([
                'pnl' => 742.31,
                'exits' => [[
                    'exit_price' => 61500.0, 'size' => 0.5, 'pnl' => 700.00,
                    'closed_at' => '2026-05-07 14:30:00', 'external_id' => 'ouinex_leg-1',
                ]],
            ])],
        );

        $this->assertSame([742.31], $seen, "the broker's total is the last word on a close");
    }

    // ── Stop loss at break-even → SECURED ───────────────────────────

    public function testPromotesToSecuredWhenTheStopReachesTheEntry(): void
    {
        // Moving the stop to entry is what actually takes the risk off, and the
        // broker reports it as a level we already sync. Detecting it here saves
        // the user from re-declaring on the journal what the platform knows.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001,
                    'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00',
                    'size' => '0.50000',
                    'direction' => 'BUY',
                    'targets' => null,
                    'trade_id' => 5001,
                    'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => ($data['status'] ?? null) === TradeStatus::SECURED->value));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['sl_price' => 60000.0])],
            closedSnapshot: [],
        );
    }

    public function testSecuresAPositionThatIsAlreadyProtectedWhenFirstSeen(): void
    {
        // The gap this closes: the promotion lived only on the update path, so
        // a position whose stop already protects the entry the first time we
        // see it was filed as OPEN and stayed wrong until the next pass.
        //
        // Which pass got it right was effectively arbitrary — a position the
        // closed-deals import had already materialised earlier in the same run
        // was found existing and promoted, one that arrived only in the open
        // snapshot was not. Observed on the test environment on 2026-08-11:
        // two DAX positions correct, a NAS position with the same stop-to-entry
        // relationship reported OPEN for twenty minutes.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')->willReturn([]);
        $this->positionRepo->method('create')->willReturn(['id' => 1001]);

        $this->tradeRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(
                fn($data) => ($data['status'] ?? null) === TradeStatus::SECURED->value
                    && ($data['be_reached'] ?? null) === 1,
            ));

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['sl_price' => 60000.0])],
            closedSnapshot: [],
        );

        $this->assertSame(1, $stats['inserted']);
    }

    public function testInsertLeavesAPositionOpenWhenItsStopStillCarriesRisk(): void
    {
        // The mirror of the test above: promoting on insert must not promote
        // everything. The default snapshot's stop sits 1000 below a long entry.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')->willReturn([]);
        $this->positionRepo->method('create')->willReturn(['id' => 1001]);

        $this->tradeRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(
                fn($data) => ($data['status'] ?? null) === TradeStatus::OPEN->value
                    && !array_key_exists('be_reached', $data),
            ));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot()],
            closedSnapshot: [],
        );
    }

    public function testSecuresAShortOnInsertOnceItsStopIsBelowEntry(): void
    {
        // Direction inverts the comparison on the insert path too — this is the
        // exact shape of the NAS position that went unflagged.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')->willReturn([]);
        $this->positionRepo->method('create')->willReturn(['id' => 1001]);

        $this->tradeRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(
                fn($data) => ($data['status'] ?? null) === TradeStatus::SECURED->value,
            ));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot([
                'direction' => 'SELL',
                'entry_price' => 29843.43,
                'sl_price' => 29840.84,
            ])],
            closedSnapshot: [],
        );
    }

    public function testPromotesToSecuredWhenTheStopLocksInProfit(): void
    {
        // A stop pushed past entry guarantees a gain — same single status, per
        // the product call: "no more risk" is one state.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001, 'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => ($data['status'] ?? null) === TradeStatus::SECURED->value));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['sl_price' => 60500.0])],
            closedSnapshot: [],
        );
    }

    public function testDoesNotSecureAShortWhoseStopIsStillAboveEntry(): void
    {
        // Direction inverts the comparison: a short is protected once its stop
        // drops TO or BELOW the entry.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'SELL',
                    'targets' => null, 'trade_id' => 5001, 'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => !array_key_exists('status', $data)));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['direction' => 'SELL', 'sl_price' => 60500.0])],
            closedSnapshot: [],
        );
    }

    public function testLeavesTheStatusAloneWithoutAStopLoss(): void
    {
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001, 'trade_status' => TradeStatus::OPEN->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => !array_key_exists('status', $data)));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['sl_price' => null])],
            closedSnapshot: [],
        );
    }

    public function testNeverDemotesATradeAlreadySecured(): void
    {
        // A trade can also be secured by hand, through a BE exit that recorded
        // a partial. Pulling the stop back on the platform must not erase that
        // decision — the sync only ever promotes.
        $this->positionRepo->method('findOpenByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_mp-1' => [
                    'position_id' => 1001, 'external_id' => 'ouinex_mp-1',
                    'entry_price' => '60000.00', 'size' => '0.50000', 'direction' => 'BUY',
                    'targets' => null, 'trade_id' => 5001,
                    'trade_status' => TradeStatus::SECURED->value,
                ],
            ]);

        $this->tradeRepo->expects($this->once())
            ->method('update')
            ->with(5001, $this->callback(fn($data) => !array_key_exists('status', $data)));

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10, accountId: 5, batchId: 99,
            openSnapshot: [$this->makeOpenSnapshot(['sl_price' => 59000.0])],
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
