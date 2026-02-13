<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\PositionService;
use App\Services\ShareService;

class PositionController extends Controller
{
    private PositionService $positionService;
    private ShareService $shareService;

    public function __construct(PositionService $positionService, ShareService $shareService)
    {
        $this->positionService = $positionService;
        $this->shareService = $shareService;
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $filters = [];

        foreach (['account_id', 'position_type', 'symbol', 'direction', 'page', 'per_page'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $result = $this->positionService->list($userId, $filters);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $positionId = (int) $request->getRouteParam('id');
        $position = $this->positionService->get($userId, $positionId);

        return $this->jsonSuccess($position);
    }

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $positionId = (int) $request->getRouteParam('id');
        $position = $this->positionService->update($userId, $positionId, $request->getBody());

        return $this->jsonSuccess($position);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $positionId = (int) $request->getRouteParam('id');
        $this->positionService->delete($userId, $positionId);

        return $this->jsonSuccess(['message_key' => 'positions.success.deleted']);
    }

    public function transfer(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $positionId = (int) $request->getRouteParam('id');
        $position = $this->positionService->transfer($userId, $positionId, $request->getBody());

        return $this->jsonSuccess($position);
    }

    public function history(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $positionId = (int) $request->getRouteParam('id');
        $history = $this->positionService->getHistory($userId, $positionId);

        return $this->jsonSuccess($history);
    }

    public function shareText(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $positionId = (int) $request->getRouteParam('id');
        $text = $this->shareService->generateText($userId, $positionId);

        return $this->jsonSuccess(['text' => $text]);
    }

    public function shareTextPlain(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $positionId = (int) $request->getRouteParam('id');
        $text = $this->shareService->generateTextPlain($userId, $positionId);

        return $this->jsonSuccess(['text' => $text]);
    }
}
