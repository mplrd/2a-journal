<?php

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\PositionRepository;
use App\Repositories\SetupRepository;

class SetupService
{
    private SetupRepository $repo;
    private PositionRepository $positionRepo;

    public function __construct(SetupRepository $repo, PositionRepository $positionRepo)
    {
        $this->repo = $repo;
        $this->positionRepo = $positionRepo;
    }

    public function list(int $userId): array
    {
        return $this->repo->findAllByUserId($userId);
    }

    public function create(int $userId, array $data): array
    {
        $label = trim($data['label'] ?? '');

        if ($label === '') {
            throw new ValidationException('setups.error.field_required', 'label');
        }

        if (mb_strlen($label) > 100) {
            throw new ValidationException('setups.error.label_too_long', 'label');
        }

        $existing = $this->repo->findByUserAndLabel($userId, $label);
        if ($existing) {
            throw new ValidationException('setups.error.duplicate_label', 'label');
        }

        return $this->repo->create(['user_id' => $userId, 'label' => $label]);
    }

    private const SUPPORTED_CATEGORIES = ['timeframe', 'pattern', 'context'];

    public function update(int $userId, int $id, array $data): array
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }

        $setup = $this->repo->findById($id);

        if (!$setup) {
            throw new NotFoundException('setups.error.not_found');
        }

        if ((int)$setup['user_id'] !== $userId) {
            throw new ForbiddenException('setups.error.forbidden');
        }

        $patch = [];
        $oldLabel = (string)$setup['label'];
        $newLabel = null;

        if (array_key_exists('label', $data)) {
            $label = trim((string)$data['label']);

            if ($label === '') {
                throw new ValidationException('setups.error.field_required', 'label');
            }

            if (mb_strlen($label) > 100) {
                throw new ValidationException('setups.error.label_too_long', 'label');
            }

            $existing = $this->repo->findByUserAndLabel($userId, $label);
            if ($existing && (int)$existing['id'] !== $id) {
                throw new ValidationException('setups.error.duplicate_label', 'label');
            }

            // The unique key (user_id, label) ignores deleted_at, so a soft-deleted
            // ghost with the same label would block the UPDATE with 1062. Clear it.
            // No UI exposes soft-deleted setups for restore, so hard-delete is safe.
            if ($label !== $oldLabel) {
                $ghost = $this->repo->findAnyByUserAndLabel($userId, $label);
                if ($ghost && (int)$ghost['id'] !== $id && $ghost['deleted_at'] !== null) {
                    $this->repo->hardDelete((int)$ghost['id']);
                }
            }

            $patch['label'] = $label;
            $newLabel = $label;
        }

        if (array_key_exists('category', $data)) {
            $category = $data['category'];
            if ($category === '' || $category === null) {
                $patch['category'] = null;
            } elseif (!in_array($category, self::SUPPORTED_CATEGORIES, true)) {
                throw new ValidationException('setups.error.invalid_category', 'category');
            } else {
                $patch['category'] = $category;
            }
        }

        $updated = $this->repo->update($id, $patch);

        // Propagate the rename into the dénormalized JSON arrays in positions.setup.
        // Skipped when the label is unchanged so we don't churn updated_at on every
        // category-only edit or no-op rename.
        if ($newLabel !== null && $newLabel !== $oldLabel) {
            $this->positionRepo->renameSetupLabel($userId, $oldLabel, $newLabel);
        }

        return $updated;
    }

    public function delete(int $userId, int $id): void
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }

        $setup = $this->repo->findById($id);

        if (!$setup) {
            throw new NotFoundException('setups.error.not_found');
        }

        if ((int)$setup['user_id'] !== $userId) {
            throw new ForbiddenException('setups.error.forbidden');
        }

        $this->repo->softDelete($id);
    }
}
