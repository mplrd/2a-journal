<?php

namespace App\Repositories;

use PDO;

class PartialExitRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(array $data): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO partial_exits (trade_id, exited_at, exit_price, size, exit_type, target_id, pnl, external_id)
             VALUES (:trade_id, :exited_at, :exit_price, :size, :exit_type, :target_id, :pnl, :external_id)'
        );
        $stmt->execute([
            'trade_id' => $data['trade_id'],
            'exited_at' => $data['exited_at'],
            'exit_price' => $data['exit_price'],
            'size' => $data['size'],
            'exit_type' => $data['exit_type'],
            'target_id' => $data['target_id'] ?? null,
            'pnl' => $data['pnl'],
            'external_id' => $data['external_id'] ?? null,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'SELECT id, trade_id, exited_at, exit_price, size, exit_type, target_id, pnl, external_id, created_at
             FROM partial_exits WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function findByTradeId(int $tradeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, trade_id, exited_at, exit_price, size, exit_type, target_id, pnl, external_id, created_at
             FROM partial_exits WHERE trade_id = :trade_id ORDER BY exited_at ASC'
        );
        $stmt->execute(['trade_id' => $tradeId]);

        return $stmt->fetchAll();
    }

    /**
     * Set of `external_id` values already recorded for this trade. The
     * broker sync uses this to dedup across runs — re-syncing the same
     * open position would otherwise re-insert every partial fill on
     * each tick. external_id null on user-created exits never matches
     * a broker-formatted `bingx_fill_<orderId>` key, so manual exits
     * and broker exits coexist safely.
     *
     * @return array<string, bool> Map keyed by external_id for O(1) lookup
     */
    public function existingExternalIdsForTrade(int $tradeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT external_id FROM partial_exits
             WHERE trade_id = :trade_id AND external_id IS NOT NULL'
        );
        $stmt->execute(['trade_id' => $tradeId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $externalId) {
            $map[(string) $externalId] = true;
        }
        return $map;
    }

    public function updatePnl(int $id, float $pnl): void
    {
        $stmt = $this->pdo->prepare('UPDATE partial_exits SET pnl = :pnl WHERE id = :id');
        $stmt->execute(['id' => $id, 'pnl' => round($pnl, 2)]);
    }
}
