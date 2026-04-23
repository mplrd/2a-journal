<?php

namespace App\Repositories;

use PDO;

class SymbolAccountSettingsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findBySymbolAndAccount(int $symbolId, int $accountId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, symbol_id, account_id, point_value, created_at, updated_at
             FROM symbol_account_settings
             WHERE symbol_id = :sid AND account_id = :aid'
        );
        $stmt->execute(['sid' => $symbolId, 'aid' => $accountId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sas.symbol_id, sas.account_id, sas.point_value
             FROM symbol_account_settings sas
             INNER JOIN symbols s ON s.id = sas.symbol_id
             INNER JOIN accounts a ON a.id = sas.account_id
             WHERE s.user_id = :uid_sym AND a.user_id = :uid_acc
               AND s.deleted_at IS NULL AND a.deleted_at IS NULL
             ORDER BY sas.symbol_id, sas.account_id'
        );
        $stmt->execute(['uid_sym' => $userId, 'uid_acc' => $userId]);
        return $stmt->fetchAll();
    }

    public function upsert(int $symbolId, int $accountId, float $pointValue): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO symbol_account_settings (symbol_id, account_id, point_value)
             VALUES (:sid, :aid, :pv)
             ON DUPLICATE KEY UPDATE point_value = VALUES(point_value)'
        );
        $stmt->execute(['sid' => $symbolId, 'aid' => $accountId, 'pv' => $pointValue]);
    }

    public function delete(int $symbolId, int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM symbol_account_settings
             WHERE symbol_id = :sid AND account_id = :aid'
        );
        $stmt->execute(['sid' => $symbolId, 'aid' => $accountId]);
    }

    /**
     * For every (symbol, account) pair owned by the user that doesn't yet have a
     * settings row, create one with point_value inherited from symbols.point_value.
     * Idempotent: safe to call on every GET.
     *
     * @return int number of rows created
     */
    public function autoMaterializeForUser(int $userId): int
    {
        $sql = <<<SQL
            INSERT INTO symbol_account_settings (symbol_id, account_id, point_value)
            SELECT s.id, a.id, s.point_value
            FROM symbols s
            INNER JOIN accounts a ON a.user_id = :uid_acc AND a.deleted_at IS NULL
            WHERE s.user_id = :uid_sym
              AND s.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM symbol_account_settings sas
                  WHERE sas.symbol_id = s.id AND sas.account_id = a.id
              )
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid_acc' => $userId,
            'uid_sym' => $userId,
        ]);
        return $stmt->rowCount();
    }
}
