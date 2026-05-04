<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccountService;
use App\Services\DrawdownService;

class AccountController extends Controller
{
    private AccountService $accountService;
    private ?DrawdownService $drawdownService;

    public function __construct(AccountService $accountService, ?DrawdownService $drawdownService = null)
    {
        $this->accountService = $accountService;
        $this->drawdownService = $drawdownService;
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = [];
        foreach (['page', 'per_page'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        $result = $this->accountService->list($userId, $params);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $account = $this->accountService->create($userId, $request->getBody());

        return $this->jsonSuccess($account, null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int)$request->getRouteParam('id');
        $account = $this->accountService->get($userId, $accountId);

        return $this->jsonSuccess($account);
    }

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int)$request->getRouteParam('id');
        $account = $this->accountService->update($userId, $accountId, $request->getBody());

        return $this->jsonSuccess($account);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int)$request->getRouteParam('id');
        $this->accountService->delete($userId, $accountId);

        return $this->jsonSuccess(['message_key' => 'accounts.success.deleted']);
    }

    /**
     * GET /accounts/dd-status — DD usage + alert flags for every PF (or
     * non-PF with DD configured) account. Used by the dashboard banner.
     */
    public function ddStatus(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $statuses = $this->drawdownService !== null
            ? $this->drawdownService->getStatusForUser($userId)
            : [];

        return $this->jsonSuccess($statuses);
    }
}
