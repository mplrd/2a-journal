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
            'SELECT id, user_id, label, created_at
             FROM setups WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, created_at
             FROM setups WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY label ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function findByUserAndLabel(int $userId, string $label): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, created_at
             FROM setups WHERE user_id = :user_id AND label = :label AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'label' => $label]);
        $row = $stmt->fetch();

        return $row ?: null;
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
