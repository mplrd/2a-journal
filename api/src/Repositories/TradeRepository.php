<?php

namespace App\Repositories;

use App\Enums\TradeStatus;
use PDO;

class TradeRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO trades (position_id, source_order_id, opened_at, remaining_size, status)
             VALUES (:position_id, :source_order_id, :opened_at, :remaining_size, :status)'
        );
        $stmt->execute([
            'position_id' => $data['position_id'],
            'source_order_id' => $data['source_order_id'] ?? null,
            'opened_at' => $data['opened_at'],
            'remaining_size' => $data['remaining_size'],
            'status' => $data['status'] ?? TradeStatus::OPEN->value,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.position_id, t.source_order_id, t.opened_at, t.closed_at,
                    t.remaining_size, t.be_reached, t.avg_exit_price, t.pnl, t.pnl_percent,
                    t.risk_reward, t.duration_minutes, t.status, t.exit_type,
                    p.user_id, p.account_id, p.direction, p.symbol, p.entry_price, p.size, p.setup,
                    p.sl_points, p.sl_price, p.be_points, p.be_price, p.be_size, p.targets, p.notes,
                    p.position_type, p.created_at, p.updated_at
             FROM trades t
             INNER JOIN positions p ON p.id = t.position_id
             WHERE t.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = 'WHERE p.user_id = :user_id';
        $params = ['user_id' => $userId];

        if (!empty($filters['account_id'])) {
            $where .= ' AND p.account_id = :account_id';
            $params['account_id'] = $filters['account_id'];
        }

        if (!empty($filters['status'])) {
            $where .= ' AND t.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['symbol'])) {
            $where .= ' AND p.symbol = :symbol';
            $params['symbol'] = $filters['symbol'];
        }

        if (!empty($filters['direction'])) {
            $where .= ' AND p.direction = :direction';
            $params['direction'] = $filters['direction'];
        }

        $countSql = "SELECT COUNT(*) FROM trades t INNER JOIN positions p ON p.id = t.position_id $where";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT t.id, t.position_id, t.source_order_id, t.opened_at, t.closed_at,
                       t.remaining_size, t.be_reached, t.avg_exit_price, t.pnl, t.pnl_percent,
                       t.risk_reward, t.duration_minutes, t.status, t.exit_type,
                       p.user_id, p.account_id, p.direction, p.symbol, p.entry_price, p.size, p.setup,
                       p.sl_points, p.sl_price, p.be_points, p.be_price, p.be_size, p.targets, p.notes,
                       p.position_type, p.created_at, p.updated_at
                FROM trades t
                INNER JOIN positions p ON p.id = t.position_id
                $where
                ORDER BY t.opened_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'remaining_size', 'be_reached', 'avg_exit_price', 'pnl', 'pnl_percent',
            'risk_reward', 'duration_minutes', 'status', 'exit_type', 'closed_at',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $sql = 'UPDATE trades SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM trades WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
