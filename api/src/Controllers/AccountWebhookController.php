<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AccountWebhookService;

class AccountWebhookController extends Controller
{
    public function __construct(private AccountWebhookService $service) {}

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) $request->getRouteParam('id');
        return $this->jsonSuccess($this->service->listForAccount($userId, $accountId));
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) $request->getRouteParam('id');
        $result = $this->service->create($userId, $accountId, $request->getBody());
        return $this->jsonSuccess($result, null, 201);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) $request->getRouteParam('id');
        $webhookId = (int) $request->getRouteParam('webhookId');
        $this->service->revoke($userId, $accountId, $webhookId);
        return $this->jsonSuccess(['revoked' => true]);
    }

    public function events(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $accountId = (int) $request->getRouteParam('id');
        $webhookId = (int) $request->getRouteParam('webhookId');
        $page = (int) ($request->getQuery('page') ?? 1);
        $perPage = (int) ($request->getQuery('per_page') ?? 50);
        $result = $this->service->listEvents($userId, $accountId, $webhookId, $page, $perPage);
        return $this->jsonSuccess($result['data'], $result['meta']);
    }
}
