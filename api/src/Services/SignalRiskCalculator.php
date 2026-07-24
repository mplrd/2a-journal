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
 * Returns null whenever the risk cannot be computed (unknown symbol, no point
 * value configured, missing/zero capital) — the caller then SKIPS the risk
 * filter rather than blocking the signal on a technical gap.
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
        $settings = $this->settingsRepo->findBySymbolAndAccount((int) $symbol['id'], $accountId);
        $pointValue = $settings !== null
            ? (float) $settings['point_value']
            : (float) ($symbol['point_value'] ?? 0);
        if ($pointValue <= 0) {
            return null;
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
