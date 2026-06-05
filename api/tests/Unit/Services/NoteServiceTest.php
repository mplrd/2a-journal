<?php

namespace Tests\Unit\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteCategoryRepository;
use App\Repositories\NoteRepository;
use App\Services\FileUploadService;
use App\Services\NoteService;
use PHPUnit\Framework\TestCase;

class NoteServiceTest extends TestCase
{
    private NoteService $service;
    private NoteRepository $repo;
    private NoteAttachmentRepository $attachmentRepo;
    private FileUploadService $fileUpload;
    private NoteCategoryRepository $categoryRepo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(NoteRepository::class);
        $this->attachmentRepo = $this->createMock(NoteAttachmentRepository::class);
        $this->fileUpload = $this->createMock(FileUploadService::class);
        $this->categoryRepo = $this->createMock(NoteCategoryRepository::class);
        $this->service = new NoteService($this->repo, $this->attachmentRepo, $this->fileUpload, $this->categoryRepo);
    }

    private function note(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'user_id' => 42,
            'category_id' => null,
            'title' => null,
            'content' => 'note body',
            'note_date' => '2026-06-01',
            'is_pinned' => 0,
        ], $overrides);
    }

    // ── List ─────────────────────────────────────────────────────

    public function testListAttachesImagesToEachNote(): void
    {
        $this->repo->method('findAllByUserId')->willReturn([
            $this->note(['id' => 1]),
            $this->note(['id' => 2]),
        ]);
        $this->attachmentRepo->method('findByNoteIds')->with([1, 2])->willReturn([
            ['id' => 10, 'note_id' => 1, 'original_name' => 'a.png'],
            ['id' => 11, 'note_id' => 2, 'original_name' => 'b.png'],
        ]);

        $result = $this->service->list(42, []);

        $this->assertCount(1, $result[0]['attachments']);
        $this->assertSame(10, $result[0]['attachments'][0]['id']);
        $this->assertCount(1, $result[1]['attachments']);
    }

    public function testListPassesPinnedAndCategoryFilters(): void
    {
        $this->repo->expects($this->once())->method('findAllByUserId')
            ->with(42, ['category_id' => 3, 'pinned' => true])
            ->willReturn([]);
        $this->attachmentRepo->method('findByNoteIds')->willReturn([]);

        $this->service->list(42, ['category_id' => '3', 'pinned' => '1']);
    }

    // ── Create validation ────────────────────────────────────────

    public function testCreateRejectsEmptyContent(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('notes.error.content_required');

        $this->service->create(42, ['content' => '   ', 'note_date' => '2026-06-01']);
    }

    public function testCreateRejectsMissingDate(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('notes.error.date_required');

        $this->service->create(42, ['content' => 'x', 'note_date' => '']);
    }

    public function testCreateRejectsInvalidDate(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('notes.error.invalid_date');

        $this->service->create(42, ['content' => 'x', 'note_date' => '01/06/2026']);
    }

    public function testCreateRejectsForeignCategory(): void
    {
        $this->categoryRepo->method('findById')->with(7)
            ->willReturn(['id' => 7, 'user_id' => 999]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('notes.error.invalid_category');

        $this->service->create(42, ['content' => 'x', 'note_date' => '2026-06-01', 'category_id' => 7]);
    }

    public function testCreateRejectsTooManyAttachments(): void
    {
        $files = array_fill(0, NoteService::MAX_ATTACHMENTS + 1, ['error' => UPLOAD_ERR_OK]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('notes.error.too_many_attachments');

        $this->service->create(42, ['content' => 'x', 'note_date' => '2026-06-01'], $files);
    }

    public function testCreateStoresNoteAndAttachments(): void
    {
        $captured = null;
        $this->repo->method('create')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $this->note(['id' => 5] + $d);
        });
        $this->repo->method('findById')->willReturn($this->note(['id' => 5]));
        $this->attachmentRepo->method('findByNoteId')->willReturn([]);
        $this->fileUpload->method('store')->willReturn([
            'stored_path' => 'notes/x.png', 'original_name' => 'x.png',
            'mime_type' => 'image/png', 'size_bytes' => 10,
        ]);
        $this->attachmentRepo->expects($this->once())->method('create')
            ->with($this->callback(fn ($a) => (int) $a['note_id'] === 5 && $a['stored_path'] === 'notes/x.png'));

        $this->service->create(42, [
            'content' => '  body  ', 'note_date' => '2026-06-01', 'is_pinned' => '1',
        ], [['error' => UPLOAD_ERR_OK]]);

        $this->assertSame('body', $captured['content']);
        $this->assertSame(1, $captured['is_pinned']);
        $this->assertSame(42, $captured['user_id']);
    }

    public function testCreateCoercesPinnedFalse(): void
    {
        $captured = null;
        $this->repo->method('create')->willReturnCallback(function (array $d) use (&$captured) {
            $captured = $d;
            return $this->note(['id' => 5]);
        });
        $this->repo->method('findById')->willReturn($this->note(['id' => 5]));
        $this->attachmentRepo->method('findByNoteId')->willReturn([]);

        $this->service->create(42, ['content' => 'x', 'note_date' => '2026-06-01']);

        $this->assertSame(0, $captured['is_pinned']);
    }

    // ── Update / delete ownership ────────────────────────────────

    public function testUpdateThrowsForbiddenWhenNotOwner(): void
    {
        $this->repo->method('findById')->willReturn($this->note(['user_id' => 999]));

        $this->expectException(ForbiddenException::class);
        $this->service->update(42, 1, ['content' => 'edited']);
    }

    public function testUpdateThrowsWhenNotFound(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->update(42, 999, ['content' => 'edited']);
    }

    public function testUpdateTogglesPin(): void
    {
        $this->repo->method('findById')->willReturn($this->note());
        $this->attachmentRepo->method('findByNoteId')->willReturn([]);
        $this->repo->expects($this->once())->method('update')
            ->with(1, $this->callback(fn ($p) => $p['is_pinned'] === 1));

        $this->service->update(42, 1, ['is_pinned' => true]);
    }

    public function testDeleteSoftDeletesOwnedNote(): void
    {
        $this->repo->method('findById')->willReturn($this->note());
        $this->repo->expects($this->once())->method('softDelete')->with(1);

        $this->service->delete(42, 1);
    }

    public function testDeleteThrowsForbiddenWhenNotOwner(): void
    {
        $this->repo->method('findById')->willReturn($this->note(['user_id' => 7]));

        $this->expectException(ForbiddenException::class);
        $this->service->delete(42, 1);
    }

    // ── Attachments ──────────────────────────────────────────────

    public function testGetAttachmentEnforcesOwnership(): void
    {
        $this->attachmentRepo->method('findByIdForUser')->with(5, 42)->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->getAttachmentForUser(42, 5);
    }

    public function testDeleteAttachmentRemovesFileAndRow(): void
    {
        $this->attachmentRepo->method('findByIdForUser')->with(5, 42)
            ->willReturn(['id' => 5, 'note_id' => 1, 'stored_path' => 'notes/x.png']);
        $this->fileUpload->expects($this->once())->method('delete')->with('notes/x.png');
        $this->attachmentRepo->expects($this->once())->method('delete')->with(5);

        $this->service->deleteAttachment(42, 1, 5);
    }
}
