<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SymbolRepository;
use App\Services\SymbolService;
use PHPUnit\Framework\TestCase;

class SymbolServiceTest extends TestCase
{
    private SymbolService $service;
    private SymbolRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(SymbolRepository::class);
        $this->service = new SymbolService($this->repo);
    }

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'code' => 'US100.CASH',
            'name' => 'NASDAQ 100',
            'type' => 'INDEX',
            'point_value' => 20.0,
            'currency' => 'USD',
        ], $overrides);
    }

    // ── Validation: code ─────────────────────────────────────────

    public function testCreateThrowsWhenCodeMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.field_required');

        $this->service->create(1, $this->validData(['code' => '']));
    }

    public function testCreateThrowsWhenCodeTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.field_too_long');

        $this->service->create(1, $this->validData(['code' => str_repeat('X', 21)]));
    }

    // ── Validation: name ─────────────────────────────────────────

    public function testCreateThrowsWhenNameMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.field_required');

        $this->service->create(1, $this->validData(['name' => '']));
    }

    public function testCreateThrowsWhenNameTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.field_too_long');

        $this->service->create(1, $this->validData(['name' => str_repeat('x', 101)]));
    }

    // ── Validation: type ─────────────────────────────────────────

    public function testCreateThrowsWhenTypeMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.field_required');

        $this->service->create(1, $this->validData(['type' => '']));
    }

    public function testCreateThrowsWhenTypeInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.invalid_type');

        $this->service->create(1, $this->validData(['type' => 'INVALID']));
    }

    // ── Validation: point_value ──────────────────────────────────

    public function testCreateThrowsWhenPointValueZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.invalid_point_value');

        $this->service->create(1, $this->validData(['point_value' => 0]));
    }

    public function testCreateThrowsWhenPointValueNegative(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.invalid_point_value');

        $this->service->create(1, $this->validData(['point_value' => -1]));
    }

    // ── Validation: currency ─────────────────────────────────────

    public function testCreateThrowsWhenCurrencyNotThreeChars(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.invalid_currency');

        $this->service->create(1, $this->validData(['currency' => 'US']));
    }

    // ── Duplicate check ──────────────────────────────────────────

    public function testCreateThrowsWhenDuplicateCode(): void
    {
        $this->repo->method('findByUserAndCode')
            ->with(1, 'US100.CASH')
            ->willReturn(['id' => 99, 'code' => 'US100.CASH']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.duplicate_code');

        $this->service->create(1, $this->validData());
    }

    // ── CRUD success ─────────────────────────────────────────────

    public function testCreateSuccess(): void
    {
        $expected = ['id' => 1, 'code' => 'US100.CASH', 'user_id' => 1];
        $this->repo->method('findByUserAndCode')->willReturn(null);
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, $this->validData());

        $this->assertSame($expected, $result);
    }

    public function testListReturnsUserSymbols(): void
    {
        $symbols = [['id' => 1, 'code' => 'US100.CASH'], ['id' => 2, 'code' => 'DE40.CASH']];
        $this->repo->method('findAllByUserId')->willReturn(['items' => $symbols, 'total' => 2]);

        $result = $this->service->list(1);

        $this->assertCount(2, $result['data']);
        $this->assertSame(1, $result['meta']['page']);
        $this->assertSame(50, $result['meta']['per_page']);
        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame(1, $result['meta']['total_pages']);
    }

    public function testGetReturnsSymbolForOwner(): void
    {
        $symbol = ['id' => 1, 'user_id' => 1, 'code' => 'US100.CASH'];
        $this->repo->method('findById')->with(1)->willReturn($symbol);

        $result = $this->service->get(1, 1);

        $this->assertSame('US100.CASH', $result['code']);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('symbols.error.not_found');

        $this->service->get(1, 999);
    }

    public function testGetThrowsForbiddenWhenNotOwner(): void
    {
        $symbol = ['id' => 1, 'user_id' => 2, 'code' => 'US100.CASH'];
        $this->repo->method('findById')->willReturn($symbol);

        try {
            $this->service->get(1, 1);
            $this->fail('Expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('FORBIDDEN', $e->getErrorCode());
            $this->assertSame('symbols.error.forbidden', $e->getMessageKey());
        }
    }

    public function testUpdateSuccess(): void
    {
        $symbol = ['id' => 1, 'user_id' => 1, 'code' => 'US100.CASH'];
        $updated = ['id' => 1, 'user_id' => 1, 'code' => 'US100.CASH', 'name' => 'New Name'];
        $this->repo->method('findById')->willReturn($symbol);
        $this->repo->method('findByUserAndCode')->willReturn(null);
        $this->repo->method('update')->willReturn($updated);

        $result = $this->service->update(1, 1, $this->validData(['name' => 'New Name']));

        $this->assertSame('New Name', $result['name']);
    }

    public function testUpdateThrowsDuplicateWhenCodeChangesToExisting(): void
    {
        $symbol = ['id' => 1, 'user_id' => 1, 'code' => 'US100.CASH'];
        $existing = ['id' => 2, 'user_id' => 1, 'code' => 'DE40.CASH'];
        $this->repo->method('findById')->willReturn($symbol);
        $this->repo->method('findByUserAndCode')
            ->with(1, 'DE40.CASH')
            ->willReturn($existing);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('symbols.error.duplicate_code');

        $this->service->update(1, 1, $this->validData(['code' => 'DE40.CASH']));
    }

    public function testDeleteSuccess(): void
    {
        $symbol = ['id' => 1, 'user_id' => 1, 'code' => 'US100.CASH'];
        $this->repo->method('findById')->willReturn($symbol);
        $this->repo->expects($this->once())->method('softDelete')->with(1);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $symbol = ['id' => 1, 'user_id' => 2, 'code' => 'US100.CASH'];
        $this->repo->method('findById')->willReturn($symbol);

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
