<?php

namespace App\Repositories;

use App\Enums\SymbolType;
use PDO;

class SymbolRepository
{
    private PDO $pdo;

    private static function defaultSymbols(): array
    {
        return [
            ['code' => 'US100.CASH', 'name' => 'NASDAQ 100', 'type' => SymbolType::INDEX->value, 'point_value' => 20.0, 'currency' => 'USD'],
            ['code' => 'DE40.CASH', 'name' => 'DAX 40', 'type' => SymbolType::INDEX->value, 'point_value' => 25.0, 'currency' => 'EUR'],
            ['code' => 'US500.CASH', 'name' => 'S&P 500', 'type' => SymbolType::INDEX->value, 'point_value' => 50.0, 'currency' => 'USD'],
            ['code' => 'FRA40.CASH', 'name' => 'CAC 40', 'type' => SymbolType::INDEX->value, 'point_value' => 10.0, 'currency' => 'EUR'],
            ['code' => 'EURUSD', 'name' => 'EUR/USD', 'type' => SymbolType::FOREX->value, 'point_value' => 10.0, 'currency' => 'USD'],
            ['code' => 'BTCUSD', 'name' => 'BTC/USD', 'type' => SymbolType::CRYPTO->value, 'point_value' => 1.0, 'currency' => 'USD'],
        ];
    }

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO symbols (user_id, code, name, type, point_value, currency)
             VALUES (:user_id, :code, :name, :type, :point_value, :currency)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'point_value' => $data['point_value'] ?? 1.0,
            'currency' => $data['currency'] ?? 'USD',
        ]);

        return $this->findById((int)$this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, code, name, type, point_value, currency, is_active, created_at, updated_at
             FROM symbols WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId, int $limit = 50, int $offset = 0): array
    {
        $countStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM symbols WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $countStmt->execute(['user_id' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, code, name, type, point_value, currency, is_active, created_at, updated_at
             FROM symbols WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY code ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function findByUserAndCode(int $userId, string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, code, name, type, point_value, currency, is_active, created_at, updated_at
             FROM symbols WHERE user_id = :user_id AND code = :code AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'code' => $code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findSoftDeletedByUserAndCode(int $userId, string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, code, name, type, point_value, currency, is_active, created_at, updated_at, deleted_at
             FROM symbols WHERE user_id = :user_id AND code = :code AND deleted_at IS NOT NULL'
        );
        $stmt->execute(['user_id' => $userId, 'code' => $code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function restore(int $id, array $data): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE symbols SET name = :name, type = :type, point_value = :point_value,
             currency = :currency, deleted_at = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $data['name'],
            'type' => $data['type'],
            'point_value' => $data['point_value'] ?? 1.0,
            'currency' => $data['currency'] ?? 'USD',
        ]);

        return $this->findById($id);
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        $allowedFields = ['code', 'name', 'type', 'point_value', 'currency'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $sql = 'UPDATE symbols SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE symbols SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function hardDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM symbols WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function seedForUser(int $userId): void
    {
        foreach (self::defaultSymbols() as $symbol) {
            $this->create(array_merge($symbol, ['user_id' => $userId]));
        }
    }
}
