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
        // created_at is written explicitly when the caller knows when the order
        // was really placed — a broker sync does. Left to MySQL's
        // CURRENT_TIMESTAMP default otherwise (orders created in the app),
        // which is why COALESCE rather than a plain bind.
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (position_id, created_at, expires_at, status)
             VALUES (:position_id, COALESCE(:created_at, CURRENT_TIMESTAMP), :expires_at, :status)'
        );
        $stmt->execute([
            'position_id' => $data['position_id'],
            'created_at' => $data['created_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'status' => $data['status'] ?? OrderStatus::PENDING->value,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.position_id, o.created_at AS order_created_at, o.expires_at, o.status,
                    o.client_order_id, o.broker_order_id,
                    p.user_id, p.account_id, p.direction, p.symbol, p.entry_price, p.size, p.setup, p.plan_id, p.plan_adherence, p.plan_adherence_reason,
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

        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            $placeholders = [];
            foreach (array_values($filters['account_ids']) as $i => $id) {
                $key = "account_id_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = (int) $id;
            }
            $where .= ' AND p.account_id IN (' . implode(', ', $placeholders) . ')';
        } elseif (!empty($filters['account_id'])) {
            $where .= ' AND p.account_id = :account_id';
            $params['account_id'] = $filters['account_id'];
        }

        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $placeholders = [];
            foreach (array_values($filters['statuses']) as $i => $s) {
                $key = "status_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $s;
            }
            $where .= ' AND o.status IN (' . implode(', ', $placeholders) . ')';
        } elseif (!empty($filters['status'])) {
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

        // Plan adherence (docs/83): IN_PLAN / OUT_OF_PLAN qualify orders that
        // reference a plan; NONE selects orders with no plan. Caller whitelists.
        if (!empty($filters['plan_adherence'])) {
            if ($filters['plan_adherence'] === 'NONE') {
                $where .= ' AND p.plan_id IS NULL';
            } else {
                $where .= ' AND p.plan_adherence = :plan_adherence';
                $params['plan_adherence'] = $filters['plan_adherence'];
            }
        }

        $countSql = "SELECT COUNT(*) FROM orders o INNER JOIN positions p ON p.id = o.position_id $where";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT o.id, o.position_id, o.created_at AS order_created_at, o.expires_at, o.status,
                       p.user_id, p.account_id, p.direction, p.symbol, p.entry_price, p.size, p.setup, p.plan_id, p.plan_adherence, p.plan_adherence_reason,
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

    /**
     * Refresh the broker-owned expiry of a pending order. Separate from
     * updateStatus so a sync can correct the date without touching the
     * lifecycle state.
     */
    public function updateExpiry(int $id, ?string $expiresAt): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE orders SET expires_at = :expires_at WHERE id = :id');
        $stmt->execute(['expires_at' => $expiresAt, 'id' => $id]);

        return $this->findById($id);
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

    /**
     * Persist the robot correlation keys after an OPEN signal places the order:
     * client_order_id (indicator-supplied, stable across the lifecycle) and
     * broker_order_id (returned by the broker, used to target amend/cancel/close).
     */
    public function setBrokerCorrelation(int $id, ?string $clientOrderId, ?string $brokerOrderId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET client_order_id = :coid, broker_order_id = :boid WHERE id = :id'
        );
        $stmt->execute(['coid' => $clientOrderId, 'boid' => $brokerOrderId, 'id' => $id]);
    }

    /**
     * Resolve a follow-up signal (MODIFY/CLOSE/CANCEL) back to the order opened
     * for the same client_order_id, scoped to the account for ownership. Only
     * non-terminal orders (PENDING/EXECUTED) are eligible; most recent first.
     */
    public function findLiveByClientOrderId(int $accountId, string $clientOrderId): ?array
    {
        $sql = 'SELECT o.*
                FROM orders o
                INNER JOIN positions p ON p.id = o.position_id
                WHERE p.account_id = :account_id
                  AND o.client_order_id = :coid
                  AND o.status IN (:pending, :executed)
                ORDER BY o.id DESC
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'account_id' => $accountId,
            'coid' => $clientOrderId,
            'pending' => OrderStatus::PENDING->value,
            'executed' => OrderStatus::EXECUTED->value,
        ]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Return current PENDING orders of an account whose position.external_id
     * starts with the given prefix (e.g. 'ouinex_order_'), indexed by
     * external_id for O(1) lookup in the broker-snapshot diff. external_id
     * lives on the positions table; we join orders for the lifecycle
     * filter.
     *
     * Wildcard ('%') is appended in the method to keep the LIKE pattern
     * safe — the caller passes a literal prefix only.
     */
    public function findPendingByExternalIdPrefixInAccount(int $accountId, string $prefix): array
    {
        $sql = 'SELECT o.id AS order_id, o.position_id, o.expires_at, o.status,
                       p.external_id
                FROM orders o
                INNER JOIN positions p ON p.id = o.position_id
                WHERE p.account_id = :account_id
                  AND p.external_id LIKE :prefix
                  AND o.status = :pending_status';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'account_id' => $accountId,
            'prefix' => $prefix . '%',
            'pending_status' => OrderStatus::PENDING->value,
        ]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['external_id']] = $row;
        }
        return $result;
    }
}
