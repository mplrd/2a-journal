<?php

namespace App\Services;

use App\Enums\Direction;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AccountRepository;
use App\Repositories\StatsRepository;

class StatsService
{
    private StatsRepository $statsRepo;
    private AccountRepository $accountRepo;

    public function __construct(StatsRepository $statsRepo, AccountRepository $accountRepo)
    {
        $this->statsRepo = $statsRepo;
        $this->accountRepo = $accountRepo;
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

    public function getStatsByPeriod(int $userId, string $group = 'month', array $filters = []): array
    {
        $validGroups = ['day', 'week', 'month', 'year'];
        if (!in_array($group, $validGroups, true)) {
            throw new ValidationException('stats.error.invalid_period_group', 'group');
        }
        $filters = $this->validateFilters($userId, $filters);
        return $this->statsRepo->getStatsByPeriod($userId, $group, $filters);
    }

    private function validateFilters(int $userId, array $filters): array
    {
        $validated = [];

        if (!empty($filters['account_id'])) {
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

        return $validated;
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
