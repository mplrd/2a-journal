<?php

namespace App\Services;

use App\Repositories\SymbolAliasRepository;
use App\Repositories\SymbolRepository;

/**
 * Turns whatever a signal calls an instrument into the user's own asset
 * (docs/99-plan-instrument-cible.md).
 *
 * Three distinct things, long conflated:
 *   ASSET   the thing traded — the DAX. A row in `symbols`.
 *   SYMBOL  what names it, and it changes from one broker to the next —
 *           GER40, DE40.CASH. `symbols.code`, `positions.symbol`.
 *   TICKER  broker + symbol — EIGHTCAP:GER40. What TradingView's {{ticker}}
 *           hands over, and what symbol_aliases reconstructs from
 *           broker_template + broker_symbol.
 *
 * Without this, a plan targeting the DAX turned away a signal that called it
 * GER40, and the risk of that signal could not be priced at all — which
 * silently switched both of the plan's risk caps off. symbol_aliases existed
 * for exactly this and was wired into the CSV import only.
 */
class SymbolResolver
{
    public function __construct(
        private SymbolRepository $symbolRepo,
        private SymbolAliasRepository $aliasRepo,
    ) {}

    /**
     * @return array|null the user's asset row, or null when nothing matches
     */
    public function resolve(int $userId, string $symbol): ?array
    {
        foreach ($this->candidates($symbol) as $candidate) {
            // The user's own code first: someone who registered the qualified
            // form (EIGHTCAP:GER40) meant it, so don't second-guess them.
            $asset = $this->symbolRepo->findByUserAndCode($userId, $candidate);
            if ($asset !== null) {
                return $asset;
            }

            $alias = $this->aliasRepo->findAnyByBrokerSymbol($userId, $candidate);
            if ($alias === null) {
                continue;
            }
            // An alias outlives the asset it pointed at. Handing back a code
            // whose row is gone would give the caller something nothing can be
            // priced against, which is worse than admitting we don't know.
            $asset = $this->symbolRepo->findByUserAndCode($userId, (string) $alias['journal_symbol']);
            if ($asset !== null) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * The string as received, then its symbol alone if it arrived as a ticker.
     * Order matters: the fully qualified form is tried first.
     *
     * @return array<int,string>
     */
    private function candidates(string $symbol): array
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return [];
        }

        $candidates = [$symbol];
        $colon = strrpos($symbol, ':');
        if ($colon !== false) {
            $bare = trim(substr($symbol, $colon + 1));
            if ($bare !== '' && $bare !== $symbol) {
                $candidates[] = $bare;
            }
        }
        return $candidates;
    }
}
