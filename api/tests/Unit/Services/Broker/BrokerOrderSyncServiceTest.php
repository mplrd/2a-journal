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
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [$this->makeOpenOrderSnapshot()],
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
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [$this->makeOpenOrderSnapshot(['entry_price' => 57500.0])],
        );

        $this->assertSame(0, $stats['inserted']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['cancelled']);
    }

    // ── CANCELLED path: order disappears from snapshot ────────────

    public function testMarksOrderCancelledWhenItDisappearsFromSnapshot(): void
    {
        // The pending order is no longer in the broker's open_orders snapshot.
        // For Paquet B, default policy is to mark CANCELLED — that's the
        // conservative interpretation. Paquet C will refine this by cross-
        // checking closed_orders to distinguish EXECUTED from CANCELLED.
        $this->orderRepo->method('findPendingByExternalIdPrefixInAccount')
            ->willReturn([
                'ouinex_order_gone' => [
                    'order_id' => 7002,
                    'position_id' => 2002,
                    'external_id' => 'ouinex_order_gone',
                    'expires_at' => null,
                ],
            ]);

        $this->orderRepo->expects($this->once())
            ->method('updateStatus')
            ->with(7002, OrderStatus::CANCELLED->value);

        // We don't delete the position — keep the trace of the cancelled
        // order for the user (with their setup/notes intact).
        $this->positionRepo->expects($this->never())->method('delete');
        $this->positionRepo->expects($this->never())->method('update');

        $stats = $this->service->apply(
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [],
        );

        $this->assertSame(0, $stats['inserted']);
        $this->assertSame(0, $stats['updated']);
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
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [
                $this->makeOpenOrderSnapshot(['external_id' => 'ouinex_order_new']),
                $this->makeOpenOrderSnapshot(['external_id' => 'ouinex_order_existing']),
                // gone is missing → CANCELLED
            ],
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
            userId: 10,
            accountId: 5,
            batchId: 99,
            openOrdersSnapshot: [],
        );
    }
}
