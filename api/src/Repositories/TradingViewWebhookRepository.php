<?php

namespace App\Repositories;

use App\Enums\WebhookStatus;
use PDO;

class TradingViewWebhookRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO tradingview_webhooks (user_id, account_id, name, url_token_hash, body_secret_hash, status)
             VALUES (:user_id, :account_id, :name, :url_token_hash, :body_secret_hash, :status)"
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'name' => $data['name'],
            'url_token_hash' => $data['url_token_hash'],
            'body_secret_hash' => $data['body_secret_hash'],
            'status' => $data['status'] ?? WebhookStatus::ACTIVE->value,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tradingview_webhooks WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tradingview_webhooks WHERE url_token_hash = :hash");
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAllByAccountId(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, user_id, account_id, name, status, last_triggered_at, total_triggered, total_errors, created_at, updated_at
             FROM tradingview_webhooks
             WHERE account_id = :account_id
             ORDER BY created_at DESC"
        );
        $stmt->execute(['account_id' => $accountId]);
        return $stmt->fetchAll();
    }

    public function revoke(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE tradingview_webhooks SET status = :status WHERE id = :id"
        )->execute(['status' => WebhookStatus::REVOKED->value, 'id' => $id]);
    }

    public function recordTrigger(int $id, bool $success): void
    {
        $sql = $success
            ? "UPDATE tradingview_webhooks SET total_triggered = total_triggered + 1, last_triggered_at = CURRENT_TIMESTAMP WHERE id = :id"
            : "UPDATE tradingview_webhooks SET total_errors = total_errors + 1, last_triggered_at = CURRENT_TIMESTAMP WHERE id = :id";
        $this->pdo->prepare($sql)->execute(['id' => $id]);
    }
}
