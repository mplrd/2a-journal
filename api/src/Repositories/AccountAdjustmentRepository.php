<?php

namespace App\Repositories;

use PDO;

/**
 * Manual balance corrections (ledger) on a trading account. Each row is a
 * signed delta folded into the account's derived current_capital
 * (see AccountRepository). Backs ticket #30 — correcting the gap left by
 * unrecorded fees or a starting balance offset, without touching
 * initial_capital.
 */
class AccountAdjustmentRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO account_balance_adjustments (account_id, amount, reason, adjusted_at)
             VALUES (:account_id, :amount, :reason, COALESCE(:adjusted_at, CURRENT_TIMESTAMP))'
        );
        $stmt->execute([
            'account_id' => $data['account_id'],
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
            'adjusted_at' => $data['adjusted_at'] ?? null,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, amount, reason, adjusted_at, created_at
             FROM account_balance_adjustments
             WHERE account_id = :account_id
             ORDER BY adjusted_at DESC, id DESC'
        );
        $stmt->execute(['account_id' => $accountId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, account_id, amount, reason, adjusted_at, created_at
             FROM account_balance_adjustments
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM account_balance_adjustments WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
