<?php

namespace App\Repositories;

use PDO;

class RateLimitRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function increment(string $ip, string $endpoint, int $windowSeconds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (ip, endpoint, attempts, window_start)
             VALUES (:ip, :endpoint, 1, NOW())
             ON DUPLICATE KEY UPDATE
                attempts = IF(window_start > DATE_SUB(NOW(), INTERVAL :window SECOND), attempts + 1, 1),
                window_start = IF(window_start > DATE_SUB(NOW(), INTERVAL :window2 SECOND), window_start, NOW())'
        );
        $stmt->execute([
            'ip' => $ip,
            'endpoint' => $endpoint,
            'window' => $windowSeconds,
            'window2' => $windowSeconds,
        ]);
    }

    public function getAttempts(string $ip, string $endpoint, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempts FROM rate_limits
             WHERE ip = :ip AND endpoint = :endpoint
             AND window_start > DATE_SUB(NOW(), INTERVAL :window SECOND)'
        );
        $stmt->execute([
            'ip' => $ip,
            'endpoint' => $endpoint,
            'window' => $windowSeconds,
        ]);

        $result = $stmt->fetchColumn();
        return $result !== false ? (int)$result : 0;
    }

    public function cleanup(int $maxWindowSeconds): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rate_limits WHERE window_start <= DATE_SUB(NOW(), INTERVAL :window SECOND)'
        );
        $stmt->execute(['window' => $maxWindowSeconds]);
    }
}
