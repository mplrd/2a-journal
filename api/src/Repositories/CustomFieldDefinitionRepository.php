<?php

namespace App\Repositories;

use PDO;

class CustomFieldDefinitionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO custom_field_definitions (user_id, name, field_type, options, sort_order)
             VALUES (:user_id, :name, :field_type, :options, :sort_order)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'name' => $data['name'],
            'field_type' => $data['field_type'],
            'options' => $data['options'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, field_type, options, sort_order, is_active, created_at, updated_at
             FROM custom_field_definitions WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, field_type, options, sort_order, is_active, created_at, updated_at
             FROM custom_field_definitions
             WHERE user_id = :user_id AND deleted_at IS NULL
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function findActiveByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, field_type, options, sort_order, is_active, created_at, updated_at
             FROM custom_field_definitions
             WHERE user_id = :user_id AND is_active = 1 AND deleted_at IS NULL
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function findByUserAndName(int $userId, string $name): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, name, field_type, options, sort_order, is_active, created_at, updated_at
             FROM custom_field_definitions
             WHERE user_id = :user_id AND name = :name AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'name' => $name]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function update(int $id, array $data): array
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['name', 'field_type', 'options', 'sort_order', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (!empty($fields)) {
            $sql = 'UPDATE custom_field_definitions SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE custom_field_definitions SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function getNextSortOrder(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1
             FROM custom_field_definitions
             WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}
