<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\PositionRepository;
use App\Repositories\StatusHistoryRepository;
use App\Services\PositionService;
use PHPUnit\Framework\TestCase;

class PositionServiceTest extends TestCase
{
    private PositionService $service;
    private PositionRepository $positionRepo;
    private AccountRepository $accountRepo;
    private StatusHistoryRepository $historyRepo;

    protected function setUp(): void
    {
        $this->positionRepo = $this->createMock(PositionRepository::class);
        $this->accountRepo = $this->createMock(AccountRepository::class);
        $this->historyRepo = $this->createMock(StatusHistoryRepository::class);
        $this->service = new PositionService($this->positionRepo, $this->accountRepo, $this->historyRepo);
    }

    private function fakePosition(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'user_id' => 1,
            'account_id' => 10,
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
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ── Get ──────────────────────────────────────────────────────

    public function testGetReturnsPositionForOwner(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->with(1)->willReturn($position);

        $result = $this->service->get(1, 1);

        $this->assertSame('NASDAQ', $result['symbol']);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->positionRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('positions.error.not_found');

        $this->service->get(1, 999);
    }

    public function testGetThrowsForbiddenWhenNotOwner(): void
    {
        $position = $this->fakePosition(['user_id' => 2]);
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('positions.error.forbidden');

        $this->service->get(1, 1);
    }

    public function testGetThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->get(1, 0);
    }

    // ── Update: validations ─────────────────────────────────────

    public function testUpdateThrowsWhenEntryPriceZero(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_price');

        $this->service->update(1, 1, ['entry_price' => 0]);
    }

    public function testUpdateThrowsWhenEntryPriceNegative(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_price');

        $this->service->update(1, 1, ['entry_price' => -100]);
    }

    public function testUpdateThrowsWhenSizeZero(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_size');

        $this->service->update(1, 1, ['size' => 0]);
    }

    public function testUpdateThrowsWhenSlPointsZero(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_sl_points');

        $this->service->update(1, 1, ['sl_points' => 0]);
    }

    public function testUpdateThrowsWhenBePointsNegative(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_be_points');

        $this->service->update(1, 1, ['be_points' => -5]);
    }

    public function testUpdateThrowsWhenBeSizeZero(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_be_size');

        $this->service->update(1, 1, ['be_size' => 0]);
    }

    public function testUpdateThrowsWhenDirectionInvalid(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_direction');

        $this->service->update(1, 1, ['direction' => 'INVALID']);
    }

    public function testUpdateThrowsWhenSymbolEmpty(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_symbol');

        $this->service->update(1, 1, ['symbol' => '']);
    }

    public function testUpdateThrowsWhenSymbolTooLong(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_symbol');

        $this->service->update(1, 1, ['symbol' => str_repeat('X', 51)]);
    }

    public function testUpdateThrowsWhenSetupEmpty(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_setup');

        $this->service->update(1, 1, ['setup' => '']);
    }

    public function testUpdateThrowsWhenNotesTooLong(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.notes_too_long');

        $this->service->update(1, 1, ['notes' => str_repeat('x', 10001)]);
    }

    public function testUpdateThrowsWhenTargetsInvalidJson(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_targets');

        $this->service->update(1, 1, ['targets' => 'not-json{']);
    }

    public function testUpdateThrowsWhenTargetPointsZero(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_target_points');

        $this->service->update(1, 1, ['targets' => [['points' => 0, 'size' => 0.5]]]);
    }

    public function testUpdateThrowsWhenTargetSizeZero(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.invalid_target_size');

        $this->service->update(1, 1, ['targets' => [['points' => 100, 'size' => 0]]]);
    }

    // ── Update: price calculations BUY ──────────────────────────

    public function testUpdateCalculatesSlPriceBuy(): void
    {
        $position = $this->fakePosition(['direction' => 'BUY', 'entry_price' => '18500.00000', 'sl_points' => '50.00']);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->method('update')->willReturnCallback(function ($id, $data) {
            return array_merge($this->fakePosition(), $data);
        });

        $result = $this->service->update(1, 1, ['entry_price' => 19000]);

        // BUY: sl_price = entry_price - sl_points = 19000 - 50 = 18950
        $this->assertEquals(18950, $result['sl_price']);
    }

    public function testUpdateCalculatesBePriceBuy(): void
    {
        $position = $this->fakePosition([
            'direction' => 'BUY',
            'entry_price' => '18500.00000',
            'be_points' => '30.00',
            'be_price' => '18530.00000',
        ]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->method('update')->willReturnCallback(function ($id, $data) use ($position) {
            return array_merge($position, $data);
        });

        $result = $this->service->update(1, 1, ['entry_price' => 19000]);

        // BUY: be_price = entry_price + be_points = 19000 + 30 = 19030
        $this->assertEquals(19030, $result['be_price']);
    }

    public function testUpdateCalculatesTargetPricesBuy(): void
    {
        $position = $this->fakePosition(['direction' => 'BUY', 'entry_price' => '18500.00000']);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->method('update')->willReturnCallback(function ($id, $data) use ($position) {
            return array_merge($position, $data);
        });

        $targets = [
            ['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 0.5],
            ['id' => 'tp2', 'label' => 'TP2', 'points' => 200, 'size' => 0.5],
        ];

        $result = $this->service->update(1, 1, ['targets' => $targets]);

        $resultTargets = json_decode($result['targets'], true);
        // BUY: target.price = entry_price + points
        $this->assertEquals(18600, $resultTargets[0]['price']); // 18500 + 100
        $this->assertEquals(18700, $resultTargets[1]['price']); // 18500 + 200
    }

    // ── Update: price calculations SELL ─────────────────────────

    public function testUpdateCalculatesSlPriceSell(): void
    {
        $position = $this->fakePosition([
            'direction' => 'SELL',
            'entry_price' => '18500.00000',
            'sl_points' => '50.00',
        ]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->method('update')->willReturnCallback(function ($id, $data) use ($position) {
            return array_merge($position, $data);
        });

        $result = $this->service->update(1, 1, ['entry_price' => 18000]);

        // SELL: sl_price = entry_price + sl_points = 18000 + 50 = 18050
        $this->assertEquals(18050, $result['sl_price']);
    }

    public function testUpdateCalculatesBePriceSell(): void
    {
        $position = $this->fakePosition([
            'direction' => 'SELL',
            'entry_price' => '18500.00000',
            'be_points' => '30.00',
            'be_price' => '18470.00000',
        ]);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->method('update')->willReturnCallback(function ($id, $data) use ($position) {
            return array_merge($position, $data);
        });

        $result = $this->service->update(1, 1, ['entry_price' => 18000]);

        // SELL: be_price = entry_price - be_points = 18000 - 30 = 17970
        $this->assertEquals(17970, $result['be_price']);
    }

    public function testUpdateCalculatesTargetPricesSell(): void
    {
        $position = $this->fakePosition(['direction' => 'SELL', 'entry_price' => '18500.00000']);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->method('update')->willReturnCallback(function ($id, $data) use ($position) {
            return array_merge($position, $data);
        });

        $targets = [
            ['id' => 'tp1', 'label' => 'TP1', 'points' => 100, 'size' => 0.5],
        ];

        $result = $this->service->update(1, 1, ['targets' => $targets]);

        $resultTargets = json_decode($result['targets'], true);
        // SELL: target.price = entry_price - points
        $this->assertEquals(18400, $resultTargets[0]['price']); // 18500 - 100
    }

    // ── Update success ──────────────────────────────────────────

    public function testUpdateSuccess(): void
    {
        $position = $this->fakePosition();
        $updated = $this->fakePosition(['notes' => 'Updated']);
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->method('update')->willReturn($updated);

        $result = $this->service->update(1, 1, ['notes' => 'Updated']);

        $this->assertSame('Updated', $result['notes']);
    }

    public function testUpdateThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->update(1, 0, ['notes' => 'test']);
    }

    // ── Delete ──────────────────────────────────────────────────

    public function testDeleteSuccess(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);
        $this->positionRepo->expects($this->once())->method('delete')->with(1);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $position = $this->fakePosition(['user_id' => 2]);
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ForbiddenException::class);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->delete(1, 0);
    }

    // ── Transfer ────────────────────────────────────────────────

    public function testTransferSuccess(): void
    {
        $position = $this->fakePosition(['account_id' => 10]);
        $this->positionRepo->method('findById')->willReturn($position);

        $targetAccount = ['id' => 20, 'user_id' => 1, 'name' => 'Other Account'];
        $this->accountRepo->method('findById')->with(20)->willReturn($targetAccount);

        $transferred = $this->fakePosition(['account_id' => 20]);
        $this->positionRepo->method('transfer')->willReturn($transferred);

        $this->historyRepo->expects($this->once())->method('create');

        $result = $this->service->transfer(1, 1, ['account_id' => 20]);

        $this->assertEquals(20, $result['account_id']);
    }

    public function testTransferThrowsWhenAccountIdMissing(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('positions.error.field_required');

        $this->service->transfer(1, 1, []);
    }

    public function testTransferThrowsWhenTargetAccountNotOwned(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $otherUserAccount = ['id' => 20, 'user_id' => 999, 'name' => 'Not mine'];
        $this->accountRepo->method('findById')->willReturn($otherUserAccount);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('positions.error.transfer_forbidden');

        $this->service->transfer(1, 1, ['account_id' => 20]);
    }

    public function testTransferThrowsWhenTargetAccountNotFound(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);
        $this->accountRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('accounts.error.not_found');

        $this->service->transfer(1, 1, ['account_id' => 99999]);
    }

    // ── History ─────────────────────────────────────────────────

    public function testGetHistoryReturnsEntries(): void
    {
        $position = $this->fakePosition();
        $this->positionRepo->method('findById')->willReturn($position);

        $entries = [['id' => 1, 'new_status' => 'transferred']];
        $this->historyRepo->method('findByEntity')->with('POSITION', 1)->willReturn($entries);

        $result = $this->service->getHistory(1, 1);

        $this->assertCount(1, $result);
    }

    public function testGetHistoryThrowsForbiddenWhenNotOwner(): void
    {
        $position = $this->fakePosition(['user_id' => 2]);
        $this->positionRepo->method('findById')->willReturn($position);

        $this->expectException(ForbiddenException::class);

        $this->service->getHistory(1, 1);
    }

    // ── List ────────────────────────────────────────────────────

    public function testListReturnsPositions(): void
    {
        $positions = [$this->fakePosition(), $this->fakePosition(['id' => 2])];
        $this->positionRepo->method('findAllByUserId')->willReturn($positions);

        $result = $this->service->list(1);

        $this->assertCount(2, $result);
    }
}
