<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\OrderService;

class OrderController extends Controller
{
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
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

        $result = $this->orderService->list($userId, $filters);

        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $order = $this->orderService->create($userId, $request->getBody());

        return $this->jsonSuccess($order, null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $orderId = (int) $request->getRouteParam('id');
        $order = $this->orderService->get($userId, $orderId);

        return $this->jsonSuccess($order);
    }

    public function cancel(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $orderId = (int) $request->getRouteParam('id');
        $order = $this->orderService->cancel($userId, $orderId);

        return $this->jsonSuccess($order);
    }

    public function execute(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $orderId = (int) $request->getRouteParam('id');
        $order = $this->orderService->execute($userId, $orderId);

        return $this->jsonSuccess($order);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $orderId = (int) $request->getRouteParam('id');
        $this->orderService->delete($userId, $orderId);

        return $this->jsonSuccess(['message_key' => 'orders.success.deleted']);
    }
}
