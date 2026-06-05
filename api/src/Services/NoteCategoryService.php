<?php

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\NoteCategoryRepository;
use App\Repositories\NoteRepository;

/**
 * Business logic for the notebook's user-defined categories. Same shape as
 * SetupService (per-user CRUD, ownership enforced here, soft-deleted ghost
 * cleanup on rename) but categories are referenced by notes via a real FK, so
 * deleting one detaches its notes (they fall back to "Autre" / NULL) instead of
 * orphaning them.
 */
class NoteCategoryService
{
    private const LABEL_MAX = 100;

    public function __construct(
        private NoteCategoryRepository $repo,
        private NoteRepository $noteRepo,
    ) {
    }

    public function list(int $userId): array
    {
        return $this->repo->findAllByUserId($userId);
    }

    public function create(int $userId, array $data): array
    {
        $label = $this->validateLabel($data['label'] ?? null);

        if ($this->repo->findByUserAndLabel($userId, $label)) {
            throw new ValidationException('note_categories.error.duplicate_label', 'label');
        }

        $this->clearGhost($userId, $label);

        return $this->repo->create(['user_id' => $userId, 'label' => $label]);
    }

    public function update(int $userId, int $id, array $data): array
    {
        $category = $this->requireOwned($userId, $id);

        if (!array_key_exists('label', $data)) {
            return $category;
        }

        $label = $this->validateLabel($data['label']);
        $existing = $this->repo->findByUserAndLabel($userId, $label);
        if ($existing && (int) $existing['id'] !== $id) {
            throw new ValidationException('note_categories.error.duplicate_label', 'label');
        }

        if ($label !== (string) $category['label']) {
            $this->clearGhost($userId, $label, $id);
        }

        return $this->repo->update($id, ['label' => $label]);
    }

    public function delete(int $userId, int $id): void
    {
        $this->requireOwned($userId, $id);

        // Detach notes first so they fall back to "Autre" (NULL) rather than
        // pointing at a soft-deleted category whose label would still render.
        $this->noteRepo->clearCategory($userId, $id);
        $this->repo->softDelete($id);
    }

    private function requireOwned(int $userId, int $id): array
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }

        $category = $this->repo->findById($id);
        if (!$category) {
            throw new NotFoundException('note_categories.error.not_found');
        }
        if ((int) $category['user_id'] !== $userId) {
            throw new ForbiddenException('note_categories.error.forbidden');
        }

        return $category;
    }

    private function validateLabel(mixed $raw): string
    {
        $label = trim((string) $raw);
        if ($label === '') {
            throw new ValidationException('note_categories.error.field_required', 'label');
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            throw new ValidationException('note_categories.error.label_too_long', 'label');
        }

        return $label;
    }

    /**
     * The unique key (user_id, label) ignores deleted_at, so a soft-deleted ghost
     * with the same label blocks INSERT/UPDATE with 1062. No UI restores deleted
     * categories, so hard-deleting the ghost is safe.
     */
    private function clearGhost(int $userId, string $label, ?int $excludeId = null): void
    {
        $ghost = $this->repo->findAnyByUserAndLabel($userId, $label);
        if ($ghost && ($excludeId === null || (int) $ghost['id'] !== $excludeId) && ($ghost['deleted_at'] ?? null) !== null) {
            $this->repo->hardDelete((int) $ghost['id']);
        }
    }
}
