<?php

use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\BillingController;
use App\Controllers\OrderController;
use App\Controllers\PositionController;
use App\Controllers\CustomFieldController;
use App\Controllers\SetupController;
use App\Controllers\SymbolController;
use App\Controllers\BrokerSyncController;
use App\Controllers\ImportController;
use App\Controllers\StatsController;
use App\Controllers\TradeController;
use App\Core\Database;
use App\Core\Router;
use App\Middlewares\AuthMiddleware;
use App\Middlewares\FeatureFlagMiddleware;
use App\Middlewares\RateLimitMiddleware;
use App\Middlewares\RequireActiveSubscriptionMiddleware;
use App\Repositories\AccountRepository;
use App\Repositories\SubscriptionRepository;
use App\Repositories\WebhookEventRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PartialExitRepository;
use App\Repositories\CustomFieldDefinitionRepository;
use App\Repositories\CustomFieldValueRepository;
use App\Repositories\SetupRepository;
use App\Repositories\SymbolAccountSettingsRepository;
use App\Repositories\SymbolRepository;
use App\Repositories\PositionRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\ImportBatchRepository;
use App\Repositories\StatsRepository;
use App\Repositories\SymbolAliasRepository;
use App\Repositories\SyncLogRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;
use App\Repositories\UserRepository;
use App\Repositories\EmailVerificationTokenRepository;
use App\Repositories\PasswordResetTokenRepository;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\BillingService;
use App\Services\EmailService;
use App\Services\OrderService;
use App\Services\PositionService;
use App\Services\ShareService;
use App\Services\CustomFieldService;
use App\Services\SetupService;
use App\Services\Broker\BrokerSyncService;
use App\Services\Broker\CredentialEncryptionService;
use App\Services\Broker\CtraderConnector;
use App\Services\Broker\MetaApiConnector;
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

// ── Features (public) ────────────────────────────────────────────
$brokerConfigForFeatures = require __DIR__ . '/broker.php';
$router->get('/features', function (App\Core\Request $request) use ($brokerConfigForFeatures) {
    return App\Core\Response::success([
        'broker_auto_sync' => (bool) $brokerConfigForFeatures['auto_sync_enabled'],
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
$router->delete('/auth/me', [$authController, 'deleteAccount'], [$authMiddleware]);
$router->post('/auth/change-password', [$authController, 'changePassword'], [$authMiddleware]);
$router->patch('/auth/profile', [$authController, 'updateProfile'], [$authMiddleware]);
$router->patch('/auth/locale', [$authController, 'updateLocale'], [$authMiddleware]);
$router->post('/auth/profile-picture', [$authController, 'uploadProfilePicture'], [$authMiddleware]);
$router->post('/auth/complete-onboarding', [$authController, 'completeOnboarding'], [$authMiddleware]);
$router->get('/auth/verify-email', [$authController, 'verifyEmail']);
$router->post('/auth/resend-verification', [$authController, 'resendVerification'], [$authMiddleware]);
$router->post('/auth/forgot-password', [$authController, 'forgotPassword'], [$forgotPasswordRateLimit]);
$router->post('/auth/reset-password', [$authController, 'resetPassword']);

// ── Billing (Stripe) ────────────────────────────────────────────
$billingConfig = require __DIR__ . '/billing.php';
$subscriptionRepo = new SubscriptionRepository($pdo);
$webhookEventRepo = new WebhookEventRepository($pdo);
$stripeClient = new \Stripe\StripeClient($billingConfig['secret_key']);
$billingService = new BillingService(
    $userRepo,
    $subscriptionRepo,
    $webhookEventRepo,
    $stripeClient,
    $billingConfig
);
$billingController = new BillingController($billingService);
$requireSubscription = new RequireActiveSubscriptionMiddleware($billingService);

$router->get('/billing/status', [$billingController, 'status'], [$authMiddleware]);
$router->post('/billing/checkout', [$billingController, 'checkout'], [$authMiddleware]);
$router->post('/billing/portal', [$billingController, 'portal'], [$authMiddleware]);
$router->post('/billing/cancel', [$billingController, 'cancel'], [$authMiddleware]);
$router->post('/billing/reactivate', [$billingController, 'reactivate'], [$authMiddleware]);
$router->post('/billing/webhook', [$billingController, 'webhook']);

// ── Symbols ─────────────────────────────────────────────────────
// AccountRepository is reused by the Accounts section below; instantiate once here.
$accountRepo = new AccountRepository($pdo);
$symbolSettingsRepo = new SymbolAccountSettingsRepository($pdo);
$symbolService = new SymbolService($symbolRepo, $symbolSettingsRepo, $accountRepo);
$symbolController = new SymbolController($symbolService);

$router->get('/symbols', [$symbolController, 'index'], [$authMiddleware, $requireSubscription]);
$router->post('/symbols', [$symbolController, 'store'], [$authMiddleware, $requireSubscription]);
$router->get('/symbols/settings', [$symbolController, 'settings'], [$authMiddleware, $requireSubscription]);
$router->get('/symbols/{id}', [$symbolController, 'show'], [$authMiddleware, $requireSubscription]);
$router->put('/symbols/{id}', [$symbolController, 'update'], [$authMiddleware, $requireSubscription]);
$router->delete('/symbols/{id}', [$symbolController, 'destroy'], [$authMiddleware, $requireSubscription]);
$router->put('/symbols/{id}/settings/{accountId}', [$symbolController, 'setSetting'], [$authMiddleware, $requireSubscription]);
$router->delete('/symbols/{id}/settings/{accountId}', [$symbolController, 'clearSetting'], [$authMiddleware, $requireSubscription]);

// ── Setups ──────────────────────────────────────────────────────
$setupService = new SetupService($setupRepo);
$setupController = new SetupController($setupService);

$router->get('/setups', [$setupController, 'index'], [$authMiddleware, $requireSubscription]);
$router->post('/setups', [$setupController, 'store'], [$authMiddleware, $requireSubscription]);
$router->delete('/setups/{id}', [$setupController, 'destroy'], [$authMiddleware, $requireSubscription]);

// ── Custom Fields ──────────────────────────────────────────────
$customFieldRepo = new CustomFieldDefinitionRepository($pdo);
$customFieldValueRepo = new CustomFieldValueRepository($pdo);
$customFieldService = new CustomFieldService($customFieldRepo, $customFieldValueRepo);
$customFieldController = new CustomFieldController($customFieldService);

$router->get('/custom-fields', [$customFieldController, 'index'], [$authMiddleware, $requireSubscription]);
$router->post('/custom-fields', [$customFieldController, 'store'], [$authMiddleware, $requireSubscription]);
$router->get('/custom-fields/{id}', [$customFieldController, 'show'], [$authMiddleware, $requireSubscription]);
$router->put('/custom-fields/{id}', [$customFieldController, 'update'], [$authMiddleware, $requireSubscription]);
$router->delete('/custom-fields/{id}', [$customFieldController, 'destroy'], [$authMiddleware, $requireSubscription]);

// ── Accounts ────────────────────────────────────────────────────
// $accountRepo already instantiated in the Symbols section above.
$accountService = new AccountService($accountRepo);
$accountController = new AccountController($accountService);

$router->get('/accounts', [$accountController, 'index'], [$authMiddleware, $requireSubscription]);
$router->post('/accounts', [$accountController, 'store'], [$authMiddleware, $requireSubscription]);
$router->get('/accounts/{id}', [$accountController, 'show'], [$authMiddleware, $requireSubscription]);
$router->put('/accounts/{id}', [$accountController, 'update'], [$authMiddleware, $requireSubscription]);
$router->delete('/accounts/{id}', [$accountController, 'destroy'], [$authMiddleware, $requireSubscription]);

// ── Shared repos for Orders, Trades & Share ──────────────────
$tradeRepo = new TradeRepository($pdo);
$partialExitRepo = new PartialExitRepository($pdo);

// ── Positions ──────────────────────────────────────────────────
$positionRepo = new PositionRepository($pdo);
$historyRepo = new StatusHistoryRepository($pdo);
$positionService = new PositionService($positionRepo, $accountRepo, $historyRepo, $setupRepo);
$shareService = new ShareService($positionRepo, $tradeRepo);
$positionController = new PositionController($positionService, $shareService);

$router->get('/positions', [$positionController, 'index'], [$authMiddleware, $requireSubscription]);
$router->get('/positions/aggregated', [$positionController, 'aggregated'], [$authMiddleware, $requireSubscription]);
$router->get('/positions/{id}', [$positionController, 'show'], [$authMiddleware, $requireSubscription]);
$router->put('/positions/{id}', [$positionController, 'update'], [$authMiddleware, $requireSubscription]);
$router->delete('/positions/{id}', [$positionController, 'destroy'], [$authMiddleware, $requireSubscription]);
$router->post('/positions/{id}/transfer', [$positionController, 'transfer'], [$authMiddleware, $requireSubscription]);
$router->get('/positions/{id}/history', [$positionController, 'history'], [$authMiddleware, $requireSubscription]);
$router->get('/positions/{id}/share/text', [$positionController, 'shareText'], [$authMiddleware, $requireSubscription]);
$router->get('/positions/{id}/share/text-plain', [$positionController, 'shareTextPlain'], [$authMiddleware, $requireSubscription]);

// ── Orders ────────────────────────────────────────────────────
$orderRepo = new OrderRepository($pdo);
$orderService = new OrderService($orderRepo, $positionRepo, $accountRepo, $historyRepo, $tradeRepo, $setupRepo);
$orderController = new OrderController($orderService);

$router->get('/orders', [$orderController, 'index'], [$authMiddleware, $requireSubscription]);
$router->post('/orders', [$orderController, 'store'], [$authMiddleware, $requireSubscription]);
$router->get('/orders/{id}', [$orderController, 'show'], [$authMiddleware, $requireSubscription]);
$router->delete('/orders/{id}', [$orderController, 'destroy'], [$authMiddleware, $requireSubscription]);
$router->post('/orders/{id}/cancel', [$orderController, 'cancel'], [$authMiddleware, $requireSubscription]);
$router->post('/orders/{id}/execute', [$orderController, 'execute'], [$authMiddleware, $requireSubscription]);

// ── Trades ─────────────────────────────────────────────────────
$tradeService = new TradeService($tradeRepo, $partialExitRepo, $positionRepo, $accountRepo, $historyRepo, $setupRepo, $customFieldService);
$tradeController = new TradeController($tradeService);

$router->get('/trades', [$tradeController, 'index'], [$authMiddleware, $requireSubscription]);
$router->post('/trades', [$tradeController, 'store'], [$authMiddleware, $requireSubscription]);
$router->get('/trades/{id}', [$tradeController, 'show'], [$authMiddleware, $requireSubscription]);
$router->post('/trades/{id}/close', [$tradeController, 'close'], [$authMiddleware, $requireSubscription]);
$router->post('/trades/{id}/be-hit', [$tradeController, 'beHit'], [$authMiddleware, $requireSubscription]);
$router->delete('/trades/{id}', [$tradeController, 'destroy'], [$authMiddleware, $requireSubscription]);

// ── Import ────────────────────────────────────────────────────
$importBatchRepo = new ImportBatchRepository($pdo);
$symbolAliasRepo = new SymbolAliasRepository($pdo);
$importService = new ImportService(
    new FileParserService(),
    new ColumnMapperService(),
    new RowGroupingService(),
    $importBatchRepo,
    $symbolAliasRepo,
    $symbolRepo,
    $positionRepo,
    $tradeRepo,
    $accountRepo,
    $pdo,
    $customFieldService
);
$importController = new ImportController($importService);

$router->get('/imports/templates', [$importController, 'templates'], [$authMiddleware, $requireSubscription]);
$router->get('/imports/template-file', [$importController, 'downloadTemplate'], [$authMiddleware, $requireSubscription]);
$router->post('/imports/headers', [$importController, 'headers'], [$authMiddleware, $requireSubscription]);
$router->post('/imports/preview', [$importController, 'preview'], [$authMiddleware, $requireSubscription]);
$router->post('/imports/confirm', [$importController, 'confirm'], [$authMiddleware, $requireSubscription]);
$router->get('/imports/batches', [$importController, 'batches'], [$authMiddleware, $requireSubscription]);
$router->post('/imports/batches/{id}/rollback', [$importController, 'rollback'], [$authMiddleware, $requireSubscription]);

// ── Broker Sync ──────────────────────────────────────────────
$brokerConfig = require __DIR__ . '/broker.php';
$brokerFeatureFlag = new FeatureFlagMiddleware(
    (bool) $brokerConfig['auto_sync_enabled'],
    'broker.error.auto_sync_disabled'
);
$brokerConnectionRepo = new BrokerConnectionRepository($pdo);
$syncLogRepo = new SyncLogRepository($pdo);
$cryptoService = new CredentialEncryptionService($brokerConfig['encryption_key']);
$metaApiConnector = new MetaApiConnector(
    new \GuzzleHttp\Client(),
    $brokerConfig['metaapi']['base_url']
);
$ctraderConnector = new CtraderConnector($brokerConfig['ctrader']);
$brokerSyncService = new BrokerSyncService(
    $brokerConnectionRepo,
    $syncLogRepo,
    $importService,
    new RowGroupingService(),
    $cryptoService,
    $ctraderConnector,
    $metaApiConnector,
);
$brokerSyncController = new BrokerSyncController(
    $brokerSyncService,
    $brokerConnectionRepo,
    $syncLogRepo,
    $cryptoService,
);

$router->post('/broker/connections', [$brokerSyncController, 'createConnection'], [$authMiddleware, $requireSubscription, $brokerFeatureFlag]);
$router->get('/broker/connections', [$brokerSyncController, 'connections'], [$authMiddleware, $requireSubscription, $brokerFeatureFlag]);
$router->post('/broker/connections/{id}/sync', [$brokerSyncController, 'sync'], [$authMiddleware, $requireSubscription, $brokerFeatureFlag]);
$router->delete('/broker/connections/{id}', [$brokerSyncController, 'deleteConnection'], [$authMiddleware, $requireSubscription, $brokerFeatureFlag]);
$router->get('/broker/connections/{id}/logs', [$brokerSyncController, 'syncLogs'], [$authMiddleware, $requireSubscription, $brokerFeatureFlag]);

// ── Stats ─────────────────────────────────────────────────────
$statsRepo = new StatsRepository($pdo);
$statsService = new StatsService($statsRepo, $accountRepo, $userRepo);
$statsController = new StatsController($statsService);

$router->get('/stats/overview', [$statsController, 'dashboard'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/charts', [$statsController, 'charts'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/by-symbol', [$statsController, 'bySymbol'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/by-direction', [$statsController, 'byDirection'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/by-setup', [$statsController, 'bySetup'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/by-period', [$statsController, 'byPeriod'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/rr-distribution', [$statsController, 'rrDistribution'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/heatmap', [$statsController, 'heatmap'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/open-trades', [$statsController, 'openTrades'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/daily-pnl', [$statsController, 'dailyPnl'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/by-session', [$statsController, 'bySession'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/by-account', [$statsController, 'byAccount'], [$authMiddleware, $requireSubscription]);
$router->get('/stats/by-account-type', [$statsController, 'byAccountType'], [$authMiddleware, $requireSubscription]);
