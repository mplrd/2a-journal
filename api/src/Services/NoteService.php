<?php

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteCategoryRepository;
use App\Repositories\NoteRepository;

/**
 * Notebook business logic: CRUD over personal notes, plus their image
 * attachments. Mandatory `note_date`, optional category (validated to belong to
 * the user), `is_pinned` to surface a note on the dashboard. Ownership is always
 * enforced here; controllers stay thin. Attachment handling reuses the generic
 * FileUploadService (files kept outside the web root, served via an
 * authenticated stream) — same approach as the support module.
 */
class NoteService
{
    /** Real-mime => extension whitelist for attachments (images only). */
    public const ALLOWED_ATTACHMENT_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    public const MAX_ATTACHMENT_SIZE = 5 * 1024 * 1024; // 5 MB per file
    public const MAX_ATTACHMENTS = 10;                  // per note
    private const ATTACHMENT_SUBDIR = 'notes';
    private const CONTENT_MAX = 20000;
    private const TITLE_MAX = 150;

    public function __construct(
        private NoteRepository $repo,
        private NoteAttachmentRepository $attachmentRepo,
        private FileUploadService $fileUpload,
        private NoteCategoryRepository $categoryRepo,
    ) {
    }

    // ── Read ─────────────────────────────────────────────────────

    public function list(int $userId, array $filters): array
    {
        $notes = $this->repo->findAllByUserId($userId, $this->normalizeFilters($filters));
        if ($notes === []) {
            return [];
        }

        $ids = array_map(static fn ($n) => (int) $n['id'], $notes);
        $byNote = [];
        foreach ($this->attachmentRepo->findByNoteIds($ids) as $a) {
            $byNote[(int) $a['note_id']][] = $a;
        }
        foreach ($notes as &$note) {
            $note['attachments'] = $byNote[(int) $note['id']] ?? [];
        }
        unset($note);

        return $notes;
    }

    public function get(int $userId, int $id): array
    {
        return $this->withAttachments($this->requireOwned($userId, $id));
    }

    // ── Write ────────────────────────────────────────────────────

    public function create(int $userId, array $data, array $files = []): array
    {
        $payload = [
            'user_id' => $userId,
            'content' => $this->validateContent($data['content'] ?? null),
            'note_date' => $this->validateNoteDate($data['note_date'] ?? null),
            'title' => $this->validateTitle($data['title'] ?? null),
            'category_id' => $this->resolveCategoryId($userId, $data['category_id'] ?? null),
            'is_pinned' => $this->coercePinned($data['is_pinned'] ?? false),
        ];
        $this->validateAttachmentCount(count($files));

        $note = $this->repo->create($payload);
        $this->storeAttachments((int) $note['id'], $files);

        return $this->get($userId, (int) $note['id']);
    }

    public function update(int $userId, int $id, array $data): array
    {
        $this->requireOwned($userId, $id);

        $patch = [];
        if (array_key_exists('content', $data)) {
            $patch['content'] = $this->validateContent($data['content']);
        }
        if (array_key_exists('note_date', $data)) {
            $patch['note_date'] = $this->validateNoteDate($data['note_date']);
        }
        if (array_key_exists('title', $data)) {
            $patch['title'] = $this->validateTitle($data['title']);
        }
        if (array_key_exists('category_id', $data)) {
            $patch['category_id'] = $this->resolveCategoryId($userId, $data['category_id']);
        }
        if (array_key_exists('is_pinned', $data)) {
            $patch['is_pinned'] = $this->coercePinned($data['is_pinned']);
        }

        $this->repo->update($id, $patch);

        return $this->get($userId, $id);
    }

    public function delete(int $userId, int $id): void
    {
        $this->requireOwned($userId, $id);
        $this->repo->softDelete($id);
    }

    // ── Attachments ──────────────────────────────────────────────

    public function addAttachments(int $userId, int $id, array $files): array
    {
        $this->requireOwned($userId, $id);
        $existing = $this->attachmentRepo->countByNoteId($id);
        $this->validateAttachmentCount($existing + count($files));
        $this->storeAttachments($id, $files);

        return $this->get($userId, $id);
    }

    public function deleteAttachment(int $userId, int $noteId, int $attachmentId): void
    {
        $attachment = $this->attachmentRepo->findByIdForUser($attachmentId, $userId);
        if ($attachment === null || (int) $attachment['note_id'] !== $noteId) {
            throw new NotFoundException('notes.error.attachment_not_found');
        }

        $this->fileUpload->delete($attachment['stored_path']);
        $this->attachmentRepo->delete($attachmentId);
    }

    /** @return array{path:string, attachment:array} */
    public function getAttachmentForUser(int $userId, int $attachmentId): array
    {
        $attachment = $this->attachmentRepo->findByIdForUser($attachmentId, $userId);
        if ($attachment === null) {
            throw new NotFoundException('notes.error.attachment_not_found');
        }

        $path = $this->fileUpload->absolutePath($attachment['stored_path']);
        if (!is_file($path)) {
            throw new NotFoundException('notes.error.attachment_not_found');
        }

        return ['path' => $path, 'attachment' => $attachment];
    }

    // ── Internals ────────────────────────────────────────────────

    private function requireOwned(int $userId, int $id): array
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }

        $note = $this->repo->findById($id);
        if (!$note) {
            throw new NotFoundException('notes.error.not_found');
        }
        if ((int) $note['user_id'] !== $userId) {
            throw new ForbiddenException('notes.error.forbidden');
        }

        return $note;
    }

    private function withAttachments(array $note): array
    {
        $note['attachments'] = $this->attachmentRepo->findByNoteId((int) $note['id']);

        return $note;
    }

    private function normalizeFilters(array $filters): array
    {
        $out = [];
        if (isset($filters['category_id']) && $filters['category_id'] !== '' && (int) $filters['category_id'] > 0) {
            $out['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['pinned']) && filter_var($filters['pinned'], FILTER_VALIDATE_BOOLEAN)) {
            $out['pinned'] = true;
        }

        return $out;
    }

    private function validateContent(mixed $raw): string
    {
        $content = trim((string) $raw);
        if ($content === '') {
            throw new ValidationException('notes.error.content_required', 'content');
        }
        if (mb_strlen($content) > self::CONTENT_MAX) {
            throw new ValidationException('notes.error.content_too_long', 'content');
        }

        return $content;
    }

    private function validateTitle(mixed $raw): ?string
    {
        $title = trim((string) ($raw ?? ''));
        if ($title === '') {
            return null;
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            throw new ValidationException('notes.error.title_too_long', 'title');
        }

        return $title;
    }

    private function validateNoteDate(mixed $raw): string
    {
        $date = trim((string) $raw);
        if ($date === '') {
            throw new ValidationException('notes.error.date_required', 'note_date');
        }
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException('notes.error.invalid_date', 'note_date');
        }

        return $date;
    }

    private function resolveCategoryId(int $userId, mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || (int) $raw === 0) {
            return null;
        }
        $id = (int) $raw;
        $category = $this->categoryRepo->findById($id);
        if (!$category || (int) $category['user_id'] !== $userId) {
            throw new ValidationException('notes.error.invalid_category', 'category_id');
        }

        return $id;
    }

    private function coercePinned(mixed $raw): int
    {
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    private function validateAttachmentCount(int $count): void
    {
        if ($count > self::MAX_ATTACHMENTS) {
            throw new ValidationException('notes.error.too_many_attachments', 'attachments');
        }
    }

    private function storeAttachments(int $noteId, array $files): void
    {
        foreach ($files as $file) {
            $meta = $this->fileUpload->store(
                $file,
                self::ATTACHMENT_SUBDIR,
                self::ALLOWED_ATTACHMENT_TYPES,
                self::MAX_ATTACHMENT_SIZE,
                'attachments'
            );
            $this->attachmentRepo->create([
                'note_id' => $noteId,
                'stored_path' => $meta['stored_path'],
                'original_name' => $meta['original_name'],
                'mime_type' => $meta['mime_type'],
                'size_bytes' => $meta['size_bytes'],
            ]);
        }
    }
}
