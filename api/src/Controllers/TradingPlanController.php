<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\TradingPlanService;

class TradingPlanController extends Controller
{
    public function __construct(private TradingPlanService $service) {}

    public function index(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        return $this->jsonSuccess($this->service->listForUser($userId));
    }

    public function show(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $planId = (int) $request->getRouteParam('id');
        return $this->jsonSuccess($this->service->getForUser($userId, $planId));
    }

    public function store(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $plan = $this->service->create($userId, $request->getBody());
        return $this->jsonSuccess($plan, null, 201);
    }

    public function update(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $planId = (int) $request->getRouteParam('id');
        $plan = $this->service->update($userId, $planId, $request->getBody());
        return $this->jsonSuccess($plan);
    }

    /**
     * Read-only: confront a contemplated entry with this plan and hand back the
     * verdict. Writes nothing (docs/102).
     */
    public function evaluate(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $planId = (int) $request->getRouteParam('id');

        return $this->jsonSuccess($this->service->evaluateDraft($userId, $planId, $request->getBody()));
    }

    public function destroy(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $planId = (int) $request->getRouteParam('id');
        $this->service->archive($userId, $planId);
        return $this->jsonSuccess(['archived' => true]);
    }
}
