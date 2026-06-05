<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\NoteCategoryService;

class NoteCategoryController extends Controller
{
    public function __construct(private NoteCategoryService $service)
    {
    }

    public function index(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        return $this->jsonSuccess($this->service->list($userId));
    }

    public function store(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');

        return $this->jsonSuccess($this->service->create($userId, $request->getBody()), null, 201);
    }

    public function update(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');

        return $this->jsonSuccess($this->service->update($userId, $id, $request->getBody()));
    }

    public function destroy(Request $request): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $id = (int) $request->getRouteParam('id');
        $this->service->delete($userId, $id);

        return $this->jsonSuccess(['message_key' => 'note_categories.success.deleted']);
    }
}
