<?php

namespace App\Services;

use App\Repositories\AccountRepository;
use App\Repositories\SymbolAccountSettingsRepository;

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
 * What genuinely returns null: no stop (size or sl_points <= 0), a symbol that
 * resolves to no asset of the user's even through the aliases, a deleted
 * account, an account that is not the caller's, or a capital <= 0 — a blown
 * account, where a percentage of nothing means nothing.
 */
class SignalRiskCalculator
{
    public function __construct(
        private SymbolResolver $symbolResolver,
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

        // Resolved, not looked up verbatim: a signal or a synced position names
        // the instrument the way its broker does, and the user's own code is
        // often another string for the same asset. Matching on the raw string
        // made the risk unpriceable, which switched the plan's risk caps off
        // without a word (docs/99).
        $symbol = $this->symbolResolver->resolve($userId, $symbolCode);
        if ($symbol === null) {
            return null;
        }

        // The account is resolved FIRST, and checked to be the caller's. Every
        // number this method returns is a function of that account's capital,
        // and the plan's rejection reason quotes the percentage to three
        // decimals with a caller-controlled size and stop — so pricing an
        // account belonging to someone else hands their capital straight back.
        // Callers are meant to have checked: TradeService::create does, and
        // TradingPlanService::evaluateDraft did not (docs/102).
        $account = $this->accountRepo->findById($accountId);
        if ($account === null || (int) ($account['user_id'] ?? 0) !== $userId) {
            return null;
        }
        $capital = (float) ($account['current_capital'] ?? 0);
        if ($capital <= 0) {
            return null;
        }

        // Per-account point value overrides the symbol default when present.
        // Both columns are NOT NULL DEFAULT 1, so this resolves to 1 for a user
        // who never touched the setting — never to "unknown". Read only once the
        // account is known to be the caller's: it is a setting OF that account.
        $settings = $this->settingsRepo->findBySymbolAndAccount((int) $symbol['id'], $accountId);
        $pointValue = $settings !== null
            ? (float) $settings['point_value']
            : (float) ($symbol['point_value'] ?? 0);
        if ($pointValue <= 0) {
            return null; // unreachable through the API; guards a hand-edited row
        }

        return ($size * $slPoints * $pointValue) / $capital * 100.0;
    }
}
