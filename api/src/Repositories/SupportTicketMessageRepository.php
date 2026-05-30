<?php

namespace App\Repositories;

use PDO;

class SupportTicketMessageRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO support_ticket_messages (ticket_id, author_id, author_is_admin, body)
             VALUES (:ticket_id, :author_id, :author_is_admin, :body)'
        );
        $stmt->execute([
            'ticket_id' => $data['ticket_id'],
            'author_id' => $data['author_id'],
            'author_is_admin' => !empty($data['author_is_admin']) ? 1 : 0,
            'body' => $data['body'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM support_ticket_messages WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Thread of a ticket, oldest first, with the author's name when still present. */
    public function findByTicketId(int $ticketId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.ticket_id, m.author_id, m.author_is_admin, m.body, m.created_at,
                    u.first_name AS author_first_name, u.last_name AS author_last_name
             FROM support_ticket_messages m
             LEFT JOIN users u ON u.id = m.author_id
             WHERE m.ticket_id = :ticket_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll();
    }
}
