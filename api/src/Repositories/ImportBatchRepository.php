<?php

namespace App\Repositories;

use PDO;

class ImportBatchRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO import_batches
                (user_id, account_id, broker_template, original_filename, file_hash, total_rows, status)
                VALUES (:user_id, :account_id, :broker_template, :original_filename, :file_hash, :total_rows, :status)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'broker_template' => $data['broker_template'] ?? null,
            'original_filename' => $data['original_filename'],
            'file_hash' => $data['file_hash'],
            'total_rows' => $data['total_rows'] ?? 0,
            'status' => $data['status'] ?? 'PENDING',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM import_batches WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ib.*, a.name AS account_name
             FROM import_batches ib
             INNER JOIN accounts a ON a.id = ib.account_id
             WHERE ib.user_id = :user_id
             ORDER BY ib.created_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function update(int $id, array $data): void
    {
        $sets = [];
        $params = ['id' => $id];

        $allowed = ['status', 'imported_positions', 'imported_trades', 'skipped_duplicates', 'skipped_errors', 'error_log', 'completed_at'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($sets)) {
            return;
        }

        $sql = "UPDATE import_batches SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM import_batches WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
}
