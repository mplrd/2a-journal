<?php

namespace App\Services;

use App\Repositories\SymbolAccountSettingsRepository;

/**
 * What one point of an instrument is worth, in the account's currency, for a
 * given (asset, account) pair (évolution #24).
 *
 * The journal stored `trades.pnl` as a raw price distance times a size — an
 * amount that is neither points nor money, and that the rest of the app has
 * always read as money: `accounts.current_capital` sums it, DrawdownService
 * compares it to a drawdown limit, plans divide a risk by the capital it
 * feeds. On any instrument that is not worth 1 the two disagreed silently.
 *
 * Same precedence as SignalRiskCalculator, which has always priced risk this
 * way: the per-account setting first, the asset's own default behind it. What
 * differs is the failure mode. Risk pricing may legitimately answer "unknown"
 * and switch a cap off; a P&L may not — a trade always has one. So every dead
 * end here resolves to 1, which reproduces exactly the arithmetic that came
 * before, rather than zeroing or refusing.
 *
 * The value is frozen on `positions.point_value` at creation and never read
 * back from the settings afterwards: changing what a point is worth must move
 * the trades that come next, never the capital and drawdown already on file.
 */
class PointValueResolver
{
    private const NEUTRAL = 1.0;

    public function __construct(
        private SymbolResolver $symbolResolver,
        private SymbolAccountSettingsRepository $settingsRepo,
    ) {}

    public function resolve(int $userId, string $symbol, int $accountId): float
    {
        if (trim($symbol) === '') {
            return self::NEUTRAL;
        }

        $asset = $this->symbolResolver->resolve($userId, $symbol);
        if ($asset === null) {
            return self::NEUTRAL;
        }

        $settings = $this->settingsRepo->findBySymbolAndAccount((int) $asset['id'], $accountId);
        $pointValue = $settings !== null
            ? (float) $settings['point_value']
            : (float) ($asset['point_value'] ?? 0);

        // Both columns are NOT NULL DEFAULT 1, so a non-positive value only
        // ever comes from a hand-edited row. Zero would flatten the trade's
        // P&L to nothing without a word.
        return $pointValue > 0 ? $pointValue : self::NEUTRAL;
    }
}
