<?php

namespace App\Services;

use App\Repositories\AccountRepository;
use App\Repositories\TradeRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Drawdown alert engine (E-08).
 *
 * Computes per-account "DD usage" (max + daily) and decides when to fire an
 * alert: when consumed DD ≥ (100 − user threshold)% of the configured DD.
 *
 * Default user threshold is 5% (alert at 95% used). Configurable per user
 * (1–10%) via `users.dd_alert_threshold_percent`.
 *
 * Daily reset uses the user's local timezone (`users.timezone`); deduplication
 * stamps `accounts.last_max_dd_alert_at` / `last_daily_dd_alert_at` so a user
 * receives at most one mail per type per local-day per account.
 *
 * Unrealized P&L is plumbed through `computeUnrealizedPnl` — currently a stub
 * returning 0 (no live broker quotes). When a quotes integration arrives, the
 * single method body changes; the rest of the pipeline already handles it.
 */
class DrawdownService
{
    public function __construct(
        private AccountRepository $accountRepo,
        private TradeRepository $tradeRepo,
        private UserRepository $userRepo,
        private EmailService $emailService
    ) {
    }

    /**
     * Returns DD-usage rows for every account of the user that has at least
     * one DD field configured (max_drawdown or daily_drawdown). Accounts
     * without DD config are skipped. Used by the dashboard banner.
     */
    public function getStatusForUser(int $userId): array
    {
        $user = $this->userRepo->findById($userId);
        if ($user === null) {
            return [];
        }

        $accountsResult = $this->accountRepo->findAllByUserId($userId, 1000, 0);
        $accounts = $accountsResult['items'] ?? [];

        $statuses = [];
        foreach ($accounts as $account) {
            $status = $this->computeForAccount($account, $user);
            if ($status !== null) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * Recompute DD usage for a single account and send alert email(s) if any
     * threshold is freshly crossed today (in user's local TZ). Idempotent for
     * the local day: subsequent calls are no-ops once the dedup column is set.
     *
     * Called from TradeService::close as a fire-and-forget side-effect — never
     * raises; logs exceptions so a downstream email failure doesn't break the
     * trade-close response.
     */
    public function checkAndNotifyForAccount(int $accountId, int $userId): void
    {
        try {
            $account = $this->accountRepo->findByIdForDdCheck($accountId);
            if ($account === null || (int) $account['user_id'] !== $userId) {
                return;
            }

            $user = $this->userRepo->findById($userId);
            if ($user === null) {
                return;
            }

            $status = $this->computeForAccount($account, $user);
            if ($status === null) {
                return;
            }

            $tz = $this->userTimezone($user);
            $todayStart = $this->todayStart($tz);
            $locale = $user['locale'] ?? 'en';
            $email = $user['email'];

            if ($status['alert_max'] && $this->shouldNotify($account['last_max_dd_alert_at'] ?? null, $todayStart)) {
                $this->emailService->sendDdAlertEmail($email, $locale, 'max', $status);
                $this->accountRepo->markDdAlertSent($accountId, 'max');
            }
            if ($status['alert_daily'] && $this->shouldNotify($account['last_daily_dd_alert_at'] ?? null, $todayStart)) {
                $this->emailService->sendDdAlertEmail($email, $locale, 'daily', $status);
                $this->accountRepo->markDdAlertSent($accountId, 'daily');
            }
        } catch (\Throwable $e) {
            error_log('[DrawdownService] checkAndNotifyForAccount failed: ' . $e->getMessage());
        }
    }

    /**
     * Pure-math compute, no I/O writes. Returns null when the account has no
     * DD config (max_drawdown AND daily_drawdown both null) — those accounts
     * are silently skipped from the status payload.
     */
    private function computeForAccount(array $account, array $user): ?array
    {
        $maxDd = $account['max_drawdown'] !== null ? (float) $account['max_drawdown'] : null;
        $dailyDd = $account['daily_drawdown'] !== null ? (float) $account['daily_drawdown'] : null;

        if ($maxDd === null && $dailyDd === null) {
            return null;
        }

        $threshold = (float) ($user['dd_alert_threshold_percent'] ?? 5.0);
        $tz = $this->userTimezone($user);

        $accountId = (int) $account['id'];
        $totalRealized = $this->tradeRepo->sumRealizedPnlForAccount($accountId);

        // "Today" boundary in user's local TZ, expressed as a UTC timestamp the
        // SQL filter will compare against trades.closed_at (stored as UTC).
        $todayStartLocal = $this->todayStart($tz);
        $todayStartUtc = $todayStartLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $todayRealized = $this->tradeRepo->sumRealizedPnlForAccountSince($accountId, $todayStartUtc);

        $unrealized = $this->computeUnrealizedPnl($accountId);

        // DD "used" = absolute value of negative P&L. Profitable account = 0 used.
        $maxUsedAmount = max(0.0, -($totalRealized + $unrealized));
        $dailyUsedAmount = max(0.0, -($todayRealized + $unrealized));

        $maxUsedPct = ($maxDd !== null && $maxDd > 0) ? min(100.0, $maxUsedAmount / $maxDd * 100.0) : null;
        $dailyUsedPct = ($dailyDd !== null && $dailyDd > 0) ? min(100.0, $dailyUsedAmount / $dailyDd * 100.0) : null;

        $alertCutoff = 100.0 - $threshold;
        $alertMax = $maxUsedPct !== null && $maxUsedPct >= $alertCutoff;
        $alertDaily = $dailyUsedPct !== null && $dailyUsedPct >= $alertCutoff;

        return [
            'account_id' => $accountId,
            'account_name' => $account['name'],
            'currency' => $account['currency'],
            'max_drawdown' => $maxDd,
            'daily_drawdown' => $dailyDd,
            'max_used_amount' => round($maxUsedAmount, 2),
            'max_used_percent' => $maxUsedPct !== null ? round($maxUsedPct, 2) : null,
            'daily_used_amount' => round($dailyUsedAmount, 2),
            'daily_used_percent' => $dailyUsedPct !== null ? round($dailyUsedPct, 2) : null,
            'alert_max' => $alertMax,
            'alert_daily' => $alertDaily,
            'threshold_percent' => $threshold,
        ];
    }

    /**
     * Unrealized P&L on open/secured trades for an account.
     *
     * Currently returns 0 — we don't have live broker quotes to compute
     * (current_price - entry_price) * remaining_size * direction_multiplier.
     * When a quotes integration lands (Ouinex/IG/etc., cf. E-02 / E-09), this
     * single method body becomes the aggregation. Everything downstream
     * (DD usage, alerts, dashboard) is already wired to consume it.
     */
    private function computeUnrealizedPnl(int $accountId): float
    {
        return 0.0;
    }

    private function userTimezone(array $user): DateTimeZone
    {
        $tzName = $user['timezone'] ?? 'UTC';
        try {
            return new DateTimeZone($tzName);
        } catch (\Exception $e) {
            return new DateTimeZone('UTC');
        }
    }

    private function todayStart(DateTimeZone $tz): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
    }

    /**
     * True when no alert was sent yet today (in user's local TZ).
     */
    private function shouldNotify(?string $lastSentAtUtc, DateTimeImmutable $todayStartLocal): bool
    {
        if ($lastSentAtUtc === null) {
            return true;
        }
        $last = new DateTimeImmutable($lastSentAtUtc, new DateTimeZone('UTC'));
        return $last < $todayStartLocal;
    }
}
