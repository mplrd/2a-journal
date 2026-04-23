<?php

namespace App\Services;

use App\Enums\SymbolType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\SymbolAccountSettingsRepository;
use App\Repositories\SymbolRepository;

class SymbolService
{
    private SymbolRepository $repo;
    private ?SymbolAccountSettingsRepository $settingsRepo;
    private ?AccountRepository $accountRepo;

    public function __construct(
        SymbolRepository $repo,
        ?SymbolAccountSettingsRepository $settingsRepo = null,
        ?AccountRepository $accountRepo = null
    ) {
        $this->repo = $repo;
        $this->settingsRepo = $settingsRepo;
        $this->accountRepo = $accountRepo;
    }

    public function list(int $userId, array $params = []): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        $result = $this->repo->findAllByUserId($userId, $perPage, $offset);
        $total = $result['total'];
        $totalPages = (int) ceil($total / $perPage);

        return [
            'data' => $result['items'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function create(int $userId, array $data): array
    {
        $this->validate($data);

        // Duplicate check
        $existing = $this->repo->findByUserAndCode($userId, $data['code']);
        if ($existing) {
            throw new ValidationException('symbols.error.duplicate_code', 'code');
        }

        // Restore soft-deleted symbol if same code exists
        $softDeleted = $this->repo->findSoftDeletedByUserAndCode($userId, $data['code']);
        if ($softDeleted) {
            return $this->repo->restore((int)$softDeleted['id'], $data);
        }

        $data['user_id'] = $userId;
        return $this->repo->create($data);
    }

    public function get(int $userId, int $symbolId): array
    {
        $this->validateId($symbolId);
        $symbol = $this->repo->findById($symbolId);

        if (!$symbol) {
            throw new NotFoundException('symbols.error.not_found');
        }

        if ((int)$symbol['user_id'] !== $userId) {
            throw new ForbiddenException('symbols.error.forbidden');
        }

        return $symbol;
    }

    public function update(int $userId, int $symbolId, array $data): array
    {
        $this->validateId($symbolId);
        $symbol = $this->get($userId, $symbolId);

        $this->validate($data);

        // Duplicate check if code is changing
        if (isset($data['code']) && $data['code'] !== $symbol['code']) {
            $existing = $this->repo->findByUserAndCode($userId, $data['code']);
            if ($existing) {
                throw new ValidationException('symbols.error.duplicate_code', 'code');
            }

            // Remove soft-deleted row with same code to avoid unique constraint conflict
            $softDeleted = $this->repo->findSoftDeletedByUserAndCode($userId, $data['code']);
            if ($softDeleted) {
                $this->repo->hardDelete((int)$softDeleted['id']);
            }
        }

        return $this->repo->update((int)$symbol['id'], $data);
    }

    public function delete(int $userId, int $symbolId): void
    {
        $this->validateId($symbolId);
        $this->get($userId, $symbolId);

        $this->repo->softDelete($symbolId);
    }

    public function seedForUser(int $userId): void
    {
        $this->repo->seedForUser($userId);
    }

    private function validate(array $data): void
    {
        if (empty($data['code'])) {
            throw new ValidationException('symbols.error.field_required', 'code');
        }

        if (mb_strlen($data['code']) > 20) {
            throw new ValidationException('error.field_too_long', 'code');
        }

        if (empty($data['name'])) {
            throw new ValidationException('symbols.error.field_required', 'name');
        }

        if (mb_strlen($data['name']) > 100) {
            throw new ValidationException('error.field_too_long', 'name');
        }

        if (empty($data['type'])) {
            throw new ValidationException('symbols.error.field_required', 'type');
        }

        if (!SymbolType::tryFrom($data['type'])) {
            throw new ValidationException('symbols.error.invalid_type', 'type');
        }

        // point_value and currency are legacy on the symbols table; the UI no longer
        // exposes them. We still accept them for backwards compatibility with callers
        // that haven't been updated (and for the import flow which uses point_value=1.0
        // as a safe default).
        if (isset($data['point_value']) && (float)$data['point_value'] <= 0) {
            throw new ValidationException('symbols.error.invalid_point_value', 'point_value');
        }

        if (isset($data['currency']) && mb_strlen($data['currency']) !== 3) {
            throw new ValidationException('symbols.error.invalid_currency', 'currency');
        }
    }

    private function validateId(int $id): void
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }
    }

    // ── Per-(symbol, account) point value ───────────────────────

    /**
     * Return the settings matrix for the user. Auto-materializes missing rows
     * (one per (symbol, account) owned by the user, inheriting point_value from
     * the symbol). Idempotent.
     */
    public function getSettingsMatrix(int $userId): array
    {
        $this->ensureSettingsRepo();
        $this->settingsRepo->autoMaterializeForUser($userId);
        return [
            'settings' => $this->settingsRepo->findAllByUserId($userId),
        ];
    }

    public function setSetting(int $userId, int $symbolId, int $accountId, float $pointValue): void
    {
        $this->ensureSettingsRepo();
        $this->assertOwnership($userId, $symbolId, $accountId);

        if ($pointValue <= 0) {
            throw new ValidationException('symbols.error.invalid_point_value', 'point_value');
        }

        $this->settingsRepo->upsert($symbolId, $accountId, $pointValue);
    }

    public function clearSetting(int $userId, int $symbolId, int $accountId): void
    {
        $this->ensureSettingsRepo();
        $this->assertOwnership($userId, $symbolId, $accountId);
        $this->settingsRepo->delete($symbolId, $accountId);
    }

    private function assertOwnership(int $userId, int $symbolId, int $accountId): void
    {
        $this->validateId($symbolId);
        $this->validateId($accountId);

        $symbol = $this->repo->findById($symbolId);
        if (!$symbol || (int) $symbol['user_id'] !== $userId) {
            // Hide existence (return 404) to avoid leaking which IDs belong to another user.
            throw new NotFoundException('symbols.error.not_found');
        }

        $account = $this->accountRepo?->findById($accountId);
        if (!$account || (int) $account['user_id'] !== $userId) {
            throw new NotFoundException('accounts.error.not_found');
        }
    }

    private function ensureSettingsRepo(): void
    {
        if (!$this->settingsRepo || !$this->accountRepo) {
            throw new \LogicException('SymbolService requires settingsRepo and accountRepo for settings operations');
        }
    }
}
