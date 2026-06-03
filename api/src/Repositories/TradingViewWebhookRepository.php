<?php

namespace App\Repositories;

use PDO;

/**
 * The TradingView webhook is now a pure auth channel owned by a robot
 * (docs/70-robots.md): it carries the URL token + body secret hashes and the
 * owning robot_id. Status, counters and the target account live on the robot.
 */
class TradingViewWebhookRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO tradingview_webhooks (user_id, robot_id, name, url_token_hash, body_secret_hash)
             VALUES (:user_id, :robot_id, :name, :url_token_hash, :body_secret_hash)"
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'robot_id' => $data['robot_id'],
            'name' => $data['name'],
            'url_token_hash' => $data['url_token_hash'],
            'body_secret_hash' => $data['body_secret_hash'],
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

    public function findByRobotId(int $robotId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM tradingview_webhooks WHERE robot_id = :robot_id LIMIT 1");
        $stmt->execute(['robot_id' => $robotId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
