<?php

namespace App\Repositories;

use App\Enums\TradeStatus;
use PDO;

class PositionRepository
{
    private PDO $pdo;

    private const COLUMNS = 'id, user_id, account_id, direction, symbol, entry_price, size, setup,
                    sl_points, sl_price, be_points, be_price, be_size, targets, notes,
                    import_batch_id, external_id, position_type, created_at, updated_at';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup,
                    sl_points, sl_price, be_points, be_price, be_size, targets, notes,
                    import_batch_id, external_id, position_type)
             VALUES (:user_id, :account_id, :direction, :symbol, :entry_price, :size, :setup,
                    :sl_points, :sl_price, :be_points, :be_price, :be_size, :targets, :notes,
                    :import_batch_id, :external_id, :position_type)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'direction' => $data['direction'],
            'symbol' => $data['symbol'],
            'entry_price' => $data['entry_price'],
            'size' => $data['size'],
            'setup' => $data['setup'] ?? null,
            'sl_points' => $data['sl_points'] ?? null,
            'sl_price' => $data['sl_price'] ?? null,
            'be_points' => $data['be_points'] ?? null,
            'be_price' => $data['be_price'] ?? null,
            'be_size' => $data['be_size'] ?? null,
            'targets' => $data['targets'] ?? null,
            'notes' => $data['notes'] ?? null,
            'import_batch_id' => $data['import_batch_id'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'position_type' => $data['position_type'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findAllByUserId(int $userId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = 'WHERE user_id = :user_id';
        $params = ['user_id' => $userId];

        if (!empty($filters['account_id'])) {
            $where .= ' AND account_id = :account_id';
            $params['account_id'] = $filters['account_id'];
        }

        if (!empty($filters['position_type'])) {
            $where .= ' AND position_type = :position_type';
            $params['position_type'] = $filters['position_type'];
        }

        if (!empty($filters['symbol'])) {
            $where .= ' AND symbol = :symbol';
            $params['symbol'] = $filters['symbol'];
        }

        if (!empty($filters['direction'])) {
            $where .= ' AND direction = :direction';
            $params['direction'] = $filters['direction'];
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM positions $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT ' . self::COLUMNS . " FROM positions $where ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM positions WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = [
            'direction', 'symbol', 'entry_price', 'size', 'setup',
            'sl_points', 'sl_price', 'be_points', 'be_price', 'be_size',
            'targets', 'notes', 'position_type',
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

        $sql = 'UPDATE positions SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM positions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function findAggregatedByUserId(int $userId, array $filters = []): array
    {
        $where = 'WHERE p.user_id = :user_id AND t.status IN (:status_open, :status_secured) AND t.remaining_size > 0';
        $params = [
            'user_id' => $userId,
            'status_open' => TradeStatus::OPEN->value,
            'status_secured' => TradeStatus::SECURED->value,
        ];

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

        $sql = "SELECT p.account_id, p.symbol, p.direction,
                       SUM(t.remaining_size) AS total_size,
                       SUM(p.entry_price * t.remaining_size) / SUM(t.remaining_size) AS pru,
                       MIN(t.opened_at) AS first_opened_at
                FROM trades t
                JOIN positions p ON p.id = t.position_id
                $where
                GROUP BY p.account_id, p.symbol, p.direction
                ORDER BY MIN(t.opened_at) DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function transfer(int $id, int $newAccountId): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE positions SET account_id = :account_id WHERE id = :id');
        $stmt->execute(['account_id' => $newAccountId, 'id' => $id]);

        return $this->findById($id);
    }
}
