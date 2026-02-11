<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Services\AccountService;
use PHPUnit\Framework\TestCase;

class AccountServiceTest extends TestCase
{
    private AccountService $service;
    private AccountRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(AccountRepository::class);
        $this->service = new AccountService($this->repo);
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'My Account',
            'account_type' => 'BROKER',
            'mode' => 'DEMO',
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

    // ── Validation: mode ─────────────────────────────────────────

    public function testCreateThrowsWhenModeMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.field_required');

        $this->service->create(1, $this->validData(['mode' => '']));
    }

    public function testCreateThrowsWhenModeInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('accounts.error.invalid_mode');

        $this->service->create(1, $this->validData(['mode' => 'INVALID']));
    }

    // ── Validation: currency ─────────────────────────────────────

    public function testCreateThrowsWhenCurrencyNotThreeChars(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->create(1, $this->validData(['currency' => 'US']));
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
}
