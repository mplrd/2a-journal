<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PositionRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;
use App\Services\OrderService;
use PHPUnit\Framework\TestCase;

class OrderServiceTest extends TestCase
{
    private OrderService $service;
    private OrderRepository $orderRepo;
    private PositionRepository $positionRepo;
    private AccountRepository $accountRepo;
    private StatusHistoryRepository $historyRepo;
    private TradeRepository $tradeRepo;

    protected function setUp(): void
    {
        $this->orderRepo = $this->createMock(OrderRepository::class);
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->accountRepo = $this->createMock(AccountRepository::class);
        $this->historyRepo = $this->createMock(StatusHistoryRepository::class);
        $this->tradeRepo = $this->createMock(TradeRepository::class);
        $this->service = new OrderService(
            $this->orderRepo,
            $this->positionRepo,
            $this->accountRepo,
            $this->historyRepo,
            $this->tradeRepo
        );
    }

    private function fakeOrder(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'position_id' => 10,
            'order_created_at' => '2026-01-01 00:00:00',
            'expires_at' => null,
            'status' => 'PENDING',
            'user_id' => 1,
            'account_id' => 100,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => '18500.00000',
            'size' => '1.0000',
            'setup' => 'Breakout',
            'sl_points' => '50.00',
            'sl_price' => '18450.00000',
            'be_points' => null,
            'be_price' => null,
            'be_size' => null,
            'targets' => null,
            'notes' => null,
            'position_type' => 'ORDER',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    private function validCreateData(array $overrides = []): array
    {
        return array_merge([
            'account_id' => 100,
            'direction' => 'BUY',
            'symbol' => 'NASDAQ',
            'entry_price' => 18500,
            'size' => 1,
            'setup' => 'Breakout',
            'sl_points' => 50,
        ], $overrides);
    }

    private function fakeAccount(array $overrides = []): array
    {
        return array_merge([
            'id' => 100,
            'user_id' => 1,
            'name' => 'Test Account',
        ], $overrides);
    }

    // ── Create: success ─────────────────────────────────────────

    public function testCreateSuccess(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturn([
            'id' => 10, 'user_id' => 1, 'symbol' => 'NASDAQ',
        ]);
        $this->orderRepo->method('create')->willReturn($this->fakeOrder());
        $this->historyRepo->expects($this->once())->method('create');

        $result = $this->service->create(1, $this->validCreateData());

        $this->assertSame('NASDAQ', $result['symbol']);
        $this->assertSame('PENDING', $result['status']);
    }

    public function testCreateCalculatesPricesBuy(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            // Verify SL price calculation: BUY → entry - sl = 18500 - 50 = 18450
            $this->assertEquals(18450, $data['sl_price']);
            return ['id' => 10, 'user_id' => 1];
        });
        $this->orderRepo->method('create')->willReturn($this->fakeOrder());

        $this->service->create(1, $this->validCreateData());
    }

    public function testCreateCalculatesPricesSell(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            // SELL → entry + sl = 18500 + 50 = 18550
            $this->assertEquals(18550, $data['sl_price']);
            return ['id' => 10, 'user_id' => 1];
        });
        $this->orderRepo->method('create')->willReturn($this->fakeOrder());

        $this->service->create(1, $this->validCreateData(['direction' => 'SELL']));
    }

    public function testCreateWithBePoints(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            // BUY → be_price = entry + be = 18500 + 30 = 18530
            $this->assertEquals(18530, $data['be_price']);
            return ['id' => 10, 'user_id' => 1];
        });
        $this->orderRepo->method('create')->willReturn($this->fakeOrder());

        $this->service->create(1, $this->validCreateData(['be_points' => 30]));
    }

    public function testCreateWithTargets(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            $targets = json_decode($data['targets'], true);
            // BUY → target.price = entry + points
            $this->assertEquals(18600, $targets[0]['price']); // 18500 + 100
            return ['id' => 10, 'user_id' => 1];
        });
        $this->orderRepo->method('create')->willReturn($this->fakeOrder());

        $targets = [['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 0.5]];
        $this->service->create(1, $this->validCreateData(['targets' => $targets]));
    }

    // ── Create: validation errors ───────────────────────────────

    public function testCreateThrowsWhenAccountIdMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.field_required');

        $data = $this->validCreateData();
        unset($data['account_id']);
        $this->service->create(1, $data);
    }

    public function testCreateThrowsWhenAccountNotFound(): void
    {
        $this->accountRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('accounts.error.not_found');

        $this->service->create(1, $this->validCreateData());
    }

    public function testCreateThrowsWhenAccountNotOwned(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount(['user_id' => 999]));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('orders.error.account_forbidden');

        $this->service->create(1, $this->validCreateData());
    }

    public function testCreateThrowsWhenDirectionMissing(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.field_required');

        $data = $this->validCreateData();
        unset($data['direction']);
        $this->service->create(1, $data);
    }

    public function testCreateThrowsWhenDirectionInvalid(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.invalid_direction');

        $this->service->create(1, $this->validCreateData(['direction' => 'INVALID']));
    }

    public function testCreateThrowsWhenSymbolEmpty(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.field_required');

        $this->service->create(1, $this->validCreateData(['symbol' => '']));
    }

    public function testCreateThrowsWhenSymbolTooLong(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.invalid_symbol');

        $this->service->create(1, $this->validCreateData(['symbol' => str_repeat('X', 51)]));
    }

    public function testCreateThrowsWhenEntryPriceZero(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.invalid_price');

        $this->service->create(1, $this->validCreateData(['entry_price' => 0]));
    }

    public function testCreateThrowsWhenSizeZero(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.invalid_size');

        $this->service->create(1, $this->validCreateData(['size' => 0]));
    }

    public function testCreateThrowsWhenSetupEmpty(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.field_required');

        $this->service->create(1, $this->validCreateData(['setup' => '']));
    }

    public function testCreateThrowsWhenSlPointsZero(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.invalid_sl_points');

        $this->service->create(1, $this->validCreateData(['sl_points' => 0]));
    }

    public function testCreateThrowsWhenNotesTooLong(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.notes_too_long');

        $this->service->create(1, $this->validCreateData(['notes' => str_repeat('x', 10001)]));
    }

    public function testCreateThrowsWhenExpiresAtInPast(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.invalid_expires_at');

        $this->service->create(1, $this->validCreateData(['expires_at' => '2020-01-01 00:00:00']));
    }

    // ── Get ─────────────────────────────────────────────────────

    public function testGetReturnsOrder(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder());

        $result = $this->service->get(1, 1);

        $this->assertSame('NASDAQ', $result['symbol']);
        $this->assertSame('PENDING', $result['status']);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->orderRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('orders.error.not_found');

        $this->service->get(1, 999);
    }

    public function testGetThrowsForbiddenWhenNotOwner(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder(['user_id' => 2]));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('orders.error.forbidden');

        $this->service->get(1, 1);
    }

    public function testGetThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->get(1, 0);
    }

    // ── Cancel ──────────────────────────────────────────────────

    public function testCancelSuccess(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder());
        $this->orderRepo->method('updateStatus')->willReturn($this->fakeOrder(['status' => 'CANCELLED']));
        $this->historyRepo->expects($this->once())->method('create');

        $result = $this->service->cancel(1, 1);

        $this->assertSame('CANCELLED', $result['status']);
    }

    public function testCancelThrowsWhenNotPending(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder(['status' => 'CANCELLED']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.not_pending');

        $this->service->cancel(1, 1);
    }

    // ── Execute ─────────────────────────────────────────────────

    public function testExecuteSuccess(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder());
        $this->orderRepo->method('updateStatus')->willReturn($this->fakeOrder(['status' => 'EXECUTED']));
        $this->positionRepo->expects($this->once())->method('update')->with(10, $this->callback(function ($data) {
            return $data['position_type'] === 'TRADE';
        }));
        $this->tradeRepo->expects($this->once())->method('create')->with($this->callback(function ($data) {
            return $data['position_id'] === 10
                && $data['source_order_id'] === 1
                && $data['status'] === 'OPEN'
                && (float) $data['remaining_size'] === 1.0;
        }))->willReturn(['id' => 50, 'position_id' => 10, 'status' => 'OPEN']);
        $this->historyRepo->expects($this->exactly(2))->method('create');

        $result = $this->service->execute(1, 1);

        $this->assertSame('EXECUTED', $result['status']);
        $this->assertSame(50, $result['trade_id']);
    }

    public function testExecuteThrowsWhenNotPending(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder(['status' => 'EXECUTED']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('orders.error.not_pending');

        $this->service->execute(1, 1);
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeleteSuccess(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder());
        $this->positionRepo->expects($this->once())->method('delete')->with(10);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->fakeOrder(['user_id' => 2]));

        $this->expectException(ForbiddenException::class);

        $this->service->delete(1, 1);
    }

    // ── List ────────────────────────────────────────────────────

    public function testListReturnsOrders(): void
    {
        $orders = [$this->fakeOrder(), $this->fakeOrder(['id' => 2])];
        $this->orderRepo->method('findAllByUserId')->willReturn(['items' => $orders, 'total' => 2]);

        $result = $this->service->list(1);

        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['page']);
    }
}
