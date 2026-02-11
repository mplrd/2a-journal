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

    public function findAllByUserId(int $userId, array $filters = []): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM positions WHERE user_id = :user_id';
        $params = ['user_id' => $userId];

        if (!empty($filters['account_id'])) {
            $sql .= ' AND account_id = :account_id';
            $params['account_id'] = $filters['account_id'];
        }

        if (!empty($filters['position_type'])) {
            $sql .= ' AND position_type = :position_type';
            $params['position_type'] = $filters['position_type'];
        }

        if (!empty($filters['symbol'])) {
            $sql .= ' AND symbol = :symbol';
            $params['symbol'] = $filters['symbol'];
        }

        if (!empty($filters['direction'])) {
            $sql .= ' AND direction = :direction';
            $params['direction'] = $filters['direction'];
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
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
            'targets', 'notes',
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
