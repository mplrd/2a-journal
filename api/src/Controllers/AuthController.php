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

        return $this->respondWithCookie($result, 201);
    }

    public function login(Request $request): Response
    {
        $result = $this->authService->login($request->getBody());

        return $this->respondWithCookie($result);
    }

    public function refresh(Request $request): Response
    {
        $refreshToken = $request->getCookie('refresh_token') ?? $request->getBody('refresh_token');

        $result = $this->authService->refresh(['refresh_token' => $refreshToken]);

        return $this->respondWithCookie($result);
    }

    public function logout(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $result = $this->authService->logout($userId);

        $response = $this->jsonSuccess(['message_key' => 'auth.success.logged_out']);
        if (isset($result['refresh_cookie'])) {
            $response->withHeader('Set-Cookie', $result['refresh_cookie']);
        }

        return $response;
    }

    public function me(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $user = $this->authService->getProfile($userId);

        return $this->jsonSuccess($user);
    }

    public function updateLocale(Request $request): Response
    {
        $userId = $request->getAttribute('user_id');
        $locale = $request->getBody('locale') ?? '';
        $user = $this->authService->updateLocale($userId, $locale);

        return $this->jsonSuccess($user);
    }

    private function respondWithCookie(array $result, int $status = 200): Response
    {
        $cookie = $result['refresh_cookie'] ?? null;
        unset($result['refresh_cookie']);

        $response = $this->jsonSuccess($result, null, $status);
        if ($cookie) {
            $response->withHeader('Set-Cookie', $cookie);
        }

        return $response;
    }
}
