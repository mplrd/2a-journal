<?php

namespace App\Repositories;

use PDO;

class SetupRepository
{
    private PDO $pdo;

    private static function defaultLabels(): array
    {
        return ['Breakout', 'FVG', 'OB', 'Liquidity Sweep', 'BOS', 'CHoCH', 'Supply/Demand', 'Trend Follow'];
    }

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO setups (user_id, label) VALUES (:user_id, :label)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'label' => $data['label'],
        ]);

        return $this->findById((int)$this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, category, created_at
             FROM setups WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, category, created_at
             FROM setups WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY label ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function findByUserAndLabel(int $userId, string $label): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, category, created_at
             FROM setups WHERE user_id = :user_id AND label = :label AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'label' => $label]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Look up a setup by (user_id, label) including soft-deleted rows. Used by
     * the rename flow to detect a soft-deleted ghost that would otherwise
     * collide with the unique constraint `uk_setups_user_label (user_id, label)`.
     * Active row is preferred when both exist (impossible per the constraint,
     * but ordering makes the contract explicit).
     */
    public function findAnyByUserAndLabel(int $userId, string $label): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, category, created_at, deleted_at
             FROM setups WHERE user_id = :user_id AND label = :label
             ORDER BY deleted_at IS NULL DESC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'label' => $label]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Physically removes a setup row. Used to clear a soft-deleted ghost
     * that blocks the unique constraint when renaming another setup to the
     * same label. There is no UI to restore soft-deleted setups, so this is
     * a safe operation.
     */
    public function hardDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM setups WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function update(int $id, array $data): ?array
    {
        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('label', $data)) {
            $fields[] = 'label = :label';
            $params['label'] = $data['label'];
        }

        if (array_key_exists('category', $data)) {
            $fields[] = 'category = :category';
            $params['category'] = $data['category'];
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $sql = 'UPDATE setups SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE setups SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function seedForUser(int $userId): void
    {
        foreach (self::defaultLabels() as $label) {
            $this->create(['user_id' => $userId, 'label' => $label]);
        }
    }

    public function ensureExist(int $userId, array $labels): void
    {
        if (empty($labels)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO setups (user_id, label) VALUES (:user_id, :label)
             ON DUPLICATE KEY UPDATE deleted_at = NULL'
        );

        foreach ($labels as $label) {
            $stmt->execute(['user_id' => $userId, 'label' => trim($label)]);
        }
    }
}
