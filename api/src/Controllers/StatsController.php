<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\StatsService;

class StatsController extends Controller
{
    private StatsService $statsService;

    public function __construct(StatsService $statsService)
    {
        $this->statsService = $statsService;
    }

    public function dashboard(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);

        $result = $this->statsService->getDashboard($userId, $filters);

        return $this->jsonSuccess($result);
    }

    public function charts(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);

        $result = $this->statsService->getCharts($userId, $filters);

        return $this->jsonSuccess($result);
    }

    public function bySymbol(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getStatsBySymbol($userId, $filters));
    }

    public function byDirection(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getStatsByDirection($userId, $filters));
    }

    public function bySetup(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getStatsBySetup($userId, $filters));
    }

    public function byPeriod(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        $group = $request->getQuery('group') ?? 'month';
        return $this->jsonSuccess($this->statsService->getStatsByPeriod($userId, $group, $filters));
    }

    public function rrDistribution(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getRrDistribution($userId, $filters));
    }

    public function heatmap(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getHeatmap($userId, $filters));
    }

    public function openTrades(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getOpenTrades($userId, $filters));
    }

    public function dailyPnl(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getDailyPnl($userId, $filters));
    }

    public function bySession(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getStatsBySession($userId, $filters));
    }

    public function byAccount(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getStatsByAccount($userId, $filters));
    }

    public function byAccountType(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = $this->extractFilters($request);
        return $this->jsonSuccess($this->statsService->getStatsByAccountType($userId, $filters));
    }

    private function extractFilters(Request $request): array
    {
        $filters = [];

        $accountId = $request->getQuery('account_id');
        if ($accountId !== null && $accountId !== '') {
            $filters['account_id'] = $accountId;
        }

        $accountIds = $request->getQuery('account_ids');
        if (is_array($accountIds) && !empty($accountIds)) {
            $filters['account_ids'] = $accountIds;
        } elseif (is_string($accountIds) && $accountIds !== '') {
            // Frontend stats service serializes arrays as comma-joined for
            // backwards compat with how symbols/setups are sent.
            $filters['account_ids'] = explode(',', $accountIds);
        }

        $dateFrom = $request->getQuery('date_from');
        if ($dateFrom !== null && $dateFrom !== '') {
            $filters['date_from'] = $dateFrom;
        }

        $dateTo = $request->getQuery('date_to');
        if ($dateTo !== null && $dateTo !== '') {
            $filters['date_to'] = $dateTo;
        }

        $direction = $request->getQuery('direction');
        if ($direction !== null && $direction !== '') {
            $filters['direction'] = $direction;
        }

        $symbols = $request->getQuery('symbols');
        if ($symbols !== null && $symbols !== '') {
            $filters['symbols'] = explode(',', $symbols);
        }

        $setups = $request->getQuery('setups');
        if ($setups !== null && $setups !== '') {
            $filters['setups'] = explode(',', $setups);
        }

        return $filters;
    }
}
