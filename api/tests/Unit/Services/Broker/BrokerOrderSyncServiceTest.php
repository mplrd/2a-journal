<?php

namespace Tests\Unit\Services\Broker;

use App\Enums\OrderStatus;
use App\Enums\PositionType;
use App\Repositories\OrderRepository;
use App\Repositories\PositionRepository;
use App\Services\Broker\BrokerOrderSyncService;
use PHPUnit\Framework\TestCase;

class BrokerOrderSyncServiceTest extends TestCase
{
    private OrderRepository $orderRepo;
    private PositionRepository $positionRepo;
    private BrokerOrderSyncService $service;

    protected function setUp(): void
    {
        $this->orderRepo = $this->createMock(OrderRepository::class);
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->service = new BrokerOrderSyncService($this->orderRepo, $this->positionRepo);
    }

    private function makeOpenOrderSnapshot(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'BTCUSDT',
            'direction' => 'BUY',
            'entry_price' => 58000.0,
            'size' => 0.5,
            'sl_price' => 57000.0,
            'tp_price' => 62000.0,
            'expires_at' => '2026-06-01 00:00:00',
            'created_at' => '2026-05-07 08:00:00',
            'external_id' => 'ouinex_order_ord-1',
        ], $overrides);
    }

    // ── INSERT path: new order pending not yet in DB ──────────────

    public function testInsertsNewPendingOrderAsPositionOrderPlusOrder(): void
    {
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([]);
        $closedOrdersSnapshot = [];

        // Insert flow: position with position_type=ORDER, then order PENDING
        // referencing it. The position holds the broker's intent (symbol/
        // direction/entry/size/SL), the order holds the lifecycle state.
        $this->positionRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                return $data['account_id'] === 5
                    && $data['user_id'] === 10
                    && $data['external_id'] === 'ouinex_order_ord-1'
                    && $data['position_type'] === PositionType::ORDER->value
                    && (float) $data['entry_price'] === 58000.0
                    && (float) $data['size'] === 0.5
                    && (float) $data['sl_price'] === 57000.0
                    && $data['import_batch_id'] === 99;
            }))
            ->willReturn(['id' => 2001]);

        $this->orderRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                return $data['position_id'] === 2001
                    && $data['status'] === OrderStatus::PENDING->value
                    && $data['expires_at'] === '2026-06-01 00:00:00';
            }));

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [$this->makeOpenOrderSnapshot()],
            closedOrdersSnapshot: $closedOrdersSnapshot,
        );

        $this->assertSame(1, $stats['inserted']);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['cancelled']);
    }

    // ── UPDATE path: order still pending, broker fields refresh ───

    public function testUpdatesBrokerFieldsOfExistingPendingOrderPreservingMeta(): void
    {
        // The user adjusts an order's trigger price on Ouinex. Expected:
        // refresh entry_price/size/SL on the position; setup/notes/custom
        // are NEVER touched. The order row only carries lifecycle state,
        // so it doesn't usually need an update unless expires_at changed.
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_order_ord-1' => [
                    'order_id' => 7001,
                    'position_id' => 2001,
                    'external_id' => 'ouinex_order_ord-1',
                    'expires_at' => '2026-06-01 00:00:00',
                ],
            ]);

        $this->positionRepo->expects($this->once())
            ->method('update')
            ->with(2001, $this->callback(function ($data) {
                $allowed = ['entry_price', 'size', 'sl_price', 'direction', 'symbol'];
                foreach (array_keys($data) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        return false;
                    }
                }
                return (float) $data['entry_price'] === 57500.0;
            }));

        // No create — already exists.
        $this->positionRepo->expects($this->never())->method('create');
        $this->orderRepo->expects($this->never())->method('create');

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [$this->makeOpenOrderSnapshot(['entry_price' => 57500.0])],
            closedOrdersSnapshot: [],
        );

        $this->assertSame(0, $stats['inserted']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['cancelled']);
    }

    // ── Disappearance with no closed_orders signal → default CANCELLED ──

    public function testKeepsCancelledDefaultWhenNotInClosedSnapshot(): void
    {
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_order_gone' => [
                    'order_id' => 7002, 'position_id' => 2002,
                    'external_id' => 'ouinex_order_gone', 'expires_at' => null,
                ],
            ]);

        $this->orderRepo->expects($this->once())
            ->method('updateStatus')
            ->with(7002, OrderStatus::CANCELLED->value);

        $this->positionRepo->expects($this->never())->method('delete');

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [],
            closedOrdersSnapshot: [], // no info → conservative CANCELLED
        );

        $this->assertSame(1, $stats['cancelled']);
        $this->assertSame(0, $stats['executed']);
        $this->assertSame(0, $stats['expired']);
    }

    // ── Disappearance with closed_orders EXECUTED → mark EXECUTED ──

    public function testMarksOrderExecutedWhenInClosedSnapshotAsExecuted(): void
    {
        // The order disappeared from open_orders AND appears in
        // closed_orders with final_status=EXECUTED → status flips to
        // EXECUTED (the order triggered into a trade). The corresponding
        // margin_position is ingested separately by BrokerOpenSyncService
        // under its own external_id (ouinex_<margin_position_id>); we
        // don't try to glue them at this layer for Phase 1.
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_order_filled' => [
                    'order_id' => 7003, 'position_id' => 2003,
                    'external_id' => 'ouinex_order_filled', 'expires_at' => null,
                ],
            ]);

        $this->orderRepo->expects($this->once())
            ->method('updateStatus')
            ->with(7003, OrderStatus::EXECUTED->value);

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [],
            closedOrdersSnapshot: [
                ['external_id' => 'ouinex_order_filled', 'final_status' => 'EXECUTED'],
            ],
        );

        $this->assertSame(1, $stats['executed']);
        $this->assertSame(0, $stats['cancelled']);
        $this->assertSame(0, $stats['expired']);
    }

    // ── Disappearance with closed_orders EXPIRED → mark EXPIRED ──

    public function testMarksOrderExpiredWhenInClosedSnapshotAsExpired(): void
    {
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_order_expired' => [
                    'order_id' => 7004, 'position_id' => 2004,
                    'external_id' => 'ouinex_order_expired', 'expires_at' => '2026-05-01 00:00:00',
                ],
            ]);

        $this->orderRepo->expects($this->once())
            ->method('updateStatus')
            ->with(7004, OrderStatus::EXPIRED->value);

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [],
            closedOrdersSnapshot: [
                ['external_id' => 'ouinex_order_expired', 'final_status' => 'EXPIRED'],
            ],
        );

        $this->assertSame(1, $stats['expired']);
        $this->assertSame(0, $stats['cancelled']);
    }

    // ── closed_orders CANCELLED explicitly → CANCELLED with confirmation ──

    public function testMarksCancelledWhenInClosedSnapshotAsCancelled(): void
    {
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_order_user_killed' => [
                    'order_id' => 7005, 'position_id' => 2005,
                    'external_id' => 'ouinex_order_user_killed', 'expires_at' => null,
                ],
            ]);

        $this->orderRepo->expects($this->once())
            ->method('updateStatus')
            ->with(7005, OrderStatus::CANCELLED->value);

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [],
            closedOrdersSnapshot: [
                ['external_id' => 'ouinex_order_user_killed', 'final_status' => 'CANCELLED'],
            ],
        );

        $this->assertSame(1, $stats['cancelled']);
    }

    // ── Mixed run: insert + update + cancel ───────────────────────

    public function testProcessesMixedOrderSnapshotInOneCall(): void
    {
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_order_existing' => [
                    'order_id' => 7001, 'position_id' => 2001,
                    'external_id' => 'ouinex_order_existing', 'expires_at' => null,
                ],
                'ouinex_order_gone' => [
                    'order_id' => 7002, 'position_id' => 2002,
                    'external_id' => 'ouinex_order_gone', 'expires_at' => null,
                ],
            ]);

        $this->positionRepo->expects($this->once())->method('create')
            ->willReturn(['id' => 9999]);
        $this->orderRepo->expects($this->once())->method('create');
        $this->positionRepo->expects($this->once())->method('update');
        $this->orderRepo->expects($this->once())->method('updateStatus')
            ->with(7002, OrderStatus::CANCELLED->value);

        $stats = $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [
                $this->makeOpenOrderSnapshot(['external_id' => 'ouinex_order_new']),
                $this->makeOpenOrderSnapshot(['external_id' => 'ouinex_order_existing']),
                // gone is missing → CANCELLED by default
            ],
            closedOrdersSnapshot: [],
        );

        $this->assertSame(1, $stats['inserted']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, $stats['cancelled']);
    }

    // ── Scope by prefix: non-Ouinex orders untouched ─────────────

    public function testQueriesRepoWithOuinexOrderPrefix(): void
    {
        // The diff is strictly scoped to ouinex_order_-prefixed rows; manual
        // orders and other providers' orders are invisible.
        $this->orderRepo->expects($this->once())
            ->method('findPendingByExternalIdPrefixInAccount')
            ->with(5, 'ouinex_order_')
            ->willReturn([]);

        $this->service->apply(
            provider: \App\Enums\BrokerProvider::OUINEX,
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [],
            closedOrdersSnapshot: [],
        );
    }
}
