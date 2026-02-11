<?php

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Core\Database;
use App\Core\Router;
use App\Middlewares\AuthMiddleware;
use App\Repositories\AccountRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\AuthService;

/** @var Router $router */

// ── Health ───────────────────────────────────────────────────────
$router->get('/health', function (App\Core\Request $request) {
    return App\Core\Response::success([
        'status' => 'ok',
        'timestamp' => date('c'),
        'version' => '1.0.0',
    ]);
});

// ── Auth ─────────────────────────────────────────────────────────
$authConfig = require __DIR__ . '/auth.php';
$pdo = Database::getConnection();
$userRepo = new UserRepository($pdo);
$tokenRepo = new RefreshTokenRepository($pdo);
$authService = new AuthService($userRepo, $tokenRepo, $authConfig);
$authController = new AuthController($authService);
$authMiddleware = new AuthMiddleware($authConfig['jwt_secret']);

$router->post('/auth/register', [$authController, 'register']);
$router->post('/auth/login', [$authController, 'login']);
$router->post('/auth/refresh', [$authController, 'refresh']);
$router->post('/auth/logout', [$authController, 'logout'], [$authMiddleware]);
$router->get('/auth/me', [$authController, 'me'], [$authMiddleware]);

// ── Accounts ────────────────────────────────────────────────────
$accountRepo = new AccountRepository($pdo);
$accountService = new AccountService($accountRepo);
$accountController = new AccountController($accountService);

$router->get('/accounts', [$accountController, 'index'], [$authMiddleware]);
$router->post('/accounts', [$accountController, 'store'], [$authMiddleware]);
$router->get('/accounts/{id}', [$accountController, 'show'], [$authMiddleware]);
$router->put('/accounts/{id}', [$accountController, 'update'], [$authMiddleware]);
$router->delete('/accounts/{id}', [$accountController, 'destroy'], [$authMiddleware]);
