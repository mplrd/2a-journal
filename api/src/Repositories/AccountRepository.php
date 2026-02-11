<?php

namespace App\Repositories;

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
            'INSERT INTO accounts (user_id, name, account_type, mode, currency, initial_capital, current_capital, broker, max_drawdown, daily_drawdown, profit_target, profit_split)
             VALUES (:user_id, :name, :account_type, :mode, :currency, :initial_capital, :current_capital, :broker, :max_drawdown, :daily_drawdown, :profit_target, :profit_split)'
        );
        $initialCapital = $data['initial_capital'] ?? 0;
        $stmt->execute([
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'account_type' => $data['account_type'],
            'mode' => $data['mode'],
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
            'SELECT id, user_id, name, account_type, broker, mode, currency, initial_capital, current_capital,
                    max_drawdown, daily_drawdown, profit_target, profit_split, is_active, created_at, updated_at
             FROM accounts WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
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
            'SELECT id, user_id, name, account_type, broker, mode, currency, initial_capital, current_capital,
                    max_drawdown, daily_drawdown, profit_target, profit_split, is_active, created_at, updated_at
             FROM accounts WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['name', 'account_type', 'mode', 'currency', 'initial_capital', 'broker',
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
}
