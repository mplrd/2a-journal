<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\NoteCategoryRepository;
use App\Repositories\NoteRepository;
use App\Services\NoteCategoryService;
use PHPUnit\Framework\TestCase;

class NoteCategoryServiceTest extends TestCase
{
    private NoteCategoryService $service;
    private NoteCategoryRepository $repo;
    private NoteRepository $noteRepo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(NoteCategoryRepository::class);
        $this->noteRepo = $this->createMock(NoteRepository::class);
        $this->service = new NoteCategoryService($this->repo, $this->noteRepo);
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListReturnsUserCategories(): void
    {
        $categories = [
            ['id' => 1, 'label' => 'Money Management'],
            ['id' => 2, 'label' => 'Trading setup'],
        ];
        $this->repo->method('findAllByUserId')->with(1)->willReturn($categories);

        $this->assertSame($categories, $this->service->list(1));
    }

    // ── Create ───────────────────────────────────────────────────

    public function testCreateThrowsWhenLabelMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('note_categories.error.field_required');

        $this->service->create(1, ['label' => '  ']);
    }

    public function testCreateThrowsWhenLabelTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('note_categories.error.label_too_long');

        $this->service->create(1, ['label' => str_repeat('x', 101)]);
    }

    public function testCreateThrowsWhenDuplicateLabel(): void
    {
        $this->repo->method('findByUserAndLabel')->with(1, 'Money Management')
            ->willReturn(['id' => 9, 'label' => 'Money Management']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('note_categories.error.duplicate_label');

        $this->service->create(1, ['label' => 'Money Management']);
    }

    public function testCreateRevivesSoftDeletedGhost(): void
    {
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->method('findAnyByUserAndLabel')->with(1, 'Psychologie')
            ->willReturn(['id' => 5, 'label' => 'Psychologie', 'deleted_at' => '2026-01-01 00:00:00']);
        $this->repo->expects($this->once())->method('hardDelete')->with(5);
        $this->repo->method('create')->willReturn(['id' => 7, 'label' => 'Psychologie']);

        $result = $this->service->create(1, ['label' => '  Psychologie  ']);
        $this->assertSame(7, $result['id']);
    }

    public function testCreateTrimsAndSucceeds(): void
    {
        $expected = ['id' => 1, 'user_id' => 1, 'label' => 'Trading setup'];
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->method('findAnyByUserAndLabel')->willReturn(null);
        $this->repo->expects($this->once())->method('create')
            ->with(['user_id' => 1, 'label' => 'Trading setup'])
            ->willReturn($expected);

        $this->assertSame($expected, $this->service->create(1, ['label' => '  Trading setup  ']));
    }

    // ── Update (rename) ──────────────────────────────────────────

    public function testUpdateRenamesCategory(): void
    {
        $cat = ['id' => 1, 'user_id' => 1, 'label' => 'Old'];
        $this->repo->method('findById')->willReturn($cat);
        $this->repo->method('findByUserAndLabel')->willReturn(null);
        $this->repo->method('findAnyByUserAndLabel')->willReturn(null);
        $this->repo->expects($this->once())->method('update')
            ->with(1, ['label' => 'New'])->willReturn(array_merge($cat, ['label' => 'New']));

        $this->assertSame('New', $this->service->update(1, 1, ['label' => 'New'])['label']);
    }

    public function testUpdateThrowsForbiddenWhenNotOwner(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 2, 'label' => 'Old']);

        $this->expectException(ForbiddenException::class);
        $this->service->update(1, 1, ['label' => 'New']);
    }

    public function testUpdateThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->update(1, 999, ['label' => 'New']);
    }

    public function testUpdateRejectsDuplicateFromAnother(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 1, 'user_id' => 1, 'label' => 'Old']);
        $this->repo->method('findByUserAndLabel')->with(1, 'Existing')
            ->willReturn(['id' => 2, 'user_id' => 1, 'label' => 'Existing']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('note_categories.error.duplicate_label');

        $this->service->update(1, 1, ['label' => 'Existing']);
    }

    // ── Delete ───────────────────────────────────────────────────

    public function testDeleteSoftDeletesAndDetachesNotes(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 3, 'user_id' => 1, 'label' => 'X']);
        $this->noteRepo->expects($this->once())->method('clearCategory')->with(1, 3);
        $this->repo->expects($this->once())->method('softDelete')->with(3);

        $this->service->delete(1, 3);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $this->repo->method('findById')->willReturn(['id' => 3, 'user_id' => 2, 'label' => 'X']);

        $this->expectException(ForbiddenException::class);
        $this->service->delete(1, 3);
    }

    public function testDeleteThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->delete(1, 999);
    }

    public function testDeleteThrowsWhenIdInvalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('error.invalid_id');

        $this->service->delete(1, 0);
    }
}
