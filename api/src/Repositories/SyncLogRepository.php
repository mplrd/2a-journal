<?php

namespace App\Repositories;

use PDO;

class SyncLogRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sync_logs (broker_connection_id, user_id, status, deals_fetched, deals_imported, deals_skipped, error_message, import_batch_id)
             VALUES (:broker_connection_id, :user_id, :status, :deals_fetched, :deals_imported, :deals_skipped, :error_message, :import_batch_id)"
        );
        $stmt->execute([
            'broker_connection_id' => $data['broker_connection_id'],
            'user_id' => $data['user_id'],
            'status' => $data['status'],
            'deals_fetched' => $data['deals_fetched'] ?? 0,
            'deals_imported' => $data['deals_imported'] ?? 0,
            'deals_skipped' => $data['deals_skipped'] ?? 0,
            'error_message' => $data['error_message'] ?? null,
            'import_batch_id' => $data['import_batch_id'] ?? null,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sync_logs WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByConnectionId(int $connectionId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sync_logs WHERE broker_connection_id = :connection_id ORDER BY started_at DESC LIMIT :limit"
        );
        $stmt->bindValue('connection_id', $connectionId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['status', 'deals_fetched', 'deals_imported', 'deals_skipped', 'error_message', 'import_batch_id', 'completed_at'];
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

        $sql = "UPDATE sync_logs SET " . implode(', ', $sets) . " WHERE id = :id";
        $this->pdo->prepare($sql)->execute($params);
    }
}
