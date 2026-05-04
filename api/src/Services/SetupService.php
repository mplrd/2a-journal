<?php

namespace App\Services;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\SetupRepository;

class SetupService
{
    private SetupRepository $repo;

    public function __construct(SetupRepository $repo)
    {
        $this->repo = $repo;
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

            $patch['label'] = $label;
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

        return $this->repo->update($id, $patch);
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
