<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use PDO;

class OrderRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (position_id, expires_at, status)
             VALUES (:position_id, :expires_at, :status)'
        );
        $stmt->execute([
            'position_id' => $data['position_id'],
            'expires_at' => $data['expires_at'] ?? null,
            'status' => $data['status'] ?? OrderStatus::PENDING->value,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.position_id, o.created_at AS order_created_at, o.expires_at, o.status,
                    p.user_id, p.account_id, p.direction, p.symbol, p.entry_price, p.size, p.setup,
                    p.sl_points, p.sl_price, p.be_points, p.be_price, p.be_size, p.targets, p.notes,
                    p.position_type, p.created_at, p.updated_at
             FROM orders o
             INNER JOIN positions p ON p.id = o.position_id
             WHERE o.id = :id'
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
            $where .= ' AND o.status = :status';
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

        $countSql = "SELECT COUNT(*) FROM orders o INNER JOIN positions p ON p.id = o.position_id $where";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT o.id, o.position_id, o.created_at AS order_created_at, o.expires_at, o.status,
                       p.user_id, p.account_id, p.direction, p.symbol, p.entry_price, p.size, p.setup,
                       p.sl_points, p.sl_price, p.be_points, p.be_price, p.be_size, p.targets, p.notes,
                       p.position_type, p.created_at, p.updated_at
                FROM orders o
                INNER JOIN positions p ON p.id = o.position_id
                $where
                ORDER BY o.created_at DESC
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

    public function updateStatus(int $id, string $newStatus): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $newStatus, 'id' => $id]);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
