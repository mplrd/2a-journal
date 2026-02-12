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
            'INSERT INTO partial_exits (trade_id, exited_at, exit_price, size, exit_type, target_id, pnl)
             VALUES (:trade_id, :exited_at, :exit_price, :size, :exit_type, :target_id, :pnl)'
        );
        $stmt->execute([
            'trade_id' => $data['trade_id'],
            'exited_at' => $data['exited_at'],
            'exit_price' => $data['exit_price'],
            'size' => $data['size'],
            'exit_type' => $data['exit_type'],
            'target_id' => $data['target_id'] ?? null,
            'pnl' => $data['pnl'],
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('SELECT * FROM partial_exits WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch();
    }

    public function findByTradeId(int $tradeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM partial_exits WHERE trade_id = :trade_id ORDER BY exited_at ASC'
        );
        $stmt->execute(['trade_id' => $tradeId]);

        return $stmt->fetchAll();
    }
}
