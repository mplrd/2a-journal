<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\CustomFieldService;

class CustomFieldController extends Controller
{
    private CustomFieldService $service;

    public function __construct(CustomFieldService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $result = $this->service->list($userId);

        return $this->jsonSuccess($result);
    }

    public function store(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $field = $this->service->create($userId, $request->getBody());

        return $this->jsonSuccess($field, null, 201);
    }

    public function show(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');
        $field = $this->service->get($userId, $id);

        return $this->jsonSuccess($field);
    }

    public function update(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');
        $field = $this->service->update($userId, $id, $request->getBody());

        return $this->jsonSuccess($field);
    }

    public function destroy(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');
        $this->service->delete($userId, $id);

        return $this->jsonSuccess(['message_key' => 'custom_fields.success.deleted']);
    }
}
