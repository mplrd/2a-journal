<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SetupRepository;
use App\Services\SetupService;
use PHPUnit\Framework\TestCase;

class SetupServiceTest extends TestCase
{
    private SetupService $service;
    private SetupRepository $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(SetupRepository::class);
        $this->service = new SetupService($this->repo);
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListReturnsUserSetups(): void
    {
        $setups = [
            ['id' => 1, 'label' => 'Breakout'],
            ['id' => 2, 'label' => 'FVG'],
        ];
        $this->repo->method('findAllByUserId')->with(1)->willReturn($setups);

        $result = $this->service->list(1);

        $this->assertSame($setups, $result);
    }

    // ── Create validation ────────────────────────────────────────

    public function testCreateThrowsWhenLabelMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.field_required');

        $this->service->create(1, ['label' => '']);
    }

    public function testCreateThrowsWhenLabelIsWhitespaceOnly(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.field_required');

        $this->service->create(1, ['label' => '   ']);
    }

    public function testCreateThrowsWhenLabelTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.label_too_long');

        $this->service->create(1, ['label' => str_repeat('x', 101)]);
    }

    public function testCreateThrowsWhenDuplicateLabel(): void
    {
        $this->repo->method('findByUserAndLabel')
            ->with(1, 'Breakout')
            ->willReturn(['id' => 99, 'label' => 'Breakout']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.duplicate_label');

        $this->service->create(1, ['label' => 'Breakout']);
    }

    public function testCreateTrimsLabel(): void
    {
        $expected = ['id' => 1, 'label' => 'Breakout', 'user_id' => 1];
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('create')
            ->with(['user_id' => 1, 'label' => 'Breakout'])
            ->willReturn($expected);

        $result = $this->service->create(1, ['label' => '  Breakout  ']);

        $this->assertSame($expected, $result);
    }

    public function testCreateSuccess(): void
    {
        $expected = ['id' => 1, 'label' => 'Breakout', 'user_id' => 1];
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->method('create')->willReturn($expected);

        $result = $this->service->create(1, ['label' => 'Breakout']);

        $this->assertSame($expected, $result);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDeleteSuccess(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Breakout'];
        $this->repo->method('findById')->with(1)->willReturn($setup);
        $this->repo->expects($this->once())->method('softDelete')->with(1);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('setups.error.not_found');

        $this->service->delete(1, 999);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $setup = ['id' => 1, 'user_id' => 2, 'label' => 'Breakout'];
        $this->repo->method('findById')->willReturn($setup);

        $this->expectException(ForbiddenException::class);

        $this->service->delete(1, 1);
    }

    public function testDeleteThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->delete(1, 0);
    }

    // ── Update ───────────────────────────────────────────────────

    public function testUpdateAcceptsAllSupportedCategories(): void
    {
        foreach (['timeframe', 'pattern', 'context'] as $category) {
            $repo = $this->createMock(SetupRepository::class);
            $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Breakout', 'category' => 'pattern'];
            $repo->method('findById')->willReturn($setup);
            $repo->expects($this->once())
                ->method('update')
                ->with(1, ['category' => $category])
                ->willReturn(array_merge($setup, ['category' => $category]));

            $service = new SetupService($repo);
            $result = $service->update(1, 1, ['category' => $category]);

            $this->assertSame($category, $result['category']);
        }
    }

    public function testUpdateAcceptsNullCategory(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Breakout', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);
        $this->repo->expects($this->once())
            ->method('update')
            ->with(1, ['category' => null])
            ->willReturn(array_merge($setup, ['category' => null]));

        $result = $this->service->update(1, 1, ['category' => null]);

        $this->assertNull($result['category']);
    }

    public function testUpdateTreatsEmptyStringCategoryAsNull(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Breakout', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);
        $this->repo->expects($this->once())
            ->method('update')
            ->with(1, ['category' => null])
            ->willReturn(array_merge($setup, ['category' => null]));

        $result = $this->service->update(1, 1, ['category' => '']);

        $this->assertNull($result['category']);
    }

    public function testUpdateRejectsInvalidCategory(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Breakout', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.invalid_category');

        $this->service->update(1, 1, ['category' => 'something-else']);
    }

    public function testUpdateThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('setups.error.not_found');

        $this->service->update(1, 999, ['category' => 'pattern']);
    }

    public function testUpdateThrowsForbiddenWhenNotOwner(): void
    {
        $setup = ['id' => 1, 'user_id' => 2, 'label' => 'Breakout', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);

        $this->expectException(ForbiddenException::class);

        $this->service->update(1, 1, ['category' => 'pattern']);
    }

    public function testUpdateThrowsWhenIdIsZero(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->update(1, 0, ['category' => 'pattern']);
    }

    // ── Update label (inline edit) ───────────────────────────────

    public function testUpdateAcceptsLabelChange(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Old', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('update')
            ->with(1, ['label' => 'New'])
            ->willReturn(array_merge($setup, ['label' => 'New']));

        $result = $this->service->update(1, 1, ['label' => 'New']);

        $this->assertSame('New', $result['label']);
    }

    public function testUpdateTrimsLabel(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Old', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('update')
            ->with(1, ['label' => 'New'])
            ->willReturn(array_merge($setup, ['label' => 'New']));

        $this->service->update(1, 1, ['label' => '  New  ']);
    }

    public function testUpdateRejectsEmptyLabel(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Old', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.field_required');

        $this->service->update(1, 1, ['label' => '   ']);
    }

    public function testUpdateRejectsLabelTooLong(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Old', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.label_too_long');

        $this->service->update(1, 1, ['label' => str_repeat('x', 101)]);
    }

    public function testUpdateRejectsDuplicateLabelFromAnotherSetup(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Old', 'category' => 'pattern'];
        $other = ['id' => 2, 'user_id' => 1, 'label' => 'Existing', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);
        $this->repo->method('findByUserAndLabel')->with(1, 'Existing')->willReturn($other);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('setups.error.duplicate_label');

        $this->service->update(1, 1, ['label' => 'Existing']);
    }

    public function testUpdateAllowsRenameToSameLabel(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Same', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);
        // Duplicate check returns the same setup → must be allowed (no-op)
        $this->repo->method('findByUserAndLabel')->with(1, 'Same')->willReturn($setup);
        $this->repo->expects($this->once())
            ->method('update')
            ->with(1, ['label' => 'Same'])
            ->willReturn($setup);

        $result = $this->service->update(1, 1, ['label' => 'Same']);

        $this->assertSame('Same', $result['label']);
    }

    public function testUpdateAcceptsLabelAndCategoryTogether(): void
    {
        $setup = ['id' => 1, 'user_id' => 1, 'label' => 'Old', 'category' => 'pattern'];
        $this->repo->method('findById')->willReturn($setup);
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->expects($this->once())
            ->method('update')
            ->with(1, ['label' => 'New', 'category' => 'context'])
            ->willReturn(['id' => 1, 'user_id' => 1, 'label' => 'New', 'category' => 'context']);

        $result = $this->service->update(1, 1, ['label' => 'New', 'category' => 'context']);

        $this->assertSame('New', $result['label']);
        $this->assertSame('context', $result['category']);
    }
}
