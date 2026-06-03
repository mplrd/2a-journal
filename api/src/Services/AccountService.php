<?php

namespace App\Services;

use App\Enums\AccountStage;
use App\Enums\AccountType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountAdjustmentRepository;
use App\Repositories\AccountRepository;

class AccountService
{
    private AccountRepository $repo;
    private AccountAdjustmentRepository $adjustmentRepo;

    public function __construct(AccountRepository $repo, AccountAdjustmentRepository $adjustmentRepo)
    {
        $this->repo = $repo;
        $this->adjustmentRepo = $adjustmentRepo;
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

        $data['user_id'] = $userId;
        return $this->repo->create($data);
    }

    public function get(int $userId, int $accountId): array
    {
        $this->validateId($accountId);
        $account = $this->repo->findById($accountId);

        if (!$account) {
            throw new NotFoundException('accounts.error.not_found');
        }

        if ((int)$account['user_id'] !== $userId) {
            throw new ForbiddenException('accounts.error.forbidden');
        }

        return $account;
    }

    public function update(int $userId, int $accountId, array $data): array
    {
        $account = $this->get($userId, $accountId);

        $this->validate($data);

        return $this->repo->update((int)$account['id'], $data);
    }

    public function delete(int $userId, int $accountId): void
    {
        $this->get($userId, $accountId);

        $this->repo->softDelete($accountId);
    }

    /**
     * Record a manual balance correction (ticket #30). The amount is a signed
     * delta folded into current_capital — initial_capital is never touched.
     * Reuses get() for ownership/not-found enforcement.
     */
    public function addAdjustment(int $userId, int $accountId, array $data): array
    {
        $this->get($userId, $accountId);

        if (!isset($data['amount']) || !is_numeric($data['amount']) || (float) $data['amount'] === 0.0) {
            throw new ValidationException('accounts.error.invalid_adjustment', 'amount');
        }

        $reason = $data['reason'] ?? null;
        if ($reason !== null && mb_strlen((string) $reason) > 255) {
            throw new ValidationException('accounts.error.reason_too_long', 'reason');
        }

        // adjusted_at is server-authoritative: the client cannot backdate or
        // inject a value (repo defaults to CURRENT_TIMESTAMP when absent).
        return $this->adjustmentRepo->create([
            'account_id' => $accountId,
            'amount' => (float) $data['amount'],
            'reason' => $reason !== null && trim((string) $reason) !== '' ? (string) $reason : null,
        ]);
    }

    public function listAdjustments(int $userId, int $accountId): array
    {
        $this->get($userId, $accountId);

        return $this->adjustmentRepo->findByAccountId($accountId);
    }

    public function deleteAdjustment(int $userId, int $accountId, int $adjustmentId): void
    {
        $this->get($userId, $accountId);
        $this->validateId($adjustmentId);

        $adjustment = $this->adjustmentRepo->findById($adjustmentId);
        if (!$adjustment || (int) $adjustment['account_id'] !== $accountId) {
            throw new NotFoundException('accounts.error.adjustment_not_found');
        }

        $this->adjustmentRepo->delete($adjustmentId);
    }

    private function validate(array $data): void
    {
        if (empty($data['name'])) {
            throw new ValidationException('accounts.error.field_required', 'name');
        }

        if (mb_strlen($data['name']) > 100) {
            throw new ValidationException('error.field_too_long', 'name');
        }

        if (empty($data['account_type'])) {
            throw new ValidationException('accounts.error.field_required', 'account_type');
        }

        $accountType = AccountType::tryFrom($data['account_type']);
        if (!$accountType) {
            throw new ValidationException('accounts.error.invalid_type', 'account_type');
        }

        if ($accountType === AccountType::PROP_FIRM) {
            if (empty($data['stage'])) {
                throw new ValidationException('accounts.error.stage_required', 'stage');
            }
            if (!AccountStage::tryFrom($data['stage'])) {
                throw new ValidationException('accounts.error.invalid_stage', 'stage');
            }
        } else {
            if (!empty($data['stage'])) {
                throw new ValidationException('accounts.error.stage_not_allowed', 'stage');
            }
        }

        if (isset($data['currency']) && strlen($data['currency']) !== 3) {
            throw new ValidationException('accounts.error.field_required', 'currency');
        }

        if (isset($data['initial_capital']) && (float)$data['initial_capital'] < 0) {
            throw new ValidationException('accounts.error.invalid_capital', 'initial_capital');
        }

        if (isset($data['broker']) && mb_strlen($data['broker']) > 100) {
            throw new ValidationException('accounts.error.field_required', 'broker');
        }

        if (isset($data['max_drawdown']) && (float)$data['max_drawdown'] < 0) {
            throw new ValidationException('accounts.error.invalid_capital', 'max_drawdown');
        }

        if (isset($data['daily_drawdown']) && (float)$data['daily_drawdown'] < 0) {
            throw new ValidationException('accounts.error.invalid_capital', 'daily_drawdown');
        }

        if (isset($data['profit_target']) && (float)$data['profit_target'] < 0) {
            throw new ValidationException('accounts.error.invalid_capital', 'profit_target');
        }

        if (isset($data['profit_split']) && ((float)$data['profit_split'] < 0 || (float)$data['profit_split'] > 100)) {
            throw new ValidationException('accounts.error.invalid_capital', 'profit_split');
        }
    }

    private function validateId(int $id): void
    {
        if ($id <= 0) {
            throw new ValidationException('error.invalid_id', 'id');
        }
    }
}
