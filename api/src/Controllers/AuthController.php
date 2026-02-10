<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function register(Request $request): Response
    {
        $result = $this->authService->register($request->getBody());

        return $this->jsonSuccess($result, null, 201);
    }

    public function login(Request $request): Response
    {
        $result = $this->authService->login($request->getBody());

        return $this->jsonSuccess($result);
    }

    public function refresh(Request $request): Response
    {
        $result = $this->authService->refresh($request->getBody());

        return $this->jsonSuccess($result);
    }

    public function logout(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $this->authService->logout($userId);

        return $this->jsonSuccess(['message_key' => 'auth.success.logged_out']);
    }

    public function me(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $user = $this->authService->getProfile($userId);

        return $this->jsonSuccess($user);
    }
}
