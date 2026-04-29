<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\SetupService;

class SetupController extends Controller
{
    private SetupService $setupService;

    public function __construct(SetupService $setupService)
    {
        $this->setupService = $setupService;
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $result = $this->setupService->list($userId);

        return $this->jsonSuccess($result);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $setup = $this->setupService->create($userId, $request->getBody());

        return $this->jsonSuccess($setup, null, 201);
    }

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $setupId = (int)$request->getRouteParam('id');
        $setup = $this->setupService->update($userId, $setupId, $request->getBody());

        return $this->jsonSuccess($setup);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $setupId = (int)$request->getRouteParam('id');
        $this->setupService->delete($userId, $setupId);

        return $this->jsonSuccess(['message_key' => 'setups.success.deleted']);
    }
}
