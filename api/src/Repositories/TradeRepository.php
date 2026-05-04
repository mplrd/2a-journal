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

    public function findByPositionId(int $positionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.position_id, t.source_order_id, t.opened_at, t.closed_at,
                    t.remaining_size, t.be_reached, t.avg_exit_price, t.pnl, t.pnl_percent,
                    t.risk_reward, t.duration_minutes, t.status, t.exit_type
             FROM trades t
             WHERE t.position_id = :position_id'
        );
        $stmt->execute(['position_id' => $positionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $joins = '';
        $where = 'WHERE p.user_id = :user_id';
        $params = ['user_id' => $userId];

        // `account_ids` (list) takes precedence over the legacy `account_id`
        // (single) when both are present. Caller is expected to have already
        // narrowed to int values; we just bind here.
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

        // `statuses` (list) takes precedence over the legacy `status` (single)
        // when both are present. Values are assumed already whitelisted by the
        // caller via the TradeStatus enum.
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $placeholders = [];
            foreach (array_values($filters['statuses']) as $i => $s) {
                $key = "status_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $s;
            }
            $where .= ' AND t.status IN (' . implode(', ', $placeholders) . ')';
        } elseif (!empty($filters['status'])) {
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

        if (!empty($filters['custom_filter'])) {
            $joins .= ' INNER JOIN custom_field_values cfv ON cfv.trade_id = t.id';
            $where .= ' AND cfv.custom_field_id = :cf_field_id AND cfv.value = :cf_value';
            $params['cf_field_id'] = $filters['custom_filter']['field_id'];
            $params['cf_value'] = $filters['custom_filter']['value'];
        }

        $countSql = "SELECT COUNT(*) FROM trades t INNER JOIN positions p ON p.id = t.position_id $joins $where";
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
                $joins
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
            'risk_reward', 'duration_minutes', 'status', 'exit_type', 'opened_at', 'closed_at',
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

    /**
     * Sum of realized P&L for an account, all-time. Used by drawdown computation.
     * Includes any trade with a non-null pnl (i.e. SECURED with partials taken
     * AND fully CLOSED). Returns 0.0 if no trades or all pnl null.
     */
    public function sumRealizedPnlForAccount(int $accountId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(t.pnl), 0)
             FROM trades t
             INNER JOIN positions p ON p.id = t.position_id
             WHERE p.account_id = :account_id AND t.pnl IS NOT NULL'
        );
        $stmt->execute(['account_id' => $accountId]);

        return (float) $stmt->fetchColumn();
    }

    /**
     * Sum of realized P&L for an account from a given UTC datetime onward.
     * Used to compute "today's P&L" relative to the user's local-midnight.
     * Caller passes a UTC-formatted timestamp ('Y-m-d H:i:s').
     */
    public function sumRealizedPnlForAccountSince(int $accountId, string $sinceUtc): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(t.pnl), 0)
             FROM trades t
             INNER JOIN positions p ON p.id = t.position_id
             WHERE p.account_id = :account_id
               AND t.pnl IS NOT NULL
               AND t.closed_at >= :since'
        );
        $stmt->execute([
            'account_id' => $accountId,
            'since' => $sinceUtc,
        ]);

        return (float) $stmt->fetchColumn();
    }
}
