<?php

namespace App\Repositories;

use PDO;

class SupportTicketAttachmentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_ticket_attachments (ticket_id, message_id, stored_path, original_name, mime_type, size_bytes)
             VALUES (:ticket_id, :message_id, :stored_path, :original_name, :mime_type, :size_bytes)'
        );
        $stmt->execute([
            'ticket_id' => $data['ticket_id'],
            'message_id' => $data['message_id'] ?? null,
            'stored_path' => $data['stored_path'],
            'original_name' => $data['original_name'],
            'mime_type' => $data['mime_type'],
            'size_bytes' => $data['size_bytes'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_ticket_attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByTicketId(int $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM support_ticket_attachments WHERE ticket_id = :ticket_id ORDER BY id ASC'
        );
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll();
    }
}
