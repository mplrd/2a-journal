<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\RobotService;

class RobotController extends Controller
{
    public function __construct(private RobotService $service) {}

    public function index(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        return $this->jsonSuccess($this->service->listForUser($userId));
    }

    public function show(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $robotId = (int) $request->getRouteParam('id');
        return $this->jsonSuccess($this->service->getDetailForUser($userId, $robotId));
    }

    public function regenerate(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $robotId = (int) $request->getRouteParam('id');
        return $this->jsonSuccess($this->service->regenerate($userId, $robotId), null, 201);
    }

    public function store(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $result = $this->service->create($userId, $request->getBody());
        return $this->jsonSuccess($result, null, 201);
    }

    public function updateStatus(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $robotId = (int) $request->getRouteParam('id');
        $robot = $this->service->changeStatus($userId, $robotId, $request->getBody('status'));
        return $this->jsonSuccess($robot);
    }

    public function destroy(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $robotId = (int) $request->getRouteParam('id');
        $this->service->archive($userId, $robotId);
        return $this->jsonSuccess(['archived' => true]);
    }

    public function events(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $robotId = (int) $request->getRouteParam('id');
        $page = (int) ($request->getQuery('page') ?? 1);
        $perPage = (int) ($request->getQuery('per_page') ?? 50);
        $result = $this->service->listEvents($userId, $robotId, $page, $perPage);
        return $this->jsonSuccess($result['data'], $result['meta']);
    }
}
