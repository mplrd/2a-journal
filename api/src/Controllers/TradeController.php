<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\TradeService;

class TradeController extends Controller
{
    private TradeService $tradeService;

    public function __construct(TradeService $tradeService)
    {
        $this->tradeService = $tradeService;
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = [];

        foreach (['account_id', 'status', 'symbol', 'direction', 'page', 'per_page'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $result = $this->tradeService->list($userId, $filters);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $trade = $this->tradeService->create($userId, $request->getBody());

        return $this->jsonSuccess($trade, null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $tradeId = (int) $request->getRouteParam('id');
        $trade = $this->tradeService->get($userId, $tradeId);

        return $this->jsonSuccess($trade);
    }

    public function close(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $tradeId = (int) $request->getRouteParam('id');
        $trade = $this->tradeService->close($userId, $tradeId, $request->getBody());

        return $this->jsonSuccess($trade);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $tradeId = (int) $request->getRouteParam('id');
        $this->tradeService->delete($userId, $tradeId);

        return $this->jsonSuccess(['message_key' => 'trades.success.deleted']);
    }
}
