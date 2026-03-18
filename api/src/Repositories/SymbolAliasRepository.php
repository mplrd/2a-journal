<?php

namespace App\Repositories;

use PDO;

class SymbolAliasRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function upsert(int $userId, string $brokerSymbol, string $journalSymbol, ?string $brokerTemplate = null): void
    {
        $sql = "INSERT INTO symbol_aliases (user_id, broker_symbol, journal_symbol, broker_template)
                VALUES (:user_id, :broker_symbol, :journal_symbol, :broker_template)
                ON DUPLICATE KEY UPDATE journal_symbol = VALUES(journal_symbol)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'broker_symbol' => $brokerSymbol,
            'journal_symbol' => $journalSymbol,
            'broker_template' => $brokerTemplate,
        ]);
    }

    public function findByBrokerSymbol(int $userId, string $brokerSymbol, ?string $brokerTemplate = null): ?array
    {
        if ($brokerTemplate !== null) {
            $sql = "SELECT * FROM symbol_aliases
                    WHERE user_id = :user_id AND broker_symbol = :broker_symbol AND broker_template = :broker_template";
            $params = ['user_id' => $userId, 'broker_symbol' => $brokerSymbol, 'broker_template' => $brokerTemplate];
        } else {
            $sql = "SELECT * FROM symbol_aliases
                    WHERE user_id = :user_id AND broker_symbol = :broker_symbol AND broker_template IS NULL";
            $params = ['user_id' => $userId, 'broker_symbol' => $brokerSymbol];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAllByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM symbol_aliases WHERE user_id = :user_id ORDER BY broker_symbol");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM symbol_aliases WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
}
