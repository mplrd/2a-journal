<?php

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\OrderController;
use App\Controllers\PositionController;
use App\Controllers\SetupController;
use App\Controllers\SymbolController;
use App\Controllers\ImportController;
use App\Controllers\StatsController;
use App\Controllers\TradeController;
use App\Core\Database;
use App\Core\Router;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\RateLimitMiddleware;
use App\Repositories\AccountRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\SetupRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\PositionRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\StatsRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;
use App\Repositories\UserRepository;
use App\Repositories\EmailVerificationTokenRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\OrderService;
use App\Services\PositionService;
use App\Services\ShareService;
use App\Services\SetupService;
use App\Services\Import\ImportService;
use App\Services\Import\FileParserService;
use App\Services\Import\ColumnMapperService;
use App\Services\Import\RowGroupingService;
use App\Services\StatsService;
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
$symbolRepo = new SymbolRepository($pdo);
$setupRepo = new SetupRepository($pdo);
$securityConfig = require __DIR__ . '/security.php';
$mailConfig = require __DIR__ . '/mail.php';
$verificationTokenRepo = new EmailVerificationTokenRepository($pdo);
$resetTokenRepo = new PasswordResetTokenRepository($pdo);
$emailService = new EmailService($mailConfig);
$authService = new AuthService($userRepo, $tokenRepo, $symbolRepo, $setupRepo, $authConfig, $verificationTokenRepo, $resetTokenRepo, $emailService, $securityConfig);
$authController = new AuthController($authService);
$authMiddleware = new AuthMiddleware($authConfig['jwt_secret']);

// Rate limiting
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
$forgotPasswordRateLimit = new RateLimitMiddleware(
    $rateLimitRepo,
    $securityConfig['rate_limits']['forgot_password']['max_attempts'],
    $securityConfig['rate_limits']['forgot_password']['window_seconds'],
    '/auth/forgot-password'
);

$router->post('/auth/register', [$authController, 'register'], [$registerRateLimit]);
$router->post('/auth/login', [$authController, 'login'], [$loginRateLimit]);
$router->post('/auth/refresh', [$authController, 'refresh'], [$refreshRateLimit]);
$router->post('/auth/logout', [$authController, 'logout'], [$authMiddleware]);
$router->get('/auth/me', [$authController, 'me'], [$authMiddleware]);
$router->patch('/auth/profile', [$authController, 'updateProfile'], [$authMiddleware]);
$router->patch('/auth/locale', [$authController, 'updateLocale'], [$authMiddleware]);
$router->post('/auth/profile-picture', [$authController, 'uploadProfilePicture'], [$authMiddleware]);
$router->post('/auth/complete-onboarding', [$authController, 'completeOnboarding'], [$authMiddleware]);
$router->get('/auth/verify-email', [$authController, 'verifyEmail']);
$router->post('/auth/resend-verification', [$authController, 'resendVerification'], [$authMiddleware]);
$router->post('/auth/forgot-password', [$authController, 'forgotPassword'], [$forgotPasswordRateLimit]);
$router->post('/auth/reset-password', [$authController, 'resetPassword']);

// ── Symbols ─────────────────────────────────────────────────────
$symbolService = new SymbolService($symbolRepo);
$symbolController = new SymbolController($symbolService);

$router->get('/symbols', [$symbolController, 'index'], [$authMiddleware]);
$router->post('/symbols', [$symbolController, 'store'], [$authMiddleware]);
$router->get('/symbols/{id}', [$symbolController, 'show'], [$authMiddleware]);
$router->put('/symbols/{id}', [$symbolController, 'update'], [$authMiddleware]);
$router->delete('/symbols/{id}', [$symbolController, 'destroy'], [$authMiddleware]);

// ── Setups ──────────────────────────────────────────────────────
$setupService = new SetupService($setupRepo);
$setupController = new SetupController($setupService);

$router->get('/setups', [$setupController, 'index'], [$authMiddleware]);
$router->post('/setups', [$setupController, 'store'], [$authMiddleware]);
$router->delete('/setups/{id}', [$setupController, 'destroy'], [$authMiddleware]);

// ── Accounts ────────────────────────────────────────────────────
$accountRepo = new AccountRepository($pdo);
$accountService = new AccountService($accountRepo);
$accountController = new AccountController($accountService);

$router->get('/accounts', [$accountController, 'index'], [$authMiddleware]);
$router->post('/accounts', [$accountController, 'store'], [$authMiddleware]);
$router->get('/accounts/{id}', [$accountController, 'show'], [$authMiddleware]);
$router->put('/accounts/{id}', [$accountController, 'update'], [$authMiddleware]);
$router->delete('/accounts/{id}', [$accountController, 'destroy'], [$authMiddleware]);

// ── Shared repos for Orders, Trades & Share ──────────────────
$tradeRepo = new TradeRepository($pdo);
$partialExitRepo = new PartialExitRepository($pdo);

// ── Positions ──────────────────────────────────────────────────
$positionRepo = new PositionRepository($pdo);
$historyRepo = new StatusHistoryRepository($pdo);
$positionService = new PositionService($positionRepo, $accountRepo, $historyRepo, $setupRepo);
$shareService = new ShareService($positionRepo, $tradeRepo);
$positionController = new PositionController($positionService, $shareService);

$router->get('/positions', [$positionController, 'index'], [$authMiddleware]);
$router->get('/positions/aggregated', [$positionController, 'aggregated'], [$authMiddleware]);
$router->get('/positions/{id}', [$positionController, 'show'], [$authMiddleware]);
$router->put('/positions/{id}', [$positionController, 'update'], [$authMiddleware]);
$router->delete('/positions/{id}', [$positionController, 'destroy'], [$authMiddleware]);
$router->post('/positions/{id}/transfer', [$positionController, 'transfer'], [$authMiddleware]);
$router->get('/positions/{id}/history', [$positionController, 'history'], [$authMiddleware]);
$router->get('/positions/{id}/share/text', [$positionController, 'shareText'], [$authMiddleware]);
$router->get('/positions/{id}/share/text-plain', [$positionController, 'shareTextPlain'], [$authMiddleware]);

// ── Orders ────────────────────────────────────────────────────
$orderRepo = new OrderRepository($pdo);
$orderService = new OrderService($orderRepo, $positionRepo, $accountRepo, $historyRepo, $tradeRepo, $setupRepo);
$orderController = new OrderController($orderService);

$router->get('/orders', [$orderController, 'index'], [$authMiddleware]);
$router->post('/orders', [$orderController, 'store'], [$authMiddleware]);
$router->get('/orders/{id}', [$orderController, 'show'], [$authMiddleware]);
$router->delete('/orders/{id}', [$orderController, 'destroy'], [$authMiddleware]);
$router->post('/orders/{id}/cancel', [$orderController, 'cancel'], [$authMiddleware]);
$router->post('/orders/{id}/execute', [$orderController, 'execute'], [$authMiddleware]);

// ── Trades ─────────────────────────────────────────────────────
$tradeService = new TradeService($tradeRepo, $partialExitRepo, $positionRepo, $accountRepo, $historyRepo, $setupRepo);
$tradeController = new TradeController($tradeService);

$router->get('/trades', [$tradeController, 'index'], [$authMiddleware]);
$router->post('/trades', [$tradeController, 'store'], [$authMiddleware]);
$router->get('/trades/{id}', [$tradeController, 'show'], [$authMiddleware]);
$router->post('/trades/{id}/close', [$tradeController, 'close'], [$authMiddleware]);
$router->post('/trades/{id}/be-hit', [$tradeController, 'beHit'], [$authMiddleware]);
$router->delete('/trades/{id}', [$tradeController, 'destroy'], [$authMiddleware]);

// ── Import ────────────────────────────────────────────────────
$importBatchRepo = new ImportBatchRepository($pdo);
$symbolAliasRepo = new SymbolAliasRepository($pdo);
$importService = new ImportService(
    new FileParserService(),
    new ColumnMapperService(),
    new RowGroupingService(),
    $importBatchRepo,
    $symbolAliasRepo,
    $positionRepo,
    $tradeRepo,
    $accountRepo,
    $pdo
);
$importController = new ImportController($importService);

$router->get('/imports/templates', [$importController, 'templates'], [$authMiddleware]);
$router->post('/imports/headers', [$importController, 'headers'], [$authMiddleware]);
$router->post('/imports/preview', [$importController, 'preview'], [$authMiddleware]);
$router->post('/imports/confirm', [$importController, 'confirm'], [$authMiddleware]);
$router->get('/imports/batches', [$importController, 'batches'], [$authMiddleware]);
$router->post('/imports/batches/{id}/rollback', [$importController, 'rollback'], [$authMiddleware]);

// ── Stats ─────────────────────────────────────────────────────
$statsRepo = new StatsRepository($pdo);
$statsService = new StatsService($statsRepo, $accountRepo, $userRepo);
$statsController = new StatsController($statsService);

$router->get('/stats/overview', [$statsController, 'dashboard'], [$authMiddleware]);
$router->get('/stats/charts', [$statsController, 'charts'], [$authMiddleware]);
$router->get('/stats/by-symbol', [$statsController, 'bySymbol'], [$authMiddleware]);
$router->get('/stats/by-direction', [$statsController, 'byDirection'], [$authMiddleware]);
$router->get('/stats/by-setup', [$statsController, 'bySetup'], [$authMiddleware]);
$router->get('/stats/by-period', [$statsController, 'byPeriod'], [$authMiddleware]);
$router->get('/stats/rr-distribution', [$statsController, 'rrDistribution'], [$authMiddleware]);
$router->get('/stats/heatmap', [$statsController, 'heatmap'], [$authMiddleware]);
$router->get('/stats/open-trades', [$statsController, 'openTrades'], [$authMiddleware]);
$router->get('/stats/daily-pnl', [$statsController, 'dailyPnl'], [$authMiddleware]);
$router->get('/stats/by-session', [$statsController, 'bySession'], [$authMiddleware]);
$router->get('/stats/by-account', [$statsController, 'byAccount'], [$authMiddleware]);
$router->get('/stats/by-account-type', [$statsController, 'byAccountType'], [$authMiddleware]);
