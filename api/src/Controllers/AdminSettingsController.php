<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Services\PlatformSettingsService;

class AdminSettingsController extends Controller
{
    public function __construct(private PlatformSettingsService $service) {}

    public function index(Request $request): Response
    {
        return $this->jsonSuccess($this->service->list());
    }

    public function update(Request $request): Response
    {
        $key = (string) $request->getRouteParam('key');
        $body = $request->getBody();
        if (!array_key_exists('value', $body)) {
            throw new ValidationException('admin.settings.error.value_required', 'value');
        }
        $adminId = (int) $request->getAttribute('user_id');
        $this->service->update($key, $body['value'], $adminId);

        // Return the updated list so the BO can refresh state in one round-trip
        return $this->jsonSuccess($this->service->list());
    }
}
