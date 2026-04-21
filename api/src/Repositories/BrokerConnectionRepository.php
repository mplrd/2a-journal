<?php

namespace App\Repositories;

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
}
