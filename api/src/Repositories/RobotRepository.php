<?php

namespace App\Repositories;

use App\Enums\RobotStatus;
use PDO;

class RobotRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO robots (user_id, account_id, name, status)
             VALUES (:user_id, :account_id, :name, :status)"
        );
        $stmt->execute([
            'user_id' => $data['user_id'],
            'account_id' => $data['account_id'],
            'name' => $data['name'],
            'status' => $data['status'] ?? RobotStatus::ACTIVE->value,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM robots WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * All non-archived robots of a user, across every account, newest first.
     * The account name is joined for the cross-account "My robots" list.
     */
    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*, a.name AS account_name
             FROM robots r
             INNER JOIN accounts a ON a.id = r.account_id
             WHERE r.user_id = :user_id AND r.status <> :archived
             ORDER BY r.created_at DESC"
        );
        $stmt->execute(['user_id' => $userId, 'archived' => RobotStatus::ARCHIVED->value]);
        return $stmt->fetchAll();
    }

    public function countByAccountId(int $accountId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM robots WHERE account_id = :account_id AND status <> :archived"
        );
        $stmt->execute(['account_id' => $accountId, 'archived' => RobotStatus::ARCHIVED->value]);
        return (int) $stmt->fetchColumn();
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->pdo->prepare("UPDATE robots SET status = :status WHERE id = :id")
            ->execute(['status' => $status, 'id' => $id]);
    }

    public function updateName(int $id, string $name): void
    {
        $this->pdo->prepare("UPDATE robots SET name = :name WHERE id = :id")
            ->execute(['name' => $name, 'id' => $id]);
    }

    /** Bump trigger counters + last_triggered_at after a webhook fires. */
    public function recordTrigger(int $id, bool $success): void
    {
        $sql = $success
            ? "UPDATE robots SET total_triggered = total_triggered + 1, last_triggered_at = CURRENT_TIMESTAMP WHERE id = :id"
            : "UPDATE robots SET total_errors = total_errors + 1, last_triggered_at = CURRENT_TIMESTAMP WHERE id = :id";
        $this->pdo->prepare($sql)->execute(['id' => $id]);
    }
}
