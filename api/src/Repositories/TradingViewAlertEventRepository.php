<?php

namespace App\Repositories;

use PDO;
use PDOException;

class TradingViewAlertEventRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Insert an event row. Throws PDOException with SQLSTATE 23000 if the
     * (webhook_id, external_alert_id) pair already exists — callers handle
     * that as the DUPLICATE path.
     */
    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO tradingview_alert_events
                (webhook_id, account_id, external_alert_id, payload_raw, status, reject_reason, error_message, created_order_id)
             VALUES
                (:webhook_id, :account_id, :external_alert_id, :payload_raw, :status, :reject_reason, :error_message, :created_order_id)"
        );
        $stmt->execute([
            'webhook_id' => $data['webhook_id'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'external_alert_id' => $data['external_alert_id'] ?? null,
            'payload_raw' => is_string($data['payload_raw']) ? $data['payload_raw'] : json_encode($data['payload_raw']),
            'status' => $data['status'],
            'reject_reason' => $data['reject_reason'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'created_order_id' => $data['created_order_id'] ?? null,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tradingview_alert_events WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAllByWebhookId(int $webhookId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM tradingview_alert_events
             WHERE webhook_id = :webhook_id
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue('webhook_id', $webhookId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countByWebhookId(int $webhookId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM tradingview_alert_events WHERE webhook_id = :webhook_id"
        );
        $stmt->execute(['webhook_id' => $webhookId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * True when (webhook_id, external_alert_id) already exists. Returns false
     * if external_alert_id is null (we don't dedup against missing alert IDs —
     * if the user omits it, every alert counts as new).
     */
    public function existsByWebhookAndAlertId(int $webhookId, ?string $externalAlertId): bool
    {
        if ($externalAlertId === null || $externalAlertId === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM tradingview_alert_events
             WHERE webhook_id = :webhook_id AND external_alert_id = :alert_id
             LIMIT 1"
        );
        $stmt->execute(['webhook_id' => $webhookId, 'alert_id' => $externalAlertId]);
        return (bool) $stmt->fetchColumn();
    }
}
