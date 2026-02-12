<?php

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\OrderController;
use App\Controllers\PositionController;
use App\Controllers\SymbolController;
use App\Controllers\TradeController;
use App\Core\Database;
use App\Core\Router;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\RateLimitMiddleware;
use App\Repositories\AccountRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\PositionRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;
use App\Repositories\UserRepository;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\OrderService;
use App\Services\PositionService;
use App\Services\SymbolService;
use App\Services\TradeService;

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

// Rate limiting
$securityConfig = require __DIR__ . '/security.php';
$rateLimitRepo = new RateLimitRepository($pdo);
$loginRateLimit = new RateLimitMiddleware(
    $rateLimitRepo,
    $securityConfig['rate_limits']['login']['max_attempts'],
    $securityConfig['rate_limits']['login']['window_seconds'],
    '/auth/login'
);
$registerRateLimit = new RateLimitMiddleware(
    $rateLimitRepo,
    $securityConfig['rate_limits']['register']['max_attempts'],
    $securityConfig['rate_limits']['register']['window_seconds'],
    '/auth/register'
);
$refreshRateLimit = new RateLimitMiddleware(
    $rateLimitRepo,
    $securityConfig['rate_limits']['refresh']['max_attempts'],
    $securityConfig['rate_limits']['refresh']['window_seconds'],
    '/auth/refresh'
);

$router->post('/auth/register', [$authController, 'register'], [$registerRateLimit]);
$router->post('/auth/login', [$authController, 'login'], [$loginRateLimit]);
$router->post('/auth/refresh', [$authController, 'refresh'], [$refreshRateLimit]);
$router->post('/auth/logout', [$authController, 'logout'], [$authMiddleware]);
$router->get('/auth/me', [$authController, 'me'], [$authMiddleware]);

// ── Symbols ─────────────────────────────────────────────────────
$symbolRepo = new SymbolRepository($pdo);
$symbolService = new SymbolService($symbolRepo);
$symbolController = new SymbolController($symbolService);

$router->get('/symbols', [$symbolController, 'index'], [$authMiddleware]);

// ── Accounts ────────────────────────────────────────────────────
$accountRepo = new AccountRepository($pdo);
$accountService = new AccountService($accountRepo);
$accountController = new AccountController($accountService);

$router->get('/accounts', [$accountController, 'index'], [$authMiddleware]);
$router->post('/accounts', [$accountController, 'store'], [$authMiddleware]);
$router->get('/accounts/{id}', [$accountController, 'show'], [$authMiddleware]);
$router->put('/accounts/{id}', [$accountController, 'update'], [$authMiddleware]);
$router->delete('/accounts/{id}', [$accountController, 'destroy'], [$authMiddleware]);

// ── Positions ──────────────────────────────────────────────────
$positionRepo = new PositionRepository($pdo);
$historyRepo = new StatusHistoryRepository($pdo);
$positionService = new PositionService($positionRepo, $accountRepo, $historyRepo);
$positionController = new PositionController($positionService);

$router->get('/positions', [$positionController, 'index'], [$authMiddleware]);
$router->get('/positions/{id}', [$positionController, 'show'], [$authMiddleware]);
$router->put('/positions/{id}', [$positionController, 'update'], [$authMiddleware]);
$router->delete('/positions/{id}', [$positionController, 'destroy'], [$authMiddleware]);
$router->post('/positions/{id}/transfer', [$positionController, 'transfer'], [$authMiddleware]);
$router->get('/positions/{id}/history', [$positionController, 'history'], [$authMiddleware]);

// ── Shared repos for Orders & Trades ──────────────────────────
$tradeRepo = new TradeRepository($pdo);
$partialExitRepo = new PartialExitRepository($pdo);

// ── Orders ────────────────────────────────────────────────────
$orderRepo = new OrderRepository($pdo);
$orderService = new OrderService($orderRepo, $positionRepo, $accountRepo, $historyRepo, $tradeRepo);
$orderController = new OrderController($orderService);

$router->get('/orders', [$orderController, 'index'], [$authMiddleware]);
$router->post('/orders', [$orderController, 'store'], [$authMiddleware]);
$router->get('/orders/{id}', [$orderController, 'show'], [$authMiddleware]);
$router->delete('/orders/{id}', [$orderController, 'destroy'], [$authMiddleware]);
$router->post('/orders/{id}/cancel', [$orderController, 'cancel'], [$authMiddleware]);
$router->post('/orders/{id}/execute', [$orderController, 'execute'], [$authMiddleware]);

// ── Trades ─────────────────────────────────────────────────────
$tradeService = new TradeService($tradeRepo, $partialExitRepo, $positionRepo, $accountRepo, $historyRepo);
$tradeController = new TradeController($tradeService);

$router->get('/trades', [$tradeController, 'index'], [$authMiddleware]);
$router->post('/trades', [$tradeController, 'store'], [$authMiddleware]);
$router->get('/trades/{id}', [$tradeController, 'show'], [$authMiddleware]);
$router->post('/trades/{id}/close', [$tradeController, 'close'], [$authMiddleware]);
$router->delete('/trades/{id}', [$tradeController, 'destroy'], [$authMiddleware]);
