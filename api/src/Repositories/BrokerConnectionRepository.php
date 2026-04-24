<?php

namespace App\Repositories;

use App\Enums\ConnectionStatus;
use PDO;

class BrokerConnectionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO broker_connections (user_id, account_id, provider, status, credentials_encrypted, credentials_iv)
             VALUES (:user_id, :account_id, :provider, :status, :credentials_encrypted, :credentials_iv)"
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'provider' => $data['provider'],
            'status' => $data['status'],
            'credentials_encrypted' => $data['credentials_encrypted'],
            'credentials_iv' => $data['credentials_iv'],
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM broker_connections WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByAccountId(int $accountId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM broker_connections WHERE account_id = :account_id");
        $stmt->execute(['account_id' => $accountId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM broker_connections WHERE user_id = :user_id ORDER BY created_at DESC");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['status', 'credentials_encrypted', 'credentials_iv', 'last_sync_at', 'last_sync_status', 'last_sync_error', 'sync_cursor'];
        $sets = [];
        $params = ['id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed)) {
                $sets[] = "$key = :$key";
                $params[$key] = $value;
            }
        }

        if (empty($sets)) {
            return;
        }

        $sql = "UPDATE broker_connections SET " . implode(', ', $sets) . " WHERE id = :id";
        $this->pdo->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM broker_connections WHERE id = :id")->execute(['id' => $id]);
    }

    /**
     * Return ACTIVE connections that are due for an auto-sync pass.
     *
     * A connection is due when it was never synced OR its last sync is older
     * than the given interval. The interval is clamped to [1, 1440] minutes
     * and injected as a safe integer literal because MariaDB does not support
     * bound parameters inside INTERVAL expressions.
     */
    public function findDueForAutoSync(int $intervalMinutes): array
    {
        if ($intervalMinutes < 1) {
            $intervalMinutes = 1;
        } elseif ($intervalMinutes > 1440) {
            $intervalMinutes = 1440;
        }

        // UTC_TIMESTAMP() sidesteps the MySQL session-timezone setting so the
        // comparison stays consistent with PHP's UTC-written last_sync_at.
        $sql = "SELECT * FROM broker_connections
                WHERE status = :status
                  AND (last_sync_at IS NULL OR last_sync_at < UTC_TIMESTAMP() - INTERVAL {$intervalMinutes} MINUTE)
                ORDER BY last_sync_at IS NULL DESC, last_sync_at ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['status' => ConnectionStatus::ACTIVE->value]);
        return $stmt->fetchAll();
    }

    public function incrementFailures(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections SET consecutive_failures = consecutive_failures + 1 WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public function resetFailures(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections SET consecutive_failures = 0 WHERE id = :id"
        )->execute(['id' => $id]);
    }

    public function countActive(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM broker_connections WHERE status = :status");
        $stmt->execute(['status' => ConnectionStatus::ACTIVE->value]);
        return (int) $stmt->fetchColumn();
    }

    public function markError(int $id, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE broker_connections
             SET status = :status, last_sync_error = :err
             WHERE id = :id"
        )->execute([
            'status' => ConnectionStatus::ERROR->value,
            'err' => $error,
            'id' => $id,
        ]);
    }
}
