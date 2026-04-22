<?php

namespace App\Repositories;

use PDO;

class WebhookEventRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function existsByStripeId(string $eventId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM stripe_webhook_events WHERE stripe_event_id = :eid');
        $stmt->execute(['eid' => $eventId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function markProcessed(string $eventId, string $eventType): void
    {
        // INSERT IGNORE keeps the call idempotent even under race conditions.
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO stripe_webhook_events (stripe_event_id, event_type) VALUES (:eid, :etype)'
        );
        $stmt->execute(['eid' => $eventId, 'etype' => $eventType]);
    }
}
