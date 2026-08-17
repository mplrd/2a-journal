<?php

namespace App\Services;

use App\Repositories\PositionRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;
use PDO;
use Throwable;

/**
 * Renames an asset's code and carries along everything that copied it
 * (docs/evolutions.md).
 *
 * `symbols.code` is duplicated as a bare string, with no foreign key, into:
 *   positions.symbol                every trade and every order
 *   symbol_aliases.journal_symbol   the broker mappings pointing at the asset
 *
 * Nothing propagated, so renaming DE40.CASH to GER40 from "My assets" detached
 * the lot in silence: historical positions could no longer be priced — which
 * switched a plan's risk caps off — and statistics split in two for one market.
 *
 * Trading plans are NOT in that list, and deliberately: they reference the asset
 * by `symbol_id` with a foreign key (migration 042), so they follow a rename on
 * their own. That is the model the other two should have followed, and the one
 * they should move to.
 *
 * All of it, the code change included, happens inside one transaction. A cascade
 * that half-applied would be worse than none: some positions priced, others
 * orphaned, and nothing afterwards to tell them apart.
 */
class SymbolCodeRenamer
{
    public function __construct(
        private SymbolRepository $symbolRepo,
        private PositionRepository $positionRepo,
        private SymbolAliasRepository $aliasRepo,
        private PDO $pdo,
    ) {}

    /**
     * @param array $data the validated update payload, `code` included
     * @return array|null the updated asset row
     */
    public function rename(int $symbolId, int $userId, string $oldCode, array $data): ?array
    {
        $newCode = (string) $data['code'];

        $this->pdo->beginTransaction();
        try {
            $symbol = $this->symbolRepo->update($symbolId, $data);
            $this->positionRepo->renameSymbolCode($userId, $oldCode, $newCode);
            $this->aliasRepo->renameJournalSymbol($userId, $oldCode, $newCode);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $symbol;
    }
}
