<?php

namespace App\Repositories;

use App\Enums\TriggerType;
use PDO;

class StatusHistoryRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO status_history (entity_type, entity_id, previous_status, new_status, user_id, trigger_type, details)
             VALUES (:entity_type, :entity_id, :previous_status, :new_status, :user_id, :trigger_type, :details)'
        );
        $stmt->execute([
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'],
            'previous_status' => $data['previous_status'] ?? null,
            'new_status' => $data['new_status'],
            'user_id' => $data['user_id'] ?? null,
            'trigger_type' => $data['trigger_type'] ?? TriggerType::MANUAL->value,
            'details' => $data['details'] ?? null,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, previous_status, new_status, user_id, changed_at, trigger_type, details
             FROM status_history WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, previous_status, new_status, user_id, changed_at, trigger_type, details
             FROM status_history WHERE entity_type = :entity_type AND entity_id = :entity_id
             ORDER BY changed_at DESC, id DESC'
        );
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);

        return $stmt->fetchAll();
    }
}
