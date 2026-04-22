<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\SymbolService;

class SymbolController extends Controller
{
    private SymbolService $symbolService;

    public function __construct(SymbolService $symbolService)
    {
        $this->symbolService = $symbolService;
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

        $result = $this->symbolService->list($userId, $params);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $symbol = $this->symbolService->create($userId, $request->getBody());

        return $this->jsonSuccess($symbol, null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $symbolId = (int)$request->getRouteParam('id');
        $symbol = $this->symbolService->get($userId, $symbolId);

        return $this->jsonSuccess($symbol);
    }

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $symbolId = (int)$request->getRouteParam('id');
        $symbol = $this->symbolService->update($userId, $symbolId, $request->getBody());

        return $this->jsonSuccess($symbol);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $symbolId = (int)$request->getRouteParam('id');
        $this->symbolService->delete($userId, $symbolId);

        return $this->jsonSuccess(['message_key' => 'symbols.success.deleted']);
    }
}
