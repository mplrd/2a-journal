<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\CtraderConnector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * cTrader Open API JSON wire format (see docs/22-broker-connectors.md):
 * every frame is {clientMsgId, payloadType:<int>, payload:{…}} — payloadType
 * is the numeric ProtoOAPayloadType code, message fields are nested under
 * `payload`. The FakeWsClient below scripts responses in that exact shape so
 * the tests exercise the real envelope, not a self-consistent fiction.
 */
class CtraderConnectorTest extends TestCase
{
    // ProtoOAPayloadType codes used across the scripted responses.
    private const APP_AUTH_RES     = 2101;
    private const ACCOUNT_AUTH_RES = 2103;
    private const ERROR_RES        = 2142;
    private const HEARTBEAT        = 51;
    private const RECONCILE_RES    = 2125;
    private const TRADER_RES       = 2122;
    private const ORDER_LIST_RES   = 2176;
    private const SYMBOLS_LIST_RES = 2115;
    private const SYMBOL_BY_ID_RES = 2117;
    private const EXECUTION_EVENT  = 2126;

    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'ws_host' => 'demo.ctraderapi.com',
            'ws_port' => 5036,
            'oauth_token_url' => 'https://openapi.ctrader.com/apps/token',
        ];
    }

    /** Wrap a payload in the cTrader JSON envelope. */
    private static function frame(int $payloadType, array $payload = []): array
    {
        return ['payloadType' => $payloadType, 'payload' => $payload];
    }

    private function makeWsStub(array $scriptedFrames): FakeWsClient
    {
        return new FakeWsClient($scriptedFrames);
    }

    // ── Wire format ─────────────────────────────────────────────────

    public function testBuildMessageUsesNumericPayloadTypeAndNestedPayload(): void
    {
        $connector = new CtraderConnector($this->config);

        $decoded = json_decode($connector->buildMessage('ProtoOAApplicationAuthReq', [
            'clientId' => 'test_client_id',
            'clientSecret' => 'test_client_secret',
        ]), true);

        // Numeric payloadType (2100), NOT the string name.
        $this->assertSame(2100, $decoded['payloadType']);
        // Fields nested under payload, not at the top level.
        $this->assertSame('test_client_id', $decoded['payload']['clientId']);
        $this->assertArrayNotHasKey('clientId', $decoded);
        // clientMsgId present and non-empty.
        $this->assertArrayHasKey('clientMsgId', $decoded);
        $this->assertNotSame('', $decoded['clientMsgId']);
    }

    public function testBuildMessageAssignsUniqueClientMsgIds(): void
    {
        $connector = new CtraderConnector($this->config);

        $a = json_decode($connector->buildMessage('ProtoOAApplicationAuthReq', []), true);
        $b = json_decode($connector->buildMessage('ProtoOAAccountAuthReq', []), true);

        $this->assertNotSame($a['clientMsgId'], $b['clientMsgId']);
    }

    // ── Error / heartbeat handling in sendAndReceive ────────────────

    public function testErrorResDetectedByNumericCode(): void
    {
        // The bug this replaces: the old code compared payloadType === the
        // string 'ProtoOAErrorRes', which never matched the numeric 2142 the
        // API actually sends, so real errors were swallowed. Here the order
        // ack is an ErrorRes(2142) → must surface as BROKER_REJECTED.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 1, 'symbolName' => 'EURUSD']]]),
            self::frame(self::ERROR_RES, ['errorCode' => 'NOT_ENOUGH_MONEY', 'description' => 'Insufficient margin']),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        try {
            $connector->placeOrder(
                ['ctid_trader_account_id' => 1, 'access_token' => 't'],
                ['symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 0.10],
            );
            $this->fail('Expected BrokerOrderException');
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->assertSame('BROKER_REJECTED', $e->getProviderCode());
            $this->assertStringContainsString('NOT_ENOUGH_MONEY', $e->getMessage());
        }
    }

    public function testHeartbeatFrameIsSkipped(): void
    {
        // A ProtoHeartbeatEvent (payloadType 51) can arrive interleaved before
        // the real response. sendAndReceive must skip it and keep reading,
        // not mistake it for the answer.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::HEARTBEAT),
            self::frame(self::TRADER_RES, ['trader' => ['balance' => 10053099944, 'moneyDigits' => 8]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $balance = $connector->fetchBalance(['ctid_trader_account_id' => 1, 'access_token' => 't']);

        $this->assertEqualsWithDelta(100.53099944, $balance, 1e-6);
    }

    // ── Reads ───────────────────────────────────────────────────────

    public function testFetchOpenPositionsParsesReconcile(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => [[
                'positionId' => 999,
                'positionStatus' => 'POSITION_STATUS_OPEN',
                'price' => 19200.0,
                'stopLoss' => 19000.0,
                'takeProfit' => 19500.0,
                'tradeData' => ['symbolId' => 22, 'volume' => 50000, 'tradeSide' => 'BUY', 'openTimestamp' => 1700000000000],
            ]]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 22, 'symbolName' => 'GER40']]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchOpenPositions(['ctid_trader_account_id' => 12345, 'access_token' => 'tok']);

        $this->assertSame(1, $result['raw_count']);
        $this->assertCount(1, $result['positions']);
        $this->assertSame('GER40', $result['positions'][0]['symbol']);
        $this->assertSame('BUY', $result['positions'][0]['direction']);
        $this->assertEquals(0.5, $result['positions'][0]['size']);
        $this->assertSame('ctrader_999', $result['positions'][0]['external_id']);
    }

    public function testFetchOpenOrdersFiltersProtectiveClosingOrders(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['order' => [
                [
                    'orderId' => 555,
                    'orderType' => 'LIMIT',
                    'orderStatus' => 'ORDER_STATUS_ACCEPTED',
                    'limitPrice' => 18000.0,
                    'closingOrder' => false,
                    'tradeData' => ['symbolId' => 22, 'volume' => 10000, 'tradeSide' => 'BUY', 'openTimestamp' => 1700000000000],
                ],
                [
                    // Protective SL/TP attached to an open position — already
                    // represented via the position's sl/tp, must be excluded.
                    'orderId' => 556,
                    'orderType' => 'STOP_LOSS_TAKE_PROFIT',
                    'stopPrice' => 17000.0,
                    'closingOrder' => true,
                    'tradeData' => ['symbolId' => 22, 'volume' => 10000, 'tradeSide' => 'SELL', 'openTimestamp' => 1700000000000],
                ],
            ]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 22, 'symbolName' => 'GER40']]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchOpenOrders(['ctid_trader_account_id' => 1, 'access_token' => 't']);

        $this->assertCount(1, $result['orders']);
        $this->assertSame('ctrader_order_555', $result['orders'][0]['external_id']);
        $this->assertSame('GER40', $result['orders'][0]['symbol']);
        $this->assertEquals(18000.0, $result['orders'][0]['entry_price']);
    }

    public function testFetchClosedOrdersMapsTerminalStatuses(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::ORDER_LIST_RES, ['order' => [
                ['orderId' => 700, 'orderStatus' => 'ORDER_STATUS_FILLED'],
                ['orderId' => 701, 'orderStatus' => 'ORDER_STATUS_CANCELLED'],
                ['orderId' => 702, 'orderStatus' => 'ORDER_STATUS_ACCEPTED'], // still live → skipped
            ]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchClosedOrders(['ctid_trader_account_id' => 1, 'access_token' => 't']);

        $this->assertSame(3, $result['raw_count']);
        $this->assertCount(2, $result['orders']);
        $byId = [];
        foreach ($result['orders'] as $o) {
            $byId[$o['external_id']] = $o['final_status'];
        }
        $this->assertSame('EXECUTED', $byId['ctrader_order_700']);
        $this->assertSame('CANCELLED', $byId['ctrader_order_701']);
        $this->assertArrayNotHasKey('ctrader_order_702', $byId);
    }

    public function testFetchBalanceAppliesMoneyDigits(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::TRADER_RES, ['trader' => ['balance' => 10053099944, 'moneyDigits' => 8]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $balance = $connector->fetchBalance(['ctid_trader_account_id' => 1, 'access_token' => 't']);

        $this->assertEqualsWithDelta(100.53099944, $balance, 1e-6);
    }

    public function testFetchBalanceReturnsNullWithoutCredentials(): void
    {
        $connector = new CtraderConnector($this->config);

        $this->assertNull($connector->fetchBalance([]));
    }

    public function testFetchOpenPositionsReturnsEmptyWithoutCredentials(): void
    {
        $connector = new CtraderConnector($this->config);

        $result = $connector->fetchOpenPositions([]);
        $this->assertSame(['positions' => [], 'raw_count' => 0], $result);
    }

    // ── Credentials refresh (HTTP, no WS) ───────────────────────────

    public function testRefreshCredentialsExchangesRefreshToken(): void
    {
        $mockHttp = new MockHandler([
            new Response(200, [], json_encode([
                'accessToken' => 'new_access',
                'refreshToken' => 'new_refresh',
                'tokenType' => 'bearer',
                'expiresIn' => 2592000,
            ])),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mockHttp)]);

        $connector = new CtraderConnector($this->config, null, $httpClient);

        $credentials = [
            'access_token' => 'old_access',
            'refresh_token' => 'old_refresh',
            'ctid_trader_account_id' => 12345,
        ];

        $refreshed = $connector->refreshCredentials($credentials);

        $this->assertSame('new_access', $refreshed['access_token']);
        $this->assertSame('new_refresh', $refreshed['refresh_token']);
        $this->assertSame(12345, $refreshed['ctid_trader_account_id']);
    }

    public function testRefreshCredentialsKeepsOriginalOnNoRefreshToken(): void
    {
        $connector = new CtraderConnector($this->config);

        $credentials = [
            'access_token' => 'some_token',
            'ctid_trader_account_id' => 123,
        ];

        $result = $connector->refreshCredentials($credentials);
        $this->assertSame($credentials, $result);
    }

    // ── Deal normalization (delegates to DealNormalizer) ────────────

    public function testNormalizeDealListResponse(): void
    {
        $rawDeals = [
            [
                'dealId' => 100,
                'positionId' => 50,
                'volume' => 100000,
                'symbolName' => 'GER40',
                'createTimestamp' => 1700000000000,
                'executionTimestamp' => 1700003600000,
                'executionPrice' => 19226.05,
                'tradeSide' => 'SELL',
                'dealStatus' => 'FILLED',
                'commission' => -50,
                'swap' => 0,
                'closePositionDetail' => [
                    'entryPrice' => 19200.00,
                    'grossProfit' => 2605,
                    'swap' => 0,
                    'commission' => -50,
                    'closedVolume' => 100000,
                ],
            ],
            [
                'dealId' => 101,
                'positionId' => 51,
                'volume' => 100000,
                'symbolName' => 'GER40',
                'createTimestamp' => 1700010000000,
                'executionTimestamp' => 1700010000000,
                'executionPrice' => 19300.00,
                'tradeSide' => 'BUY',
                'dealStatus' => 'FILLED',
                'commission' => 0,
                'swap' => 0,
                // No closePositionDetail = opening deal
            ],
        ];

        $connector = new CtraderConnector($this->config);
        $deals = $connector->normalizeDeals($rawDeals);

        $this->assertCount(1, $deals);
        $this->assertSame('GER40', $deals[0]['symbol']);
        $this->assertSame('SELL', $deals[0]['direction']);
        $this->assertEquals(19200.00, $deals[0]['entry_price']);
        $this->assertEquals(19226.05, $deals[0]['exit_price']);
        $this->assertEquals(26.05, $deals[0]['pnl']);
        $this->assertSame('ctrader_50', $deals[0]['external_id']);
    }

    // ── Outbound orders ─────────────────────────────────────────────

    public function testPlaceMarketBuyOrderConvertsLotToVolumeAndReturnsOrderId(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [
                ['symbolId' => 1, 'symbolName' => 'EURUSD'],
                ['symbolId' => 2, 'symbolName' => 'GBPUSD'],
            ]]),
            self::frame(self::EXECUTION_EVENT, ['order' => [
                'orderId' => 1100,
                'orderStatus' => 'ORDER_STATUS_FILLED',
            ]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->placeOrder(
            ['ctid_trader_account_id' => 12345, 'access_token' => 'tok'],
            [
                'symbol' => 'EURUSD',
                'direction' => 'BUY',
                'order_type' => 'MARKET',
                'size' => 0.10,
                'sl_price' => 1.0950,
                'tp_prices' => [1.1050],
            ]
        );

        $this->assertSame('1100', $result['external_order_id']);
        $this->assertSame('ORDER_STATUS_FILLED', $result['status']);

        // The NewOrder payload is nested under `payload`, payloadType numeric.
        $sent = json_decode($ws->sentMessages[3], true);
        $this->assertSame(2106, $sent['payloadType']);
        $this->assertSame(1, $sent['payload']['symbolId']);
        $this->assertSame('MARKET', $sent['payload']['orderType']);
        $this->assertSame('BUY', $sent['payload']['tradeSide']);
        $this->assertSame(10, $sent['payload']['volume']);
        $this->assertSame(1.0950, $sent['payload']['stopLoss']);
    }

    public function testPlaceLimitOrderIncludesLimitPrice(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GBPUSD']]]),
            self::frame(self::EXECUTION_EVENT, ['order' => ['orderId' => 200, 'orderStatus' => 'ORDER_STATUS_ACCEPTED']]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $connector->placeOrder(
            ['ctid_trader_account_id' => 1, 'access_token' => 't'],
            ['symbol' => 'GBPUSD', 'direction' => 'SELL', 'order_type' => 'LIMIT', 'size' => 1.0, 'entry_price' => 1.27],
        );

        $sent = json_decode($ws->sentMessages[3], true);
        $this->assertSame('LIMIT', $sent['payload']['orderType']);
        $this->assertSame('SELL', $sent['payload']['tradeSide']);
        $this->assertSame(1.27, $sent['payload']['limitPrice']);
    }

    public function testUnknownSymbolThrowsBrokerOrderException(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 1, 'symbolName' => 'EURUSD']]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        try {
            $connector->placeOrder(
                ['ctid_trader_account_id' => 1, 'access_token' => 't'],
                ['symbol' => 'XYZNO', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 1.0],
            );
            $this->fail('Expected BrokerOrderException');
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->assertSame('UNKNOWN_SYMBOL', $e->getProviderCode());
        }
    }

    public function testMissingCredentialsRaisesInvalidCredentials(): void
    {
        $connector = new CtraderConnector($this->config);

        try {
            $connector->placeOrder([], ['symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 1]);
            $this->fail('Expected BrokerOrderException');
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->assertSame('INVALID_CREDENTIALS', $e->getProviderCode());
        }
    }

    public function testCancelOrderSendsCancelOrderReq(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::EXECUTION_EVENT, ['order' => ['orderId' => 1, 'orderStatus' => 'ORDER_STATUS_CANCELLED']]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->cancelOrder(['ctid_trader_account_id' => 1, 'access_token' => 't'], '1');
        $this->assertSame('ORDER_STATUS_CANCELLED', $result['status']);

        $sent = json_decode($ws->sentMessages[2], true);
        $this->assertSame(2108, $sent['payloadType']);
        $this->assertSame(1, $sent['payload']['orderId']);
    }

    public function testClosePositionFullAndPartial(): void
    {
        $ws1 = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::EXECUTION_EVENT, ['executionType' => 'ORDER_FILLED']),
        ]);
        $connector1 = new CtraderConnector($this->config, $ws1);
        $full = $connector1->closePosition(['ctid_trader_account_id' => 1, 'access_token' => 't'], 'pos-99');
        $this->assertIsArray($full);
        $sentFull = json_decode($ws1->sentMessages[2], true);
        $this->assertArrayNotHasKey('volume', $sentFull['payload']);

        $ws2 = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::EXECUTION_EVENT, ['executionType' => 'ORDER_PARTIAL_FILL']),
        ]);
        $connector2 = new CtraderConnector($this->config, $ws2);
        $connector2->closePosition(['ctid_trader_account_id' => 1, 'access_token' => 't'], 'pos-99', 0.5);
        $sentPartial = json_decode($ws2->sentMessages[2], true);
        $this->assertSame(50, $sentPartial['payload']['volume']);
    }
}

/**
 * Minimal WsClient stand-in: each `text()` call is paired with the next
 * scripted frame from `receive()`. Sent messages are captured in
 * `$sentMessages`. Scripted frames are the full cTrader JSON envelope
 * ({payloadType:<int>, payload:{…}}), re-encoded on the way out.
 */
class FakeWsClient extends \WebSocket\Client
{
    public array $sentMessages = [];
    private array $frames;
    private int $pos = 0;

    public function __construct(array $scriptedFrames)
    {
        $this->frames = $scriptedFrames;
        // Skip parent constructor — no real socket.
    }

    public function text(string $payload): void
    {
        $this->sentMessages[] = $payload;
    }

    public function receive(): string
    {
        if ($this->pos >= count($this->frames)) {
            throw new \RuntimeException('FakeWsClient ran out of scripted frames');
        }
        return json_encode($this->frames[$this->pos++]);
    }

    public function close(int $status = 1000, string $message = 'ttfn'): void
    {
        // no-op
    }
}
