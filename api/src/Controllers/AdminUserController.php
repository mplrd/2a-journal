<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminUserService;

class AdminUserController extends Controller
{
    public function __construct(private AdminUserService $service) {}

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (['search', 'status', 'page', 'per_page'] as $key) {
            $value = $request->getQuery($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = $value;
            }
        }

        $result = $this->service->list($filters);
        return $this->jsonSuccess($result['data'], $result['meta']);
    }

    public function show(Request $request): Response
    {
        $userId = (int) $request->getRouteParam('id');
        return $this->jsonSuccess($this->service->get($userId));
    }

    public function suspend(Request $request): Response
    {
        $adminId = (int) $request->getAttribute('user_id');
        $userId = (int) $request->getRouteParam('id');
        return $this->jsonSuccess($this->service->suspend($adminId, $userId));
    }

    public function unsuspend(Request $request): Response
    {
        $userId = (int) $request->getRouteParam('id');
        return $this->jsonSuccess($this->service->unsuspend($userId));
    }

    public function resetPassword(Request $request): Response
    {
        $userId = (int) $request->getRouteParam('id');
        $this->service->resetPassword($userId);
        return $this->jsonSuccess(['sent' => true]);
    }

    public function destroy(Request $request): Response
    {
        $adminId = (int) $request->getAttribute('user_id');
        $userId = (int) $request->getRouteParam('id');
        $this->service->delete($adminId, $userId);
        return $this->jsonSuccess(['deleted' => true]);
    }
}
