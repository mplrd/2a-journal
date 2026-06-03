<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccountService;

/**
 * Manual balance corrections on an account (ticket #30).
 * Routes: /accounts/{id}/adjustments (GET, POST), /{adjustmentId} (DELETE).
 */
class AccountAdjustmentController extends Controller
{
    public function __construct(private AccountService $accountService) {}

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) $request->getRouteParam('id');

        return $this->jsonSuccess($this->accountService->listAdjustments($userId, $accountId));
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) $request->getRouteParam('id');
        $adjustment = $this->accountService->addAdjustment($userId, $accountId, $request->getBody());

        return $this->jsonSuccess($adjustment, null, 201);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) $request->getRouteParam('id');
        $adjustmentId = (int) $request->getRouteParam('adjustmentId');
        $this->accountService->deleteAdjustment($userId, $accountId, $adjustmentId);

        return $this->jsonSuccess(['message_key' => 'accounts.success.adjustment_deleted']);
    }
}
