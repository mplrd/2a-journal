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

    private function extractFilters(Request $request): array
    {
        $filters = [];
        $accountId = $request->getQuery('account_id');
        if ($accountId !== null && $accountId !== '') {
            $filters['account_id'] = $accountId;
        }
        return $filters;
    }
}
