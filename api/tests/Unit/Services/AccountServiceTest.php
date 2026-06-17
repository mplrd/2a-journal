<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountAdjustmentRepository;
use App\Repositories\AccountRepository;
use App\Services\AccountService;
use PHPUnit\Framework\TestCase;

class AccountServiceTest extends TestCase
{
    private AccountService $service;
    private AccountRepository $repo;
    private AccountAdjustmentRepository $adjustmentRepo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(AccountRepository::class);
        $this->adjustmentRepo = $this->createMock(AccountAdjustmentRepository::class);
        $this->service = new AccountService($this->repo, $this->adjustmentRepo);
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My Account',
            'account_type' => 'BROKER_DEMO',
        ], $overrides);
    }

    // ── Validation: name ─────────────────────────────────────────

    public function testCreateThrowsWhenNameMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.field_required');

        $this->service->create(1, $this->validData(['name' => '']));
    }

    public function testCreateThrowsWhenNameTooLong(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, $this->validData(['name' => str_repeat('x', 101)]));
    }

    // ── Validation: account_type ─────────────────────────────────

    public function testCreateThrowsWhenAccountTypeMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.field_required');

        $this->service->create(1, $this->validData(['account_type' => '']));
    }

    public function testCreateThrowsWhenAccountTypeInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.invalid_type');

        $this->service->create(1, $this->validData(['account_type' => 'INVALID']));
    }

    // ── Validation: stage ──────────────────────────────────────────

    public function testCreateThrowsWhenStageRequiredForPropFirm(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.stage_required');

        $this->service->create(1, $this->validData(['account_type' => 'PROP_FIRM']));
    }

    public function testCreateThrowsWhenStageNotAllowedForBroker(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.stage_not_allowed');

        $this->service->create(1, $this->validData(['account_type' => 'BROKER_DEMO', 'stage' => 'CHALLENGE']));
    }

    public function testCreateThrowsWhenStageInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.invalid_stage');

        $this->service->create(1, $this->validData(['account_type' => 'PROP_FIRM', 'stage' => 'INVALID']));
    }

    public function testCreateSuccessPropFirmWithStage(): void
    {
        $expected = ['id' => 1, 'name' => 'FTMO', 'user_id' => 1];
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, $this->validData([
            'account_type' => 'PROP_FIRM',
            'stage' => 'CHALLENGE',
        ]));

        $this->assertSame($expected, $result);
    }

    public function testCreateSuccessBrokerDemoWithoutStage(): void
    {
        $expected = ['id' => 1, 'name' => 'My Account', 'user_id' => 1];
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, $this->validData());

        $this->assertSame($expected, $result);
    }

    // ── Validation: currency ─────────────────────────────────────

    public function testCreateThrowsWhenCurrencyTooShort(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, $this->validData(['currency' => 'US']));
    }

    public function testCreateThrowsWhenCurrencyTooLong(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, $this->validData(['currency' => 'BITCOIN'])); // 7 chars
    }

    public function testCreateAcceptsStablecoinCurrency(): void
    {
        // USDT/USDC (4-char crypto settlement currencies) must be accepted so a
        // broker account can match its broker balance currency (no alert).
        $this->repo->expects($this->once())->method('create')
            ->willReturn(['id' => 1, 'currency' => 'USDT']);

        $this->service->create(1, $this->validData(['currency' => 'USDT']));
    }

    // ── Validation: initial_capital ──────────────────────────────

    public function testCreateThrowsWhenCapitalNegative(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.invalid_capital');

        $this->service->create(1, $this->validData(['initial_capital' => -100]));
    }

    // ── Validation: profit_split ─────────────────────────────────

    public function testCreateThrowsWhenProfitSplitOver100(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, $this->validData(['profit_split' => 101]));
    }

    public function testCreateThrowsWhenProfitSplitNegative(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, $this->validData(['profit_split' => -1]));
    }

    // ── CRUD success ─────────────────────────────────────────────

    public function testCreateSuccess(): void
    {
        $expected = ['id' => 1, 'name' => 'My Account', 'user_id' => 1];
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, $this->validData());

        $this->assertSame($expected, $result);
    }

    public function testListReturnsUserAccounts(): void
    {
        $accounts = [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']];
        $this->repo->method('findAllByUserId')->willReturn(['items' => $accounts, 'total' => 2]);

        $result = $this->service->list(1);

        $this->assertCount(2, $result['data']);
        $this->assertSame(1, $result['meta']['page']);
        $this->assertSame(50, $result['meta']['per_page']);
        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['total_pages']);
    }

    public function testGetReturnsAccountForOwner(): void
    {
        $account = ['id' => 1, 'user_id' => 1, 'name' => 'My Account'];
        $this->repo->method('findById')->with(1)->willReturn($account);

        $result = $this->service->get(1, 1);

        $this->assertSame('My Account', $result['name']);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('accounts.error.not_found');

        $this->service->get(1, 999);
    }

    public function testGetThrowsForbiddenWhenNotOwner(): void
    {
        $account = ['id' => 1, 'user_id' => 2, 'name' => 'Not mine'];
        $this->repo->method('findById')->willReturn($account);

        try {
            $this->service->get(1, 1);
            $this->fail('Expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('FORBIDDEN', $e->getErrorCode());
            $this->assertSame('accounts.error.forbidden', $e->getMessageKey());
        }
    }

    public function testUpdateSuccess(): void
    {
        $account = ['id' => 1, 'user_id' => 1, 'name' => 'Old Name'];
        $updated = ['id' => 1, 'user_id' => 1, 'name' => 'New Name'];
        $this->repo->method('findById')->willReturn($account);
        $this->repo->method('update')->willReturn($updated);

        $result = $this->service->update(1, 1, $this->validData(['name' => 'New Name']));

        $this->assertSame('New Name', $result['name']);
    }

    public function testDeleteSuccess(): void
    {
        $account = ['id' => 1, 'user_id' => 1, 'name' => 'To Delete'];
        $this->repo->method('findById')->willReturn($account);
        $this->repo->expects($this->once())->method('softDelete')->with(1);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $account = ['id' => 1, 'user_id' => 2, 'name' => 'Not mine'];
        $this->repo->method('findById')->willReturn($account);

        $this->expectException(ForbiddenException::class);

        $this->service->delete(1, 1);
    }

    // ── Validate ID > 0 ─────────────────────────────────────────

    public function testGetThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->get(1, 0);
    }

    public function testUpdateThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->update(1, 0, $this->validData());
    }

    public function testDeleteThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->delete(1, 0);
    }

    // ── Balance adjustments ──────────────────────────────────────

    public function testAddAdjustmentSuccess(): void
    {
        $account = ['id' => 1, 'user_id' => 1, 'name' => 'FTMO'];
        $created = ['id' => 7, 'account_id' => 1, 'amount' => 18.0, 'reason' => 'Frais'];
        $this->repo->method('findById')->willReturn($account);
        $this->adjustmentRepo->expects($this->once())
            ->method('create')
            ->with($this->callback(function ($data) {
                return (int) $data['account_id'] === 1
                    && (float) $data['amount'] === 18.0
                    && $data['reason'] === 'Frais';
            }))
            ->willReturn($created);

        $result = $this->service->addAdjustment(1, 1, ['amount' => 18, 'reason' => 'Frais']);

        $this->assertSame($created, $result);
    }

    public function testAddAdjustmentAcceptsNegativeAmount(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);
        $this->adjustmentRepo->method('create')->willReturn(['id' => 1, 'amount' => -50.0]);

        $result = $this->service->addAdjustment(1, 1, ['amount' => -50]);

        $this->assertEquals(-50.0, (float) $result['amount']);
    }

    public function testAddAdjustmentThrowsWhenAmountMissing(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.invalid_adjustment');

        $this->service->addAdjustment(1, 1, ['reason' => 'x']);
    }

    public function testAddAdjustmentThrowsWhenAmountNotNumeric(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.invalid_adjustment');

        $this->service->addAdjustment(1, 1, ['amount' => 'abc']);
    }

    public function testAddAdjustmentThrowsWhenAmountZero(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.invalid_adjustment');

        $this->service->addAdjustment(1, 1, ['amount' => 0]);
    }

    public function testAddAdjustmentThrowsWhenReasonTooLong(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.reason_too_long');

        $this->service->addAdjustment(1, 1, ['amount' => 10, 'reason' => str_repeat('x', 256)]);
    }

    public function testAddAdjustmentThrowsForbiddenWhenNotOwner(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 2]);

        $this->expectException(ForbiddenException::class);

        $this->service->addAdjustment(1, 1, ['amount' => 10]);
    }

    public function testAddAdjustmentThrowsNotFoundWhenAccountMissing(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->addAdjustment(1, 999, ['amount' => 10]);
    }

    public function testListAdjustmentsReturnsRepoResultForOwner(): void
    {
        $adjustments = [['id' => 1, 'amount' => 10], ['id' => 2, 'amount' => -5]];
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);
        $this->adjustmentRepo->method('findByAccountId')->with(1)->willReturn($adjustments);

        $result = $this->service->listAdjustments(1, 1);

        $this->assertSame($adjustments, $result);
    }

    public function testListAdjustmentsThrowsForbiddenWhenNotOwner(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 2]);

        $this->expectException(ForbiddenException::class);

        $this->service->listAdjustments(1, 1);
    }

    public function testDeleteAdjustmentSuccess(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);
        $this->adjustmentRepo->method('findById')->willReturn(['id' => 7, 'account_id' => 1]);
        $this->adjustmentRepo->expects($this->once())->method('delete')->with(7);

        $this->service->deleteAdjustment(1, 1, 7);
    }

    public function testDeleteAdjustmentThrowsNotFoundWhenAdjustmentMissing(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);
        $this->adjustmentRepo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->deleteAdjustment(1, 1, 999);
    }

    public function testDeleteAdjustmentThrowsNotFoundWhenAdjustmentBelongsToAnotherAccount(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1]);
        $this->adjustmentRepo->method('findById')->willReturn(['id' => 7, 'account_id' => 2]);

        $this->expectException(NotFoundException::class);

        $this->service->deleteAdjustment(1, 1, 7);
    }

    public function testDeleteAdjustmentThrowsForbiddenWhenNotOwner(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 2]);

        $this->expectException(ForbiddenException::class);

        $this->service->deleteAdjustment(1, 1, 7);
    }
}
