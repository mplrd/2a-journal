<?php

namespace App\Repositories;

use PDO;

/**
 * Metadata for note images. The binary files live outside the web root
 * (api/storage/uploads/notes/); only their path + descriptor is stored here.
 */
class NoteAttachmentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO note_attachments (note_id, stored_path, original_name, mime_type, size_bytes)
             VALUES (:note_id, :stored_path, :original_name, :mime_type, :size_bytes)'
        );
        $stmt->execute([
            'note_id' => $data['note_id'],
            'stored_path' => $data['stored_path'],
            'original_name' => $data['original_name'],
            'mime_type' => $data['mime_type'],
            'size_bytes' => $data['size_bytes'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM note_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Resolve an attachment only if it belongs to a live note owned by the user.
     * Used to gate downloads and single-image deletion.
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.* FROM note_attachments a
             JOIN notes n ON n.id = a.note_id
             WHERE a.id = :id AND n.user_id = :user_id AND n.deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id, 'user_id' => $userId]);

        return $stmt->fetch() ?: null;
    }

    public function findByNoteId(int $noteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM note_attachments WHERE note_id = :note_id ORDER BY id ASC'
        );
        $stmt->execute(['note_id' => $noteId]);

        return $stmt->fetchAll();
    }

    /**
     * Batch-load attachments for a set of notes (list view), avoiding N+1.
     *
     * @param int[] $noteIds
     */
    public function findByNoteIds(array $noteIds): array
    {
        if ($noteIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM note_attachments WHERE note_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute(array_map('intval', $noteIds));

        return $stmt->fetchAll();
    }

    public function countByNoteId(int $noteId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM note_attachments WHERE note_id = :note_id');
        $stmt->execute(['note_id' => $noteId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM note_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
