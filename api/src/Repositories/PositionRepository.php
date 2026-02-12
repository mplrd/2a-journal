<?php

namespace App\Repositories;

use PDO;

class PositionRepository
{
    private PDO $pdo;

    private const COLUMNS = 'id, user_id, account_id, direction, symbol, entry_price, size, setup,
                    sl_points, sl_price, be_points, be_price, be_size, targets, notes,
                    position_type, created_at, updated_at';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO positions (user_id, account_id, direction, symbol, entry_price, size, setup,
                    sl_points, sl_price, be_points, be_price, be_size, targets, notes, position_type)
             VALUES (:user_id, :account_id, :direction, :symbol, :entry_price, :size, :setup,
                    :sl_points, :sl_price, :be_points, :be_price, :be_size, :targets, :notes, :position_type)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'direction' => $data['direction'],
            'symbol' => $data['symbol'],
            'entry_price' => $data['entry_price'],
            'size' => $data['size'],
            'setup' => $data['setup'],
            'sl_points' => $data['sl_points'],
            'sl_price' => $data['sl_price'],
            'be_points' => $data['be_points'] ?? null,
            'be_price' => $data['be_price'] ?? null,
            'be_size' => $data['be_size'] ?? null,
            'targets' => $data['targets'] ?? null,
            'notes' => $data['notes'] ?? null,
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

    public function transfer(int $id, int $newAccountId): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE positions SET account_id = :account_id WHERE id = :id');
        $stmt->execute(['account_id' => $newAccountId, 'id' => $id]);

        return $this->findById($id);
    }
}
