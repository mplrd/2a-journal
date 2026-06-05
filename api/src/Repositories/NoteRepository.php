<?php

namespace App\Repositories;

use PDO;

/**
 * Data access for personal notebook notes. Per-user, soft-deleted. Each note may
 * carry a category (LEFT JOINed so the label travels with the row) and is exposed
 * with its `category_label` for display without a second round-trip.
 */
class NoteRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    private const SELECT = 'SELECT n.id, n.user_id, n.category_id, c.label AS category_label,
            n.title, n.content, n.note_date, n.is_pinned, n.created_at, n.updated_at
        FROM notes n
        LEFT JOIN note_categories c ON c.id = n.category_id AND c.deleted_at IS NULL';

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notes (user_id, category_id, title, content, note_date, is_pinned)
             VALUES (:user_id, :category_id, :title, :content, :note_date, :is_pinned)'
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'] ?? null,
            'content' => $data['content'],
            'note_date' => $data['note_date'],
            'is_pinned' => $data['is_pinned'] ?? 0,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE n.id = :id AND n.deleted_at IS NULL');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @param array{category_id?:int, pinned?:bool} $filters
     */
    public function findAllByUserId(int $userId, array $filters = []): array
    {
        $sql = self::SELECT . ' WHERE n.user_id = :user_id AND n.deleted_at IS NULL';
        $params = ['user_id' => $userId];

        if (isset($filters['category_id'])) {
            $sql .= ' AND n.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['pinned'])) {
            $sql .= ' AND n.is_pinned = 1';
        }

        // Pinned first on the dashboard-style queries, then most recent note_date.
        $sql .= ' ORDER BY n.is_pinned DESC, n.note_date DESC, n.id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function update(int $id, array $data): ?array
    {
        $allowed = ['category_id', 'title', 'content', 'note_date', 'is_pinned'];
        $fields = [];
        $params = ['id' => $id];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "$col = :$col";
                $params[$col] = $data[$col];
            }
        }

        if ($fields === []) {
            return $this->findById($id);
        }

        $sql = 'UPDATE notes SET ' . implode(', ', $fields) . ' WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findById($id);
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notes SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Detach every note of a (soft-deleted) category so it falls back to "Autre".
     * The FK is ON DELETE SET NULL, but categories are soft-deleted (no row drop),
     * so the reassignment is done explicitly here.
     */
    public function clearCategory(int $userId, int $categoryId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notes SET category_id = NULL
             WHERE user_id = :user_id AND category_id = :category_id'
        );
        $stmt->execute(['user_id' => $userId, 'category_id' => $categoryId]);
    }
}
