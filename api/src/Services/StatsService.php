<?php

namespace App\Services;

use App\Enums\Direction;
use App\Enums\SetupCombinationMatchMode;
use App\Enums\TradingSession;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\SetupRepository;
use App\Repositories\StatsRepository;
use App\Repositories\UserRepository;

class StatsService
{
    private const MAX_COMBINATION_SETUPS = 20;
    private const MAX_COMBINATIONS_PER_REQUEST = 10;

    private StatsRepository $statsRepo;
    private AccountRepository $accountRepo;
    private UserRepository $userRepo;
    private SetupRepository $setupRepo;

    public function __construct(
        StatsRepository $statsRepo,
        AccountRepository $accountRepo,
        UserRepository $userRepo,
        SetupRepository $setupRepo
    ) {
        $this->statsRepo = $statsRepo;
        $this->accountRepo = $accountRepo;
        $this->userRepo = $userRepo;
        $this->setupRepo = $setupRepo;
    }

    private function getUserTimezoneOffset(int $userId): string
    {
        $user = $this->userRepo->findById($userId);
        $tzName = $user['timezone'] ?? 'UTC';
        $tz = new \DateTimeZone($tzName);
        $offset = $tz->getOffset(new \DateTime('now', $tz));
        $sign = $offset >= 0 ? '+' : '-';
        $hours = str_pad((string) intdiv(abs($offset), 3600), 2, '0', STR_PAD_LEFT);
        $minutes = str_pad((string) ((abs($offset) % 3600) / 60), 2, '0', STR_PAD_LEFT);
        return "{$sign}{$hours}:{$minutes}";
    }

    public function getDashboard(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);

        $overview = $this->statsRepo->getOverview($userId, $filters);
        $recentTrades = $this->statsRepo->getRecentTrades($userId, 5, $filters);

        return [
            'overview' => $overview,
            'recent_trades' => $recentTrades,
        ];
    }

    public function getCharts(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);

        return [
            'cumulative_pnl' => $this->statsRepo->getCumulativePnl($userId, $filters),
            'win_loss' => $this->statsRepo->getWinLossDistribution($userId, $filters),
            'pnl_by_symbol' => $this->statsRepo->getPnlBySymbol($userId, $filters),
        ];
    }

    public function getStatsBySymbol(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getStatsBySymbol($userId, $filters);
    }

    public function getStatsByDirection(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getStatsByDirection($userId, $filters);
    }

    public function getStatsBySetup(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getStatsBySetup($userId, $filters);
    }

    /**
     * Ad-hoc analysis of N user-defined setup combinations side by side:
     * for each combination, aggregate metrics on trades carrying every
     * requested setup. A single shared baseline (same global filters, no
     * combination constraint) is also returned so the UI can render N+1 bars
     * per metric and let the user spot which combination dominates.
     *
     * Each entry in $combinations is `['setup_ids' => int[]]`. The structure
     * is kept as an associative array (rather than a bare array of int[]) to
     * leave room for per-combination options later (e.g. an explicit `name`
     * field when we ship saved combinations) without breaking the contract.
     */
    public function getSetupCombinationsAnalysis(
        int $userId,
        array $combinations,
        string $matchMode,
        array $filters = []
    ): array {
        // An empty list is legal: the modal always shows the baseline first
        // and only adds combination bars as the user composes them. We just
        // skip the per-combination resolution and go straight to the baseline.
        if (count($combinations) > self::MAX_COMBINATIONS_PER_REQUEST) {
            throw new ValidationException('stats.error.too_many_combinations', 'combinations');
        }

        $mode = SetupCombinationMatchMode::tryFrom($matchMode);
        if ($mode === null) {
            throw new ValidationException('stats.error.invalid_match_mode', 'match');
        }

        // Resolve each combination → cleaned int IDs + setup labels (with
        // ownership check). Done up-front so we don't run any SQL if a single
        // combination is invalid.
        $resolved = [];
        foreach ($combinations as $combo) {
            $rawIds = is_array($combo) && isset($combo['setup_ids']) && is_array($combo['setup_ids'])
                ? $combo['setup_ids']
                : [];
            $cleanIds = array_values(array_unique(
                array_filter(array_map('intval', $rawIds), fn($id) => $id > 0)
            ));
            if (empty($cleanIds)) {
                throw new ValidationException('stats.error.setup_combination_required', 'setup_ids');
            }
            if (count($cleanIds) > self::MAX_COMBINATION_SETUPS) {
                throw new ValidationException('stats.error.setup_combination_too_large', 'setup_ids');
            }

            $setupNames = [];
            foreach ($cleanIds as $id) {
                $setup = $this->setupRepo->findById($id);
                if (!$setup || (int) $setup['user_id'] !== $userId) {
                    throw new ForbiddenException('setups.error.forbidden');
                }
                $setupNames[] = $setup['label'];
            }

            $resolved[] = ['setup_ids' => $cleanIds, 'setups' => $setupNames];
        }

        $validatedFilters = $this->validateFilters($userId, $filters);

        // One repo call per combination + one for the shared baseline.
        $combinationsResult = [];
        foreach ($resolved as $entry) {
            $combinationsResult[] = [
                'setup_ids' => $entry['setup_ids'],
                'setups' => $entry['setups'],
                'stats' => $this->statsRepo->getStatsForSetupCombination(
                    $userId,
                    $entry['setups'],
                    $validatedFilters
                ),
            ];
        }
        $baseline = $this->statsRepo->getStatsForSetupCombination($userId, [], $validatedFilters);

        return [
            'baseline' => $baseline,
            'combinations' => $combinationsResult,
            'match' => $mode->value,
        ];
    }

    public function getStatsByPeriod(int $userId, string $group = 'month', array $filters = []): array
    {
        // Linear modes return one bucket per calendar period (date axis).
        // Cyclic modes collapse the year and expose seasonality (axis = day
        // of week / iso week / month, regardless of which year the trade
        // belongs to).
        $validGroups = ['day', 'week', 'month', 'year', 'day_of_week', 'iso_week', 'month_of_year'];
        if (!in_array($group, $validGroups, true)) {
            throw new ValidationException('stats.error.invalid_period_group', 'group');
        }
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getStatsByPeriod($userId, $group, $filters);
    }

    public function getRrDistribution(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getRrDistribution($userId, $filters);
    }

    public function getHeatmap(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        $tz = $this->getUserTimezoneOffset($userId);
        return $this->statsRepo->getHeatmap($userId, $filters, $tz);
    }

    public function getStatsBySession(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        $trades = $this->statsRepo->getTradesForSessionStats($userId, $filters);

        $groups = [];
        foreach ($trades as $trade) {
            $dt = new \DateTime($trade['closed_at'], new \DateTimeZone('UTC'));
            $session = TradingSession::classify($dt)->value;
            $groups[$session][] = $trade;
        }

        $result = [];
        $order = ['ASIA', 'EUROPE', 'EUROPE_US', 'US', 'OFF'];
        foreach ($order as $session) {
            if (empty($groups[$session])) {
                continue;
            }
            $result[] = $this->aggregateSessionStats($session, $groups[$session]);
        }

        return $result;
    }

    private function aggregateSessionStats(string $session, array $trades): array
    {
        $total = count($trades);
        $wins = 0;
        $losses = 0;
        $totalPnl = 0.0;
        $sumRr = 0.0;
        $rrCount = 0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;

        foreach ($trades as $t) {
            $pnl = (float) $t['pnl'];
            $totalPnl += $pnl;
            if ($t['risk_reward'] !== null) {
                $sumRr += (float) $t['risk_reward'];
                $rrCount++;
            }

            if ($pnl > 0) {
                $wins++;
                $grossProfit += $pnl;
            } elseif ($pnl < 0) {
                $losses++;
                $grossLoss += abs($pnl);
            }
        }

        return [
            'session' => $session,
            'total_trades' => $total,
            'wins' => $wins,
            'losses' => $losses,
            'win_rate' => $total > 0 ? round($wins * 100.0 / $total, 2) : 0,
            'total_pnl' => round($totalPnl, 2),
            'avg_rr' => $rrCount > 0 ? round($sumRr / $rrCount, 2) : null,
            'profit_factor' => $grossLoss > 0 ? round($grossProfit / $grossLoss, 2) : null,
        ];
    }

    public function getStatsByAccount(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getStatsByAccount($userId, $filters);
    }

    public function getStatsByAccountType(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getStatsByAccountType($userId, $filters);
    }

    public function getOpenTrades(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getOpenTrades($userId, 5, $filters);
    }

    public function getDailyPnl(int $userId, array $filters = []): array
    {
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getDailyPnl($userId, $filters);
    }

    private function validateFilters(int $userId, array $filters): array
    {
        $validated = [];

        if (!empty($filters['account_ids']) && is_array($filters['account_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['account_ids']), fn($id) => $id > 0));
            $checked = [];
            foreach ($ids as $id) {
                $account = $this->accountRepo->findById($id);
                if (!$account || (int) $account['user_id'] !== $userId) {
                    throw new ForbiddenException('accounts.error.forbidden');
                }
                $checked[] = $id;
            }
            if (!empty($checked)) {
                $validated['account_ids'] = array_values(array_unique($checked));
            }
        } elseif (!empty($filters['account_id'])) {
            $accountId = (int) $filters['account_id'];
            $account = $this->accountRepo->findById($accountId);

            if (!$account || (int) $account['user_id'] !== $userId) {
                throw new ForbiddenException('accounts.error.forbidden');
            }

            $validated['account_id'] = $accountId;
        }

        if (!empty($filters['date_from'])) {
            if (!$this->isValidDate($filters['date_from'])) {
                throw new ValidationException('stats.error.invalid_date', 'date_from');
            }
            $validated['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            if (!$this->isValidDate($filters['date_to'])) {
                throw new ValidationException('stats.error.invalid_date', 'date_to');
            }
            $validated['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['direction'])) {
            if (Direction::tryFrom($filters['direction']) === null) {
                throw new ValidationException('stats.error.invalid_direction', 'direction');
            }
            $validated['direction'] = $filters['direction'];
        }

        if (!empty($filters['symbols'])) {
            $validated['symbols'] = array_values(array_filter($filters['symbols'], 'is_string'));
        }

        if (!empty($filters['setups'])) {
            $validated['setups'] = array_values(array_filter($filters['setups'], 'is_string'));
        }

        $validated['be_threshold_percent'] = $this->getUserBeThreshold($userId);

        return $validated;
    }

    private function getUserBeThreshold(int $userId): float
    {
        $user = $this->userRepo->findById($userId);
        return isset($user['be_threshold_percent']) ? (float) $user['be_threshold_percent'] : 0.0;
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
