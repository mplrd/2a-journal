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

    /**
     * The mapping for a broker symbol whatever the template it was recorded
     * under. The import writes aliases stamped with the broker it read
     * (`broker_template`); a TradingView alert carries no such stamp, so a
     * template-scoped lookup would never find them and the mapping would be
     * useless outside the import — which is how it stayed until now.
     *
     * Ambiguity is answered with null rather than a guess: the same broker
     * symbol recorded against two different assets means we cannot tell which
     * one the signal is about, and picking one would silently trade under the
     * wrong instrument.
     */
    public function findAnyByBrokerSymbol(int $userId, string $brokerSymbol): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT journal_symbol FROM symbol_aliases
             WHERE user_id = :user_id AND broker_symbol = :broker_symbol"
        );
        $stmt->execute(['user_id' => $userId, 'broker_symbol' => $brokerSymbol]);
        $rows = $stmt->fetchAll();

        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * Repoints the user's aliases at an asset's new code. journal_symbol copies
     * symbols.code as a string rather than referencing symbols.id, so without
     * this a rename orphaned every mapping — which is also why SymbolResolver
     * has to guard against an alias pointing at an asset that no longer exists.
     */
    public function renameJournalSymbol(int $userId, string $oldCode, string $newCode): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE symbol_aliases SET journal_symbol = :new
             WHERE user_id = :user_id AND journal_symbol = :old"
        );
        $stmt->execute(['new' => $newCode, 'user_id' => $userId, 'old' => $oldCode]);

        return $stmt->rowCount();
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
