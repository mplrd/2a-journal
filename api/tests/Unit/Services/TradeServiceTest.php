<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\PositionRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;
use App\Services\TradeService;
use PHPUnit\Framework\TestCase;

class TradeServiceTest extends TestCase
{
    private TradeService $service;
    private TradeRepository $tradeRepo;
    private PartialExitRepository $partialExitRepo;
    private PositionRepository $positionRepo;
    private AccountRepository $accountRepo;
    private StatusHistoryRepository $historyRepo;

    protected function setUp(): void
    {
        $this->tradeRepo = $this->createMock(TradeRepository::class);
        $this->partialExitRepo = $this->createMock(PartialExitRepository::class);
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->accountRepo = $this->createMock(AccountRepository::class);
        $this->historyRepo = $this->createMock(StatusHistoryRepository::class);
        $this->service = new TradeService(
            $this->tradeRepo,
            $this->partialExitRepo,
            $this->positionRepo,
            $this->accountRepo,
            $this->historyRepo
        );
    }

    private function fakeTrade(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'position_id' => 10,
            'source_order_id' => null,
            'opened_at' => '2026-01-15 10:00:00',
            'closed_at' => null,
            'remaining_size' => '1.0000',
            'be_reached' => 0,
            'avg_exit_price' => null,
            'pnl' => null,
            'pnl_percent' => null,
            'risk_reward' => null,
            'duration_minutes' => null,
            'status' => 'OPEN',
            'exit_type' => null,
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
            'position_type' => 'TRADE',
            'created_at' => '2026-01-15 10:00:00',
            'updated_at' => '2026-01-15 10:00:00',
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
            'opened_at' => '2026-01-15 10:00:00',
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
        $this->tradeRepo->method('create')->willReturn($this->fakeTrade());
        $this->historyRepo->expects($this->once())->method('create');

        $result = $this->service->create(1, $this->validCreateData());

        $this->assertSame('NASDAQ', $result['symbol']);
        $this->assertSame('OPEN', $result['status']);
    }

    public function testCreateCalculatesPricesBuy(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            // BUY → sl_price = entry - sl = 18500 - 50 = 18450
            $this->assertEquals(18450, $data['sl_price']);
            return ['id' => 10, 'user_id' => 1];
        });
        $this->tradeRepo->method('create')->willReturn($this->fakeTrade());

        $this->service->create(1, $this->validCreateData());
    }

    public function testCreateCalculatesPricesSell(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            // SELL → sl_price = entry + sl = 18500 + 50 = 18550
            $this->assertEquals(18550, $data['sl_price']);
            return ['id' => 10, 'user_id' => 1];
        });
        $this->tradeRepo->method('create')->willReturn($this->fakeTrade());

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
        $this->tradeRepo->method('create')->willReturn($this->fakeTrade());

        $this->service->create(1, $this->validCreateData(['be_points' => 30]));
    }

    public function testCreateWithTargets(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            $targets = json_decode($data['targets'], true);
            // BUY → target.price = entry + points = 18500 + 100 = 18600
            $this->assertEquals(18600, $targets[0]['price']);
            return ['id' => 10, 'user_id' => 1];
        });
        $this->tradeRepo->method('create')->willReturn($this->fakeTrade());

        $targets = [['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 0.5]];
        $this->service->create(1, $this->validCreateData(['targets' => $targets]));
    }

    public function testCreateSetsPositionTypeTrade(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());
        $this->positionRepo->method('create')->willReturnCallback(function ($data) {
            $this->assertSame('TRADE', $data['position_type']);
            return ['id' => 10, 'user_id' => 1];
        });
        $this->tradeRepo->method('create')->willReturn($this->fakeTrade());

        $this->service->create(1, $this->validCreateData());
    }

    // ── Create: validation errors ───────────────────────────────

    public function testCreateThrowsWhenAccountIdMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.field_required');

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
        $this->expectExceptionMessage('trades.error.account_forbidden');

        $this->service->create(1, $this->validCreateData());
    }

    public function testCreateThrowsWhenDirectionMissing(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.field_required');

        $data = $this->validCreateData();
        unset($data['direction']);
        $this->service->create(1, $data);
    }

    public function testCreateThrowsWhenDirectionInvalid(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.invalid_direction');

        $this->service->create(1, $this->validCreateData(['direction' => 'INVALID']));
    }

    public function testCreateThrowsWhenSymbolEmpty(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.field_required');

        $this->service->create(1, $this->validCreateData(['symbol' => '']));
    }

    public function testCreateThrowsWhenEntryPriceZero(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.invalid_price');

        $this->service->create(1, $this->validCreateData(['entry_price' => 0]));
    }

    public function testCreateThrowsWhenSizeZero(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.invalid_size');

        $this->service->create(1, $this->validCreateData(['size' => 0]));
    }

    public function testCreateThrowsWhenSetupEmpty(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.field_required');

        $this->service->create(1, $this->validCreateData(['setup' => '']));
    }

    public function testCreateThrowsWhenSlPointsZero(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.invalid_sl_points');

        $this->service->create(1, $this->validCreateData(['sl_points' => 0]));
    }

    public function testCreateThrowsWhenOpenedAtMissing(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.field_required');

        $data = $this->validCreateData();
        unset($data['opened_at']);
        $this->service->create(1, $data);
    }

    public function testCreateThrowsWhenNotesTooLong(): void
    {
        $this->accountRepo->method('findById')->willReturn($this->fakeAccount());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.notes_too_long');

        $this->service->create(1, $this->validCreateData(['notes' => str_repeat('x', 10001)]));
    }

    // ── List ────────────────────────────────────────────────────

    public function testListReturnsTrades(): void
    {
        $trades = [$this->fakeTrade(), $this->fakeTrade(['id' => 2])];
        $this->tradeRepo->method('findAllByUserId')->willReturn(['items' => $trades, 'total' => 2]);

        $result = $this->service->list(1);

        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['page']);
    }

    // ── Get ─────────────────────────────────────────────────────

    public function testGetReturnsTrade(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade());
        $this->partialExitRepo->method('findByTradeId')->willReturn([]);

        $result = $this->service->get(1, 1);

        $this->assertSame('NASDAQ', $result['symbol']);
        $this->assertSame('OPEN', $result['status']);
        $this->assertArrayHasKey('partial_exits', $result);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->tradeRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('trades.error.not_found');

        $this->service->get(1, 999);
    }

    public function testGetThrowsForbiddenWhenNotOwner(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade(['user_id' => 2]));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('trades.error.forbidden');

        $this->service->get(1, 1);
    }

    public function testGetThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->get(1, 0);
    }

    // ── Close: partial exit ─────────────────────────────────────

    public function testClosePartialExitSuccess(): void
    {
        $trade = $this->fakeTrade(['remaining_size' => '2.0000', 'size' => '2.0000']);
        $this->tradeRepo->method('findById')->willReturn($trade);
        $this->partialExitRepo->method('create')->willReturn([
            'id' => 1, 'trade_id' => 1, 'exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00',
        ]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00'],
        ]);
        $this->tradeRepo->method('update')->willReturn($this->fakeTrade([
            'remaining_size' => '1.0000', 'status' => 'SECURED',
        ]));
        $this->historyRepo->expects($this->once())->method('create');

        $result = $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);

        $this->assertSame('SECURED', $result['status']);
    }

    public function testCloseFullExitSuccess(): void
    {
        $trade = $this->fakeTrade(['remaining_size' => '1.0000', 'size' => '1.0000']);
        $this->tradeRepo->method('findById')->willReturn($trade);
        $this->partialExitRepo->method('create')->willReturn([
            'id' => 1, 'trade_id' => 1, 'exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00',
        ]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00'],
        ]);
        $this->tradeRepo->method('update')->willReturn($this->fakeTrade([
            'remaining_size' => '0.0000', 'status' => 'CLOSED',
            'pnl' => '100.00', 'exit_type' => 'TP',
        ]));
        $this->historyRepo->expects($this->once())->method('create');

        $result = $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);

        $this->assertSame('CLOSED', $result['status']);
    }

    public function testClosePnlCalculationBuy(): void
    {
        $trade = $this->fakeTrade([
            'remaining_size' => '1.0000', 'size' => '1.0000',
            'direction' => 'BUY', 'entry_price' => '18500.00000',
        ]);
        $this->tradeRepo->method('findById')->willReturn($trade);
        $this->partialExitRepo->method('create')->willReturnCallback(function ($data) {
            // BUY: pnl = (18600 - 18500) * 1 * 1 = 100
            $this->assertEquals(100.0, $data['pnl']);
            return ['id' => 1, 'trade_id' => 1, 'exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00'];
        });
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00'],
        ]);
        $this->tradeRepo->method('update')->willReturn($this->fakeTrade(['status' => 'CLOSED']));

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    public function testClosePnlCalculationSell(): void
    {
        $trade = $this->fakeTrade([
            'remaining_size' => '1.0000', 'size' => '1.0000',
            'direction' => 'SELL', 'entry_price' => '18500.00000',
        ]);
        $this->tradeRepo->method('findById')->willReturn($trade);
        $this->partialExitRepo->method('create')->willReturnCallback(function ($data) {
            // SELL: pnl = (18400 - 18500) * 1 * -1 = 100
            $this->assertEquals(100.0, $data['pnl']);
            return ['id' => 1, 'trade_id' => 1, 'exit_price' => '18400.00000', 'size' => '1.0000', 'pnl' => '100.00'];
        });
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['exit_price' => '18400.00000', 'size' => '1.0000', 'pnl' => '100.00'],
        ]);
        $this->tradeRepo->method('update')->willReturn($this->fakeTrade(['status' => 'CLOSED']));

        $this->service->close(1, 1, [
            'exit_price' => 18400,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    public function testCloseStatusTransitionOpenToSecured(): void
    {
        $trade = $this->fakeTrade(['remaining_size' => '2.0000', 'size' => '2.0000', 'status' => 'OPEN']);
        $this->tradeRepo->method('findById')->willReturn($trade);
        $this->partialExitRepo->method('create')->willReturn([
            'id' => 1, 'trade_id' => 1, 'exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00',
        ]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00'],
        ]);
        $this->tradeRepo->method('update')->willReturnCallback(function ($id, $data) {
            $this->assertSame('SECURED', $data['status']);
            return $this->fakeTrade(['status' => 'SECURED', 'remaining_size' => '1.0000']);
        });

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'BE',
        ]);
    }

    public function testCloseSecuredStaysSecuredOnPartial(): void
    {
        $trade = $this->fakeTrade(['remaining_size' => '2.0000', 'size' => '3.0000', 'status' => 'SECURED']);
        $this->tradeRepo->method('findById')->willReturn($trade);
        $this->partialExitRepo->method('create')->willReturn([
            'id' => 2, 'trade_id' => 1, 'exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00',
        ]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['exit_price' => '18550.00000', 'size' => '1.0000', 'pnl' => '50.00'],
            ['exit_price' => '18600.00000', 'size' => '1.0000', 'pnl' => '100.00'],
        ]);
        $this->tradeRepo->method('update')->willReturnCallback(function ($id, $data) {
            // Status should not change (stays SECURED)
            $this->assertArrayNotHasKey('status', $data);
            return $this->fakeTrade(['status' => 'SECURED', 'remaining_size' => '1.0000']);
        });
        // No status change → no history log
        $this->historyRepo->expects($this->never())->method('create');

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    public function testCloseRemainingSize(): void
    {
        $trade = $this->fakeTrade(['remaining_size' => '2.0000', 'size' => '2.0000']);
        $this->tradeRepo->method('findById')->willReturn($trade);
        $this->partialExitRepo->method('create')->willReturn([
            'id' => 1, 'trade_id' => 1, 'exit_price' => '18600.00000', 'size' => '0.5000', 'pnl' => '50.00',
        ]);
        $this->partialExitRepo->method('findByTradeId')->willReturn([
            ['exit_price' => '18600.00000', 'size' => '0.5000', 'pnl' => '50.00'],
        ]);
        $this->tradeRepo->method('update')->willReturnCallback(function ($id, $data) {
            $this->assertEquals(1.5, $data['remaining_size']);
            return $this->fakeTrade(['remaining_size' => '1.5000']);
        });

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 0.5,
            'exit_type' => 'TP',
        ]);
    }

    // ── Close: validation errors ────────────────────────────────

    public function testCloseThrowsWhenAlreadyClosed(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade(['status' => 'CLOSED']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.already_closed');

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    public function testCloseThrowsWhenExitSizeExceedsRemaining(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade(['remaining_size' => '0.5000']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.invalid_exit_size');

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    public function testCloseThrowsWhenExitPriceInvalid(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.invalid_exit_price');

        $this->service->close(1, 1, [
            'exit_price' => 0,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    public function testCloseThrowsWhenExitTypeInvalid(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('trades.error.invalid_exit_type');

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'INVALID',
        ]);
    }

    public function testCloseThrowsWhenNotFound(): void
    {
        $this->tradeRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->close(1, 999, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    public function testCloseThrowsForbidden(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade(['user_id' => 2]));

        $this->expectException(ForbiddenException::class);

        $this->service->close(1, 1, [
            'exit_price' => 18600,
            'exit_size' => 1,
            'exit_type' => 'TP',
        ]);
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeleteSuccess(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade());
        $this->positionRepo->expects($this->once())->method('delete')->with(10);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $this->tradeRepo->method('findById')->willReturn($this->fakeTrade(['user_id' => 2]));

        $this->expectException(ForbiddenException::class);

        $this->service->delete(1, 1);
    }
}
