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

        foreach (['account_id', 'status', 'symbol', 'direction', 'page', 'per_page', 'date_from', 'date_to'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        // statuses (multi-select) arrives as an array when the client sends
        // ?statuses[]=OPEN&statuses[]=SECURED. Accept it separately so the
        // empty-string check above does not reject an empty array silently.
        $statuses = $request->getQuery('statuses');
        if (is_array($statuses) && !empty($statuses)) {
            $filters['statuses'] = $statuses;
        }

        // account_ids (multi-select) — same pattern as statuses.
        $accountIds = $request->getQuery('account_ids');
        if (is_array($accountIds) && !empty($accountIds)) {
            $filters['account_ids'] = $accountIds;
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

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $tradeId = (int) $request->getRouteParam('id');
        $trade = $this->tradeService->update($userId, $tradeId, $request->getBody());

        return $this->jsonSuccess($trade);
    }

    public function close(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $tradeId = (int) $request->getRouteParam('id');
        $trade = $this->tradeService->close($userId, $tradeId, $request->getBody());

        return $this->jsonSuccess($trade);
    }

    public function beHit(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $tradeId = (int) $request->getRouteParam('id');
        $trade = $this->tradeService->markBeReached($userId, $tradeId);

        return $this->jsonSuccess($trade);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $tradeId = (int) $request->getRouteParam('id');
        $this->tradeService->delete($userId, $tradeId);

        return $this->jsonSuccess(['message_key' => 'trades.success.deleted']);
    }

    public function bulkDestroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $body = $request->getBody();
        $ids = $body['ids'] ?? [];

        $count = $this->tradeService->deleteBulk($userId, $ids);

        return $this->jsonSuccess([
            'deleted_count' => $count,
            'message_key' => 'trades.success.bulk_deleted',
        ]);
    }
}
