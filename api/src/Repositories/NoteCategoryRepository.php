<?php

namespace App\Repositories;

use PDO;

/**
 * User-defined notebook categories. Mirrors SetupRepository (per-user labels,
 * soft delete, unique (user_id, label)) but without the fixed taxonomy ENUM —
 * here the labels themselves are the user's free creation.
 */
class NoteCategoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO note_categories (user_id, label) VALUES (:user_id, :label)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'label' => $data['label'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, created_at
             FROM note_categories WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, created_at
             FROM note_categories WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY label ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function findByUserAndLabel(int $userId, string $label): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, created_at
             FROM note_categories WHERE user_id = :user_id AND label = :label AND deleted_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'label' => $label]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Look up a category by (user_id, label) including soft-deleted rows, so the
     * create/rename flow can clear a soft-deleted ghost that would otherwise
     * collide with the unique constraint uk_note_categories_user_label.
     */
    public function findAnyByUserAndLabel(int $userId, string $label): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, label, created_at, deleted_at
             FROM note_categories WHERE user_id = :user_id AND label = :label
             ORDER BY deleted_at IS NULL DESC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'label' => $label]);

        return $stmt->fetch() ?: null;
    }

    public function update(int $id, array $data): ?array
    {
        if (!array_key_exists('label', $data)) {
            return $this->findById($id);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE note_categories SET label = :label WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['label' => $data['label'], 'id' => $id]);

        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE note_categories SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Physically removes a category row. Used to clear a soft-deleted ghost that
     * blocks the unique constraint when (re)creating a category with the same
     * label. There is no UI to restore soft-deleted categories, so this is safe.
     */
    public function hardDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM note_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
