<?php

namespace App\Repositories;

use App\Enums\TradeStatus;
use PDO;

class AccountRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO accounts (user_id, name, account_type, stage, currency, initial_capital, current_capital, broker, max_drawdown, daily_drawdown, profit_target, profit_split)
             VALUES (:user_id, :name, :account_type, :stage, :currency, :initial_capital, :current_capital, :broker, :max_drawdown, :daily_drawdown, :profit_target, :profit_split)'
        );
        $initialCapital = $data['initial_capital'] ?? 0;
        $stmt->execute([
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'account_type' => $data['account_type'],
            'stage' => $data['stage'] ?? null,
            'currency' => $data['currency'] ?? 'EUR',
            'initial_capital' => $initialCapital,
            'current_capital' => $initialCapital,
            'broker' => $data['broker'] ?? null,
            'max_drawdown' => $data['max_drawdown'] ?? null,
            'daily_drawdown' => $data['daily_drawdown'] ?? null,
            'profit_target' => $data['profit_target'] ?? null,
            'profit_split' => $data['profit_split'] ?? null,
        ]);

        return $this->findById((int)$this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.user_id, a.name, a.account_type, a.stage, a.broker, a.currency, a.initial_capital,
                    (a.initial_capital + COALESCE(pnl.total, 0)) AS current_capital,
                    a.max_drawdown, a.daily_drawdown, a.profit_target, a.profit_split,
                    a.is_active, a.created_at, a.updated_at
             FROM accounts a
             LEFT JOIN (
                 SELECT p.account_id, SUM(t.pnl) AS total
                 FROM trades t
                 INNER JOIN positions p ON p.id = t.position_id
                 WHERE t.status = :closed_status
                 GROUP BY p.account_id
             ) pnl ON pnl.account_id = a.id
             WHERE a.id = :id AND a.deleted_at IS NULL'
        );
        $stmt->execute([
            'id' => $id,
            'closed_status' => TradeStatus::CLOSED->value,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId, int $limit = 50, int $offset = 0): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM accounts WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $countStmt->execute(['user_id' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.user_id, a.name, a.account_type, a.stage, a.broker, a.currency, a.initial_capital,
                    (a.initial_capital + COALESCE(pnl.total, 0)) AS current_capital,
                    a.max_drawdown, a.daily_drawdown, a.profit_target, a.profit_split,
                    a.is_active, a.created_at, a.updated_at
             FROM accounts a
             LEFT JOIN (
                 SELECT p.account_id, SUM(t.pnl) AS total
                 FROM trades t
                 INNER JOIN positions p ON p.id = t.position_id
                 WHERE t.status = :closed_status
                 GROUP BY p.account_id
             ) pnl ON pnl.account_id = a.id
             WHERE a.user_id = :user_id AND a.deleted_at IS NULL
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('closed_status', TradeStatus::CLOSED->value, PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['name', 'account_type', 'stage', 'currency', 'initial_capital', 'broker',
                          'max_drawdown', 'daily_drawdown', 'profit_target', 'profit_split'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $sql = 'UPDATE accounts SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE accounts SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Internal lookup for the DD alert engine. Returns the same row as
     * findById() PLUS the dedup timestamps (`last_max_dd_alert_at`,
     * `last_daily_dd_alert_at`) — these are NEVER exposed by the public
     * `findById` SELECT because no API endpoint should leak the alert
     * dispatch history.
     */
    public function findByIdForDdCheck(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, account_type, currency,
                    max_drawdown, daily_drawdown,
                    last_max_dd_alert_at, last_daily_dd_alert_at
             FROM accounts
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Stamp the dedup column for a DD alert. $type is 'max' or 'daily';
     * any other value is silently ignored (defensive — caller validates).
     */
    public function markDdAlertSent(int $id, string $type): void
    {
        $column = match ($type) {
            'max' => 'last_max_dd_alert_at',
            'daily' => 'last_daily_dd_alert_at',
            default => null,
        };
        if ($column === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE accounts SET {$column} = NOW() WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
    }
}
