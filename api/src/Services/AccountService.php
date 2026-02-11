<?php

namespace App\Services;

use App\Enums\AccountMode;
use App\Enums\AccountType;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;

class AccountService
{
    private AccountRepository $repo;

    public function __construct(AccountRepository $repo)
    {
        $this->repo = $repo;
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

    private function validate(array $data): void
    {
        if (empty($data['name'])) {
            throw new ValidationException('accounts.error.field_required', 'name');
        }

        if (mb_strlen($data['name']) > 100) {
            throw new ValidationException('accounts.error.field_required', 'name');
        }

        if (empty($data['account_type'])) {
            throw new ValidationException('accounts.error.field_required', 'account_type');
        }

        if (!AccountType::tryFrom($data['account_type'])) {
            throw new ValidationException('accounts.error.invalid_type', 'account_type');
        }

        if (empty($data['mode'])) {
            throw new ValidationException('accounts.error.field_required', 'mode');
        }

        if (!AccountMode::tryFrom($data['mode'])) {
            throw new ValidationException('accounts.error.invalid_mode', 'mode');
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
