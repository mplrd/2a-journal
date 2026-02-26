<?php

namespace App\Services;

use App\Exceptions\ForbiddenException;
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

        return $validated;
    }
}
