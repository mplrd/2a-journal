<?php

namespace App\Services;

use App\Repositories\AccountRepository;
use App\Repositories\SymbolAccountSettingsRepository;
use App\Repositories\SymbolRepository;

/**
 * Computes the monetary risk of an incoming signal as a percentage of the
 * account capital, for the trading-plan "max risk per trade" filter
 * (docs/83-trading-plans.md).
 *
 *   risk_money = size × sl_points × point_value(symbol, account)
 *   risk_%     = risk_money ÷ current_capital × 100
 *
 * Returns null whenever the risk cannot be computed — the caller then SKIPS the
 * risk filter rather than blocking the signal on a technical gap.
 *
 * "No point value configured" is NOT one of those cases, whatever this docblock
 * used to say: both symbols.point_value and symbol_account_settings.point_value
 * are NOT NULL DEFAULT 1, SymbolService refuses <= 0 on both write paths, and
 * every new user is seeded six assets with real values. The guard below is
 * defence against a hand-edited row, not a case to plan around. Saying otherwise
 * sent a reader looking for a hole that does not exist, and the claim reached
 * two docs and a user-facing tooltip before anyone checked the schema.
 *
 * What genuinely returns null: no stop (size or sl_points <= 0), a symbol
 * outside the user's assets (the trade form is a picker over them, so this needs
 * the API), a deleted account, or a capital <= 0 — a blown account, where a
 * percentage of nothing means nothing.
 */
class SignalRiskCalculator
{
    public function __construct(
        private SymbolRepository $symbolRepo,
        private SymbolAccountSettingsRepository $settingsRepo,
        private AccountRepository $accountRepo,
    ) {}

    public function computePercent(
        int $userId,
        int $accountId,
        string $symbolCode,
        float $size,
        float $slPoints,
    ): ?float {
        if ($size <= 0 || $slPoints <= 0) {
            return null;
        }

        $symbol = $this->symbolRepo->findByUserAndCode($userId, $symbolCode);
        if ($symbol === null) {
            return null;
        }

        // Per-account point value overrides the symbol default when present.
        // Both columns are NOT NULL DEFAULT 1, so this resolves to 1 for a user
        // who never touched the setting — never to "unknown".
        $settings = $this->settingsRepo->findBySymbolAndAccount((int) $symbol['id'], $accountId);
        $pointValue = $settings !== null
            ? (float) $settings['point_value']
            : (float) ($symbol['point_value'] ?? 0);
        if ($pointValue <= 0) {
            return null; // unreachable through the API; guards a hand-edited row
        }

        $account = $this->accountRepo->findById($accountId);
        if ($account === null) {
            return null;
        }
        $capital = (float) ($account['current_capital'] ?? 0);
        if ($capital <= 0) {
            return null;
        }

        return ($size * $slPoints * $pointValue) / $capital * 100.0;
    }
}
