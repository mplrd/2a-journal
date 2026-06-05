<?php

namespace Tests\Integration\Webhooks;

use App\Core\Database;
use App\Enums\BrokerProvider;
use App\Enums\ConnectionStatus;
use App\Enums\OrderStatus;
use App\Enums\RobotStatus;
use App\Enums\WebhookEventStatus;
use App\Enums\WebhookRejectReason;
use App\Exceptions\BrokerOrderException;
use App\Repositories\AccountRepository;
use App\Repositories\BrokerConnectionRepository;
use App\Repositories\RobotRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PositionRepository;
use App\Repositories\SetupRepository;
use App\Repositories\StatusHistoryRepository;
use App\Repositories\TradeRepository;
use App\Repositories\TradingViewAlertEventRepository;
use App\Repositories\TradingViewWebhookRepository;
use App\Services\Broker\ConnectorInterface;
use App\Services\Broker\CredentialEncryptionService;
use App\Services\OrderService;
use App\Services\TradingViewWebhookService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage of TradingViewWebhookService — the heart of the
 * ingestion pipeline. Every reject reason, the dedup path, the broker-failure
 * path, and the happy path must each produce exactly one event row and update
 * the webhook counters consistently.
 */
class TradingViewWebhookFlowTest extends TestCase
{
    private PDO $pdo;
    private TradingViewWebhookRepository $webhookRepo;
    private RobotRepository $robotRepo;
    private OrderRepository $orderRepo;
    private TradingViewAlertEventRepository $eventRepo;
    private BrokerConnectionRepository $connectionRepo;
    private TradingViewWebhookService $service;
    private FakeConnector $connector;
    private CredentialEncryptionService $crypto;
    private int $userId;
    private int $accountId;

    protected function setUp(): void
    {
        $this->loadEnv();
        Database::reset();
        $this->pdo = Database::getConnection();

        $this->wipeTables();

        $this->webhookRepo = new TradingViewWebhookRepository($this->pdo);
        $this->robotRepo = new RobotRepository($this->pdo);
        $this->eventRepo = new TradingViewAlertEventRepository($this->pdo);
        $this->connectionRepo = new BrokerConnectionRepository($this->pdo);

        $orderRepo = new OrderRepository($this->pdo);
        $this->orderRepo = $orderRepo;
        $positionRepo = new PositionRepository($this->pdo);
        $accountRepo = new AccountRepository($this->pdo);
        $historyRepo = new StatusHistoryRepository($this->pdo);
        $tradeRepo = new TradeRepository($this->pdo);
        $setupRepo = new SetupRepository($this->pdo);
        $orderService = new OrderService($orderRepo, $positionRepo, $accountRepo, $historyRepo, $tradeRepo, $setupRepo);

        // 32-byte deterministic key — only used in-test for the encrypted
        // credentials roundtrip; nothing here ever touches a real broker.
        $this->crypto = new CredentialEncryptionService(str_repeat("\x01", 32));

        $this->connector = new FakeConnector();

        $this->service = new TradingViewWebhookService(
            $this->webhookRepo,
            $this->robotRepo,
            $this->eventRepo,
            $this->connectionRepo,
            $orderService,
            $orderRepo,
            $historyRepo,
            $this->crypto,
            $this->connector,
            $this->connector,
            $this->connector,
            $this->connector,
        );

        $this->userId = $this->seedUser();
        $this->accountId = $this->seedAccount($this->userId);
    }

    protected function tearDown(): void
    {
        $this->wipeTables();
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->service->process('nope', ['secret' => 'irrelevant']);

        $events = $this->fetchAllEvents();
        $this->assertCount(1, $events);
        $this->assertSame(WebhookEventStatus::REJECTED->value, $events[0]['status']);
        $this->assertSame(WebhookRejectReason::INVALID_TOKEN->value, $events[0]['reject_reason']);
        $this->assertNull($events[0]['webhook_id']);
    }

    public function testPausedRobotIsRejected(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook(RobotStatus::PAUSED);

        $this->service->process($token, $this->validPayload($secret));

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookRejectReason::ROBOT_PAUSED->value, $events[0]['reject_reason']);
    }

    public function testInvalidSecretIsRejected(): void
    {
        ['token' => $token] = $this->seedWebhook();

        $this->service->process($token, $this->validPayload('wrong-secret'));

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookRejectReason::INVALID_SECRET->value, $events[0]['reject_reason']);
    }

    public function testInvalidPayloadIsRejectedWithDetail(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $payload = $this->validPayload($secret);
        unset($payload['symbol']);

        $this->service->process($token, $payload);

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookRejectReason::INVALID_PAYLOAD->value, $events[0]['reject_reason']);
        $this->assertStringContainsString('symbol', $events[0]['error_message']);
    }

    public function testDuplicateAlertIsDedupedNotReprocessed(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $payload = $this->validPayload($secret);

        $this->service->process($token, $payload);
        $this->service->process($token, $payload);

        $events = $this->fetchAllEvents();
        $this->assertCount(2, $events);
        $statuses = array_column($events, 'status');
        sort($statuses);
        $this->assertSame(
            [WebhookEventStatus::DUPLICATE->value, WebhookEventStatus::PROCESSED->value],
            $statuses,
        );

        // Order should have been created only once.
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE position_id IN (SELECT id FROM positions WHERE account_id = :acc)");
        $stmt->execute(['acc' => $this->accountId]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testMissingBrokerConnectionIsRejected(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();

        $this->service->process($token, $this->validPayload($secret));

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookRejectReason::NO_BROKER->value, $events[0]['reject_reason']);
    }

    public function testInactiveBrokerConnectionIsRejected(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection(ConnectionStatus::ERROR);

        $this->service->process($token, $this->validPayload($secret));

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookRejectReason::BROKER_INACTIVE->value, $events[0]['reject_reason']);
    }

    public function testBrokerErrorMarksEventFailedButLeavesOrderInPlace(): void
    {
        ['token' => $token, 'secret' => $secret, 'robot_id' => $robotId] = $this->seedWebhook();
        $this->seedBrokerConnection();
        $this->connector->throwOnNext = new BrokerOrderException('insufficient margin', 'INSUFFICIENT_MARGIN');

        $this->service->process($token, $this->validPayload($secret));

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookEventStatus::FAILED->value, $events[0]['status']);
        $this->assertSame(WebhookRejectReason::BROKER_ERROR->value, $events[0]['reject_reason']);
        $this->assertStringContainsString('INSUFFICIENT_MARGIN', $events[0]['error_message']);
        $this->assertNotNull($events[0]['created_order_id'], 'order is created before broker call; FAILED preserves it');

        $robot = $this->robotRepo->findById($robotId);
        $this->assertSame(1, (int) $robot['total_errors']);
        $this->assertSame(0, (int) $robot['total_triggered']);
    }

    public function testHappyPathCreatesOrderAndProcessesEvent(): void
    {
        ['token' => $token, 'secret' => $secret, 'robot_id' => $robotId] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $this->service->process($token, $this->validPayload($secret));

        $events = $this->fetchAllEvents();
        $this->assertCount(1, $events);
        $this->assertSame(WebhookEventStatus::PROCESSED->value, $events[0]['status']);
        $this->assertNull($events[0]['reject_reason']);
        $this->assertNotNull($events[0]['created_order_id']);

        // The order exists and is PENDING (placeOrder doesn't auto-execute).
        $stmt = $this->pdo->prepare("SELECT status FROM orders WHERE id = :id");
        $stmt->execute(['id' => $events[0]['created_order_id']]);
        $this->assertSame(OrderStatus::PENDING->value, $stmt->fetchColumn());

        $robot = $this->robotRepo->findById($robotId);
        $this->assertSame(1, (int) $robot['total_triggered']);

        // Connector received the normalized order shape with BUY/EURUSD.
        $this->assertNotNull($this->connector->lastOrder);
        $this->assertSame('EURUSD', $this->connector->lastOrder['symbol']);
        $this->assertSame('BUY', $this->connector->lastOrder['direction']);
    }

    public function testSecretIsNeverPersistedInPayloadRaw(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $this->service->process($token, $this->validPayload($secret));

        $events = $this->fetchAllEvents();
        $raw = json_decode($events[0]['payload_raw'], true);
        $this->assertSame('***', $raw['secret'], 'secret must be redacted before persistence');
    }

    // ── Order management actions (OPEN / MODIFY / CLOSE / CANCEL) ──

    public function testOpenPersistsClientOrderIdAndBrokerOrderId(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $payload = $this->validPayload($secret);
        $payload['client_order_id'] = 'COID-123';
        $this->service->process($token, $payload);

        $events = $this->fetchAllEvents();
        $orderId = (int) $events[0]['created_order_id'];
        $order = $this->orderRepo->findById($orderId);
        $this->assertSame('COID-123', $order['client_order_id']);
        $this->assertStringStartsWith('fake-', (string) $order['broker_order_id']);
    }

    public function testModifyResolvesByClientOrderIdAndCallsConnector(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $open = $this->validPayload($secret);
        $open['client_order_id'] = 'COID-MOD';
        $this->service->process($token, $open);

        $this->service->process($token, [
            'secret' => $secret,
            'alert_id' => 'TV-' . uniqid('', true),
            'action' => 'MODIFY',
            'client_order_id' => 'COID-MOD',
            'symbol' => 'EURUSD',
            'sl_price' => 1.0950,
        ]);

        $events = $this->fetchAllEvents();
        $last = end($events);
        $this->assertSame(WebhookEventStatus::PROCESSED->value, $last['status']);
        $this->assertSame('MODIFY', $last['error_message']); // action tag stored in error_message
        $this->assertSame(1.0950, (float) $this->connector->lastModification['sl_price']);
    }

    public function testModifyUnknownClientOrderIdIsRejected(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $this->service->process($token, [
            'secret' => $secret,
            'alert_id' => 'TV-' . uniqid('', true),
            'action' => 'MODIFY',
            'client_order_id' => 'does-not-exist',
            'sl_price' => 1.0950,
        ]);

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookRejectReason::ORDER_NOT_FOUND->value, end($events)['reject_reason']);
    }

    public function testCancelMarksOrderCancelledLocally(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $open = $this->validPayload($secret);
        $open['client_order_id'] = 'COID-CXL';
        $this->service->process($token, $open);
        $orderId = (int) $this->fetchAllEvents()[0]['created_order_id'];

        $this->service->process($token, [
            'secret' => $secret,
            'alert_id' => 'TV-' . uniqid('', true),
            'action' => 'CANCEL',
            'client_order_id' => 'COID-CXL',
        ]);

        $this->assertNotNull($this->connector->lastCancelOrderId);
        $this->assertSame('CANCELLED', $this->orderRepo->findById($orderId)['status']);
    }

    public function testCloseCallsConnectorAndCancelsLocalOrder(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $open = $this->validPayload($secret);
        $open['client_order_id'] = 'COID-CLS';
        $this->service->process($token, $open);

        $this->service->process($token, [
            'secret' => $secret,
            'alert_id' => 'TV-' . uniqid('', true),
            'action' => 'CLOSE',
            'client_order_id' => 'COID-CLS',
        ]);

        $this->assertNotNull($this->connector->lastClosePositionId);
        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookEventStatus::PROCESSED->value, end($events)['status']);
    }

    public function testUnknownActionIsRejected(): void
    {
        ['token' => $token, 'secret' => $secret] = $this->seedWebhook();
        $this->seedBrokerConnection();

        $this->service->process($token, [
            'secret' => $secret,
            'alert_id' => 'TV-' . uniqid('', true),
            'action' => 'TELEPORT',
            'client_order_id' => 'x',
        ]);

        $events = $this->fetchAllEvents();
        $this->assertSame(WebhookRejectReason::UNSUPPORTED_ACTION->value, end($events)['reject_reason']);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function validPayload(string $secret): array
    {
        return [
            'secret' => $secret,
            'alert_id' => 'TV-' . uniqid('', true),
            'symbol' => 'EURUSD',
            'direction' => 'BUY',
            'order_type' => 'MARKET',
            'entry_price' => 1.1000,
            'size' => 1.0,
            'sl_points' => 0.0050,
            'setup' => ['TradingView'],
        ];
    }

    private function seedWebhook(RobotStatus $robotStatus = RobotStatus::ACTIVE): array
    {
        $robot = $this->robotRepo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'name' => 'Test robot',
            'status' => $robotStatus->value,
        ]);
        $token = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(16));
        $webhook = $this->webhookRepo->create([
            'user_id' => $this->userId,
            'robot_id' => (int) $robot['id'],
            'name' => 'Test webhook',
            'url_token_hash' => hash('sha256', $token),
            'body_secret_hash' => hash('sha256', $secret),
        ]);
        return [
            'token' => $token,
            'secret' => $secret,
            'webhook_id' => (int) $webhook['id'],
            'robot_id' => (int) $robot['id'],
        ];
    }

    private function seedBrokerConnection(ConnectionStatus $status = ConnectionStatus::ACTIVE): void
    {
        $encrypted = $this->crypto->encrypt(['api_key' => 'k', 'api_secret' => 's']);
        $this->connectionRepo->create([
            'user_id' => $this->userId,
            'account_id' => $this->accountId,
            'provider' => BrokerProvider::BINGX->value,
            'status' => $status->value,
            'credentials_encrypted' => $encrypted['ciphertext'],
            'credentials_iv' => $encrypted['iv'],
        ]);
    }

    private function seedUser(): int
    {
        $this->pdo->prepare("INSERT INTO users (email, password, locale) VALUES (:email, :pw, 'fr')")
            ->execute(['email' => 'tv-flow@test.com', 'pw' => password_hash('Test1234!', PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedAccount(int $userId): int
    {
        $this->pdo->prepare(
            "INSERT INTO accounts (user_id, name, account_type, initial_capital, currency)
             VALUES (:u, 'TV Account', 'BROKER_DEMO', 10000, 'USD')"
        )->execute(['u' => $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function fetchAllEvents(): array
    {
        return $this->pdo->query(
            "SELECT * FROM tradingview_alert_events ORDER BY id ASC"
        )->fetchAll();
    }

    private function wipeTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'tradingview_alert_events',
            'tradingview_webhooks',
            'robots',
            'broker_connections',
            'status_history',
            'partial_exits',
            'trades',
            'orders',
            'positions',
            'accounts',
            'refresh_tokens',
            'users',
        ] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function loadEnv(): void
    {
        $envFile = __DIR__ . '/../../../.env';
        if (!file_exists($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (($eq = strpos($line, '=')) === false) continue;
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
                $value = substr($value, 1, -1);
            }
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

/**
 * Test double for ConnectorInterface. By default placeOrder succeeds with a
 * canned external_order_id; setting throwOnNext makes the next call throw the
 * given BrokerOrderException once.
 */
class FakeConnector implements ConnectorInterface
{
    public ?BrokerOrderException $throwOnNext = null;
    public ?array $lastOrder = null;
    public ?string $lastCancelOrderId = null;
    public ?string $lastClosePositionId = null;
    public ?array $lastModification = null;

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        return ['deals' => [], 'cursor' => null, 'raw_count' => 0];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        return ['positions' => [], 'raw_count' => 0];
    }

    public function fetchOpenOrders(array $credentials): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function refreshCredentials(array $credentials): array
    {
        return $credentials;
    }

    public function testConnection(array $credentials): bool
    {
        return true;
    }

    public function fetchBalance(array $credentials): ?float
    {
        return null;
    }

    public function placeOrder(array $credentials, array $order): array
    {
        $this->lastOrder = $order;
        if ($this->throwOnNext) {
            $throw = $this->throwOnNext;
            $this->throwOnNext = null;
            throw $throw;
        }
        return [
            'external_order_id' => 'fake-' . uniqid('', true),
            'status' => 'ACCEPTED',
            'raw' => [],
        ];
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        $this->lastCancelOrderId = $externalOrderId;
        return ['status' => 'CANCELLED', 'raw' => []];
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        $this->lastClosePositionId = $externalPositionId;
        return ['status' => 'CLOSED', 'raw' => []];
    }

    public function modifyOrder(array $credentials, array $modification): array
    {
        $this->lastModification = $modification;
        if ($this->throwOnNext) {
            $throw = $this->throwOnNext;
            $this->throwOnNext = null;
            throw $throw;
        }
        return ['status' => 'MODIFIED', 'raw' => []];
    }
}
