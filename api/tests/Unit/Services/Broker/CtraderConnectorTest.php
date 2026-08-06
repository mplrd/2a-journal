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
    private const ACCOUNT_LIST_RES = 2150;
    private const ASSET_LIST_RES   = 2113;
    private const DEAL_LIST_RES    = 2134;
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
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 22, 'symbolName' => 'GER40']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 22, 'lotSize' => 100000]]]),
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

    public function testFetchOpenPositionsAsksForTheProtectionOrders(): void
    {
        // ProtoOAReconcileReq.returnProtectionOrders: "If TRUE, then current
        // protection orders are returned separately, otherwise you can use
        // position.stopLoss and position.takeProfit fields." Without it cTrader
        // COLLAPSES every protection into the position's two scalar fields, so
        // a staged plan comes back as a single level and no amount of filtering
        // on order[] can recover it — the orders were never sent.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => []]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $connector->fetchOpenPositions(['ctid_trader_account_id' => 1, 'access_token' => 'tok']);

        $reconcile = null;
        foreach ($ws->sentMessages as $message) {
            $decoded = json_decode($message, true);
            if (($decoded['payloadType'] ?? null) === 2124) {
                $reconcile = $decoded['payload'];
            }
        }

        $this->assertNotNull($reconcile, 'no ProtoOAReconcileReq was sent');
        $this->assertTrue($reconcile['returnProtectionOrders'] ?? false);
    }

    public function testFetchOpenPositionsCollectsStagedTakeProfitOrders(): void
    {
        // cTrader now places partial take profits server-side, as LIMIT closing
        // orders bound to the position. They are the ONLY record of a staged
        // exit plan — ProtoOAPosition.takeProfit holds a single level — and the
        // order path deliberately drops closing orders, so without this they
        // vanished entirely. The STOP_LOSS_TAKE_PROFIT order below is the
        // position's own protective pair, not an objective: it must be ignored.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, [
                'position' => [[
                    'positionId' => 331,
                    'price' => 26386.34,
                    'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'BUY', 'openTimestamp' => 1785907740000],
                ]],
                'order' => [
                    [
                        'orderId' => 900, 'positionId' => 331, 'orderType' => 'LIMIT',
                        'closingOrder' => true, 'limitPrice' => 26600.0,
                        'tradeData' => ['symbolId' => 5, 'volume' => 100, 'tradeSide' => 'SELL'],
                    ],
                    [
                        'orderId' => 901, 'positionId' => 331, 'orderType' => 'LIMIT',
                        'closingOrder' => true, 'limitPrice' => 26450.0,
                        'tradeData' => ['symbolId' => 5, 'volume' => 150, 'tradeSide' => 'SELL'],
                    ],
                    [
                        // The position's own SL/TP pair — not a staged objective.
                        'orderId' => 902, 'positionId' => 331,
                        'orderType' => 'STOP_LOSS_TAKE_PROFIT',
                        'closingOrder' => true, 'stopPrice' => 26200.0,
                        'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'SELL'],
                    ],
                    [
                        // A standalone entry order on another symbol: untouched.
                        'orderId' => 903, 'orderType' => 'LIMIT', 'limitPrice' => 20000.0,
                        'tradeData' => ['symbolId' => 5, 'volume' => 100, 'tradeSide' => 'BUY'],
                    ],
                ],
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40.cash']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchOpenPositions(['ctid_trader_account_id' => 1, 'access_token' => 'tok']);

        $targets = $result['positions'][0]['targets'];
        $this->assertCount(2, $targets, 'only the LIMIT closing orders are objectives');
        // Nearest to the entry first, with the volume each step takes off.
        $this->assertSame(26450.0, $targets[0]['price']);
        $this->assertSame(1.5, $targets[0]['size']);
        $this->assertSame(26600.0, $targets[1]['price']);
        $this->assertSame(1.0, $targets[1]['size']);
    }

    public function testFetchOpenPositionsCollectsProtectiveOrdersCarryingATakeProfit(): void
    {
        // cTrader stages up to five take profit levels per position, each with
        // its own quantity. The public docs never state how those levels come
        // out on the Open API, and reading only LIMIT closing orders brought
        // back nothing on a real account — so a protective order that carries a
        // takeProfit price counts as a level too. Broad on purpose: whichever
        // shape the platform uses, the level is seen. A protective order with
        // only a stop loss stays out.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, [
                'position' => [[
                    'positionId' => 331,
                    'price' => 26386.34,
                    'takeProfit' => 22386.34,
                    'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
                ]],
                'order' => [
                    [
                        // Intermediate level: 500 points below entry on a short.
                        'orderId' => 910, 'positionId' => 331,
                        'orderType' => 'STOP_LOSS_TAKE_PROFIT', 'closingOrder' => true,
                        'takeProfit' => 25886.34,
                        'tradeData' => ['symbolId' => 5, 'volume' => 100, 'tradeSide' => 'BUY'],
                    ],
                    [
                        // Final level on the rest of the position.
                        'orderId' => 911, 'positionId' => 331,
                        'orderType' => 'STOP_LOSS_TAKE_PROFIT', 'closingOrder' => true,
                        'takeProfit' => 22386.34,
                        'tradeData' => ['symbolId' => 5, 'volume' => 150, 'tradeSide' => 'BUY'],
                    ],
                    [
                        // Pure stop loss: protective, but not an objective.
                        'orderId' => 912, 'positionId' => 331,
                        'orderType' => 'STOP_LOSS_TAKE_PROFIT', 'closingOrder' => true,
                        'stopLoss' => 26619.42,
                        'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'BUY'],
                    ],
                ],
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40.cash']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchOpenPositions(['ctid_trader_account_id' => 1, 'access_token' => 'tok']);

        $targets = $result['positions'][0]['targets'];
        $this->assertCount(2, $targets, 'the stop-loss-only order is not an objective');
        $this->assertSame(25886.34, $targets[0]['price']);
        $this->assertSame(1.0, $targets[0]['size']);
        $this->assertSame(22386.34, $targets[1]['price']);
        $this->assertSame(1.5, $targets[1]['size']);
    }

    public function testFetchOpenPositionsAcceptsAProtectionOrderWithoutTheClosingFlag(): void
    {
        // Requiring closingOrder was a guess, and returnProtectionOrders still
        // brought back nothing usable on a real account. `closingOrder` is
        // documented for orders the user places to close part of a position;
        // nothing promises the platform sets it on the protections it returns
        // separately. The link that genuinely matters is positionId — an order
        // bound to a position and carrying a take profit price IS a level.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, [
                'position' => [[
                    'positionId' => 331,
                    'price' => 26386.34,
                    'takeProfit' => 22386.34,
                    'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
                ]],
                'order' => [[
                    'orderId' => 930, 'positionId' => 331,
                    'orderType' => 'STOP_LOSS_TAKE_PROFIT',
                    // no closingOrder flag at all
                    'takeProfit' => 25886.34,
                    'tradeData' => ['symbolId' => 5, 'volume' => 100, 'tradeSide' => 'BUY'],
                ]],
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40.cash']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchOpenPositions(['ctid_trader_account_id' => 1, 'access_token' => 'tok']);

        $targets = $result['positions'][0]['targets'];
        $this->assertCount(2, $targets);
        $this->assertSame(25886.34, $targets[0]['price']);
    }

    public function testFetchOpenPositionsDoesNotCountTheSameLevelTwice(): void
    {
        // The position's own takeProfit mirrors one of the staged levels. It is
        // only a fallback, and a level reported by two orders at one price is
        // still one level.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, [
                'position' => [[
                    'positionId' => 331,
                    'price' => 26386.34,
                    'takeProfit' => 22386.34,
                    'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
                ]],
                'order' => [
                    [
                        'orderId' => 920, 'positionId' => 331, 'orderType' => 'LIMIT',
                        'closingOrder' => true, 'limitPrice' => 22386.34,
                        'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'BUY'],
                    ],
                    [
                        'orderId' => 921, 'positionId' => 331,
                        'orderType' => 'STOP_LOSS_TAKE_PROFIT', 'closingOrder' => true,
                        'takeProfit' => 22386.34,
                        'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'BUY'],
                    ],
                ],
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40.cash']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchOpenPositions(['ctid_trader_account_id' => 1, 'access_token' => 'tok']);

        $this->assertCount(1, $result['positions'][0]['targets']);
    }

    public function testFetchOpenPositionsIgnoresClosingOrdersOfOtherPositions(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, [
                'position' => [[
                    'positionId' => 331,
                    'price' => 26386.34,
                    'tradeData' => ['symbolId' => 5, 'volume' => 250, 'tradeSide' => 'BUY', 'openTimestamp' => 1785907740000],
                ]],
                'order' => [[
                    'orderId' => 950, 'positionId' => 999, 'orderType' => 'LIMIT',
                    'closingOrder' => true, 'limitPrice' => 26600.0,
                    'tradeData' => ['symbolId' => 5, 'volume' => 100, 'tradeSide' => 'SELL'],
                ]],
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40.cash']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchOpenPositions(['ctid_trader_account_id' => 1, 'access_token' => 'tok']);

        $this->assertSame([], $result['positions'][0]['targets']);
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
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 22, 'symbolName' => 'GER40']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 22, 'lotSize' => 100000]]]),
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
        // The closing deal sold, so the position it closed was a BUY.
        $this->assertSame('BUY', $deals[0]['direction']);
        $this->assertEquals(19200.00, $deals[0]['entry_price']);
        $this->assertEquals(19226.05, $deals[0]['exit_price']);
        // 26.05 gross less the 0.50 commission carried by the closing detail.
        $this->assertEquals(25.55, $deals[0]['pnl']);
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

    // ── Account discovery (ProtoOAGetAccountListByAccessTokenReq) ───

    public function testFetchAccountsListsEveryAccountBehindTheAccessToken(): void
    {
        // The connect form used to ask the user to type ctidTraderAccountId by
        // hand — a number the cTrader platform never shows (it displays
        // traderLogin instead), so a real user filled in the login and got
        // "account not found". Discover them from the token instead.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [
                    ['ctidTraderAccountId' => 42111, 'traderLogin' => 1234567, 'isLive' => true],
                    ['ctidTraderAccountId' => 42112, 'traderLogin' => 7654321, 'isLive' => false],
                ],
            ]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $accounts = $connector->fetchAccounts([
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'tok',
        ]);

        // Only the account list is scripted here, so per-account enrichment
        // cannot run: the entries keep their base shape with empty extras
        // rather than disappearing or being flagged disabled.
        $this->assertSame([
            [
                'ctid_trader_account_id' => 42111,
                'trader_login' => '1234567',
                'is_live' => true,
                'broker_name' => null,
                'balance' => null,
                'currency' => null,
                'is_disabled' => false,
                'disabled_reason' => null,
                'details' => ['ctidTraderAccountId' => 42111, 'traderLogin' => 1234567, 'isLive' => true],
            ],
            [
                'ctid_trader_account_id' => 42112,
                'trader_login' => '7654321',
                'is_live' => false,
                'broker_name' => null,
                'balance' => null,
                'currency' => null,
                'is_disabled' => false,
                'disabled_reason' => null,
                'details' => ['ctidTraderAccountId' => 42112, 'traderLogin' => 7654321, 'isLive' => false],
            ],
        ], $accounts);

        // Request carries the access token under the right payload type.
        $sent = json_decode($ws->sentMessages[1], true);
        $this->assertSame(2149, $sent['payloadType']);
        $this->assertSame('tok', $sent['payload']['accessToken']);
    }

    public function testFetchAccountsPassesThroughEveryFieldTheApiReturns(): void
    {
        // A real user got a long list of accounts and could not find the ones
        // FTMO shows them; picking one returned RET_ACCOUNT_DISABLED, i.e. the
        // list includes archived accounts. Mapping only three fields threw away
        // whatever distinguishes them, so keep everything and let the UI decide.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [[
                    'ctidTraderAccountId' => 42111,
                    'traderLogin' => 1234567,
                    'isLive' => true,
                    'brokerTitleShort' => 'FTMO',
                    'lastClosingDealTimestamp' => 1750000000000,
                    'someFutureField' => 'kept anyway',
                ]],
            ]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $account = $connector->fetchAccounts(['access_token' => 'tok'])[0];

        // Mapped fields stay stable for the callers that rely on them.
        $this->assertSame(42111, $account['ctid_trader_account_id']);
        $this->assertSame('1234567', $account['trader_login']);
        $this->assertTrue($account['is_live']);
        // Everything else survives verbatim, including fields we do not know about.
        $this->assertSame('FTMO', $account['details']['brokerTitleShort']);
        $this->assertSame('kept anyway', $account['details']['someFutureField']);
        $this->assertSame(1750000000000, $account['details']['lastClosingDealTimestamp']);
    }

    public function testFetchAccountsEnrichesEachEntryWithItsBalanceAndCurrency(): void
    {
        // brokerTitleShort alone does not separate several accounts at the same
        // prop firm — they all read "FTMO". What tells them apart is the size:
        // "FTMO 80 000 € — 1234567". Balance and deposit currency live in
        // ProtoOATrader, one account auth away, so fetch them per account.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [
                    ['ctidTraderAccountId' => 42111, 'traderLogin' => 1234567, 'isLive' => true, 'brokerTitleShort' => 'FTMO'],
                ],
            ]),
            self::frame(self::ACCOUNT_AUTH_RES),
            // Money is an integer scaled by moneyDigits: 8000000 / 10^2 = 80000.
            self::frame(self::TRADER_RES, ['trader' => [
                'balance' => 8000000,
                'moneyDigits' => 2,
                'depositAssetId' => 3,
                'leverageInCents' => 10000,
            ]]),
            self::frame(self::ASSET_LIST_RES, ['asset' => [
                ['assetId' => 3, 'name' => 'EUR'],
                ['assetId' => 4, 'name' => 'USD'],
            ]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $account = $connector->fetchAccounts(['access_token' => 'tok'])[0];

        $this->assertSame(80000.0, $account['balance']);
        $this->assertSame('EUR', $account['currency']);
        $this->assertSame('FTMO', $account['broker_name']);
        $this->assertFalse($account['is_disabled']);
        // Trader fields join the raw panel too, so an unknown one still shows.
        $this->assertSame(10000, $account['details']['leverageInCents']);
    }

    public function testFetchAccountsFlagsAccountsTheBrokerRefusesToAuthenticate(): void
    {
        // This is the real answer to "which ones are archived": nothing in
        // ProtoOACtidTraderAccount marks them, but account auth rejects them
        // with RET_ACCOUNT_DISABLED. One refusal must not abort the others.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [
                    ['ctidTraderAccountId' => 42111, 'traderLogin' => 1234567, 'isLive' => true, 'brokerTitleShort' => 'FTMO'],
                    ['ctidTraderAccountId' => 42112, 'traderLogin' => 7654321, 'isLive' => true, 'brokerTitleShort' => 'FTMO'],
                ],
            ]),
            self::frame(self::ERROR_RES, ['errorCode' => 'RET_ACCOUNT_DISABLED', 'description' => 'Account is disabled']),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::TRADER_RES, ['trader' => ['balance' => 2500000, 'moneyDigits' => 2, 'depositAssetId' => 4]]),
            self::frame(self::ASSET_LIST_RES, ['asset' => [['assetId' => 4, 'name' => 'USD']]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $accounts = $connector->fetchAccounts(['access_token' => 'tok']);

        $this->assertTrue($accounts[0]['is_disabled']);
        $this->assertStringContainsString('RET_ACCOUNT_DISABLED', $accounts[0]['disabled_reason']);
        $this->assertNull($accounts[0]['balance']);
        // The next account is still enriched — the loop carries on.
        $this->assertFalse($accounts[1]['is_disabled']);
        $this->assertSame(25000.0, $accounts[1]['balance']);
        $this->assertSame('USD', $accounts[1]['currency']);
    }

    public function testFetchAccountsKeepsAnAccountWhoseAuthFailedForANonAccountReason(): void
    {
        // Not every broker error means "this account is unusable".
        // INVALID_REQUEST means we sent something malformed — and since a
        // disabled account is now hidden from the picker, treating it as a
        // refusal makes perfectly good accounts vanish with no explanation.
        // Fail safe: only account-scoped codes hide an account, anything else
        // leaves it listed so the user can still try it.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [
                    ['ctidTraderAccountId' => 42111, 'traderLogin' => 1234567, 'isLive' => true],
                    ['ctidTraderAccountId' => 42112, 'traderLogin' => 7654321, 'isLive' => true],
                ],
            ]),
            self::frame(self::ERROR_RES, ['errorCode' => 'INVALID_REQUEST', 'description' => 'Unexpected IOException']),
            self::frame(self::ERROR_RES, ['errorCode' => 'RET_ACCOUNT_DISABLED', 'description' => 'Account is disabled']),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $accounts = $connector->fetchAccounts(['access_token' => 'tok']);

        // Malformed request: nothing learned about the account, keep it.
        $this->assertFalse($accounts[0]['is_disabled']);
        $this->assertNull($accounts[0]['disabled_reason']);
        // Account-scoped refusal: that one really is unusable.
        $this->assertTrue($accounts[1]['is_disabled']);
        $this->assertSame('RET_ACCOUNT_DISABLED', $accounts[1]['disabled_reason']);
    }

    public function testFetchAccountsRequestsTheAssetListOncePerBroker(): void
    {
        // Asset ids are broker-scoped, so two accounts at the same broker share
        // one list. Re-requesting it per account would triple the round trips
        // on a long list for nothing.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [
                    ['ctidTraderAccountId' => 42111, 'traderLogin' => 1234567, 'isLive' => true, 'brokerTitleShort' => 'FTMO'],
                    ['ctidTraderAccountId' => 42112, 'traderLogin' => 7654321, 'isLive' => true, 'brokerTitleShort' => 'FTMO'],
                ],
            ]),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::TRADER_RES, ['trader' => ['balance' => 8000000, 'moneyDigits' => 2, 'depositAssetId' => 3]]),
            self::frame(self::ASSET_LIST_RES, ['asset' => [['assetId' => 3, 'name' => 'EUR']]]),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::TRADER_RES, ['trader' => ['balance' => 1000000, 'moneyDigits' => 2, 'depositAssetId' => 3]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $accounts = $connector->fetchAccounts(['access_token' => 'tok']);

        $this->assertSame('EUR', $accounts[0]['currency']);
        $this->assertSame('EUR', $accounts[1]['currency']);

        $assetListRequests = array_filter(
            $ws->sentMessages,
            fn (string $m) => (json_decode($m, true)['payloadType'] ?? null) === 2112,
        );
        $this->assertCount(1, $assetListRequests);
    }

    public function testFetchAccountsKeepsTheEntryWhenEnrichmentBreaksDown(): void
    {
        // A transport failure is not a disabled account. Saying "archived"
        // because the socket died would send the user chasing a phantom, so
        // only a broker refusal sets the flag; anything else leaves it unknown.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [
                    ['ctidTraderAccountId' => 42111, 'traderLogin' => 1234567, 'isLive' => true, 'brokerTitleShort' => 'FTMO'],
                ],
            ]),
            // Nothing scripted for the account auth: the stub throws.
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $accounts = $connector->fetchAccounts(['access_token' => 'tok']);

        $this->assertCount(1, $accounts);
        $this->assertSame('1234567', $accounts[0]['trader_login']);
        $this->assertSame('FTMO', $accounts[0]['broker_name']);
        $this->assertNull($accounts[0]['balance']);
        $this->assertFalse($accounts[0]['is_disabled']);
    }

    // ── fetchBalance currency ───────────────────────────────────────

    public function testFetchBalanceReportsTheCurrencyItIsDenominatedIn(): void
    {
        // BrokerSyncService reads the balance currency through an optional
        // getBalanceCurrency(), which only BingX implemented — so a cTrader
        // sync always stored a null currency and the account could never be
        // told its balance was in a different one from its own setting.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::TRADER_RES, ['trader' => [
                'balance' => 8000000,
                'moneyDigits' => 2,
                'depositAssetId' => 3,
            ]]),
            self::frame(self::ASSET_LIST_RES, ['asset' => [
                ['assetId' => 3, 'name' => 'USD'],
                ['assetId' => 4, 'name' => 'EUR'],
            ]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $balance = $connector->fetchBalance([
            'ctid_trader_account_id' => 42111,
            'access_token' => 'tok',
        ]);

        $this->assertSame(80000.0, $balance);
        $this->assertSame('USD', $connector->getBalanceCurrency());
    }

    public function testFetchBalanceLeavesTheCurrencyNullWhenItCannotBeResolved(): void
    {
        // A missing asset list must not cost the balance: reporting no currency
        // is honest, inventing one would let the UI claim a false match.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::TRADER_RES, ['trader' => ['balance' => 8000000, 'moneyDigits' => 2]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $balance = $connector->fetchBalance([
            'ctid_trader_account_id' => 42111,
            'access_token' => 'tok',
        ]);

        $this->assertSame(80000.0, $balance);
        $this->assertNull($connector->getBalanceCurrency());
    }

    // ── fetchDeals ──────────────────────────────────────────────────

    public function testFetchDealsSendsSymbolIdsAsAJsonArrayNotAnObject(): void
    {
        // array_unique() preserves keys, so deduplicating symbol ids from deals
        // that repeat a symbol leaves gaps (0, 2, 4). json_encode turns a
        // gapped array into an OBJECT, and cTrader's repeated int64 field then
        // fails with "Couldn't parse integer: For input string: {". It only
        // bites once two deals share a symbol, which is the normal case — hence
        // a first real sync blowing up on code that looked fine.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => []]),
            self::frame(self::DEAL_LIST_RES, [
                'deal' => [
                    ['dealId' => 1, 'symbolId' => 1, 'executionTimestamp' => 1750000000000],
                    ['dealId' => 2, 'symbolId' => 1, 'executionTimestamp' => 1750000001000],
                    ['dealId' => 3, 'symbolId' => 22, 'executionTimestamp' => 1750000002000],
                    ['dealId' => 4, 'symbolId' => 1, 'executionTimestamp' => 1750000003000],
                    ['dealId' => 5, 'symbolId' => 41, 'executionTimestamp' => 1750000004000],
                ],
                'hasMore' => false,
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [
                ['symbolId' => 1, 'symbolName' => 'EURUSD'],
                ['symbolId' => 22, 'symbolName' => 'GBPUSD'],
                ['symbolId' => 41, 'symbolName' => 'XAUUSD'],
            ]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [
                ['symbolId' => 1, 'lotSize' => 10000000],
                ['symbolId' => 22, 'lotSize' => 10000000],
                ['symbolId' => 41, 'lotSize' => 10000],
            ]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $connector->fetchDeals([
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 'tok',
            'ctid_trader_account_id' => 43210987,
        ]);

        $symbolRequest = null;
        foreach ($ws->sentMessages as $message) {
            // Decoded as objects, so a JSON array stays a PHP array and a JSON
            // object becomes stdClass — the whole point of this assertion.
            $decoded = json_decode($message);
            if (($decoded->payloadType ?? null) === 2116) {
                $symbolRequest = $decoded;
            }
        }

        $this->assertNotNull($symbolRequest, 'no ProtoOASymbolByIdReq was sent');
        $this->assertIsArray($symbolRequest->payload->symbolId);
        $this->assertSame([1, 22, 41], $symbolRequest->payload->symbolId);
    }

    public function testFetchDealsTakesSymbolNamesFromTheLightSymbolList(): void
    {
        // ProtoOASymbolByIdRes carries ProtoOASymbol, which has NO symbolName
        // field at all — only ProtoOALightSymbol (ProtoOASymbolsListRes) does.
        // Reading the name off the wrong message is why every synced trade came
        // back labelled "SYM_331". The stub mirrors the real API: the by-id
        // response has lotSize and no name.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => []]),
            self::frame(self::DEAL_LIST_RES, [
                'deal' => [[
                    'dealId' => 1,
                    'positionId' => 90,
                    'symbolId' => 331,
                    'volume' => 150,
                    'tradeSide' => 'BUY',
                    'createTimestamp' => 1785916872000,
                    'executionTimestamp' => 1785916872000,
                    'executionPrice' => 26300.0,
                    'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 12960, 'closedVolume' => 150],
                ]],
                'hasMore' => false,
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [
                ['symbolId' => 331, 'symbolName' => 'GER40'],
            ]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [
                ['symbolId' => 331, 'lotSize' => 100, 'digits' => 2],
            ]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchDeals([
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 43210987,
        ]);

        $this->assertSame('GER40', $result['deals'][0]['symbol']);
        // lotSize 100 cents → 150 cents of volume is 1.5 contracts, not 0.0015.
        $this->assertEquals(1.5, $result['deals'][0]['size']);
        // tradeSide BUY closed the position → it was a SELL.
        $this->assertSame('SELL', $result['deals'][0]['direction']);
    }

    public function testFetchDealsNamesArchivedSymbolsFromTheByIdResponse(): void
    {
        // ProtoOASymbolsListReq omits archived symbols unless asked, so a
        // symbol the broker has retired is absent from the light list and can
        // never be named from it — one instrument stays SYM_<id> while every
        // other one on the same account resolves. ProtoOASymbolByIdRes, which
        // we already call for lotSize, returns archivedSymbol[] alongside
        // symbol[], and ProtoOAArchivedSymbol carries the name under `name`
        // (not `symbolName`). No extra round trip needed.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => []]),
            self::frame(self::DEAL_LIST_RES, [
                'deal' => [
                    [
                        'dealId' => 1, 'positionId' => 90, 'symbolId' => 331, 'volume' => 150,
                        'tradeSide' => 'BUY',
                        'createTimestamp' => 1785916872000, 'executionTimestamp' => 1785916872000,
                        'executionPrice' => 26300.0,
                        'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 12960, 'closedVolume' => 150],
                    ],
                    [
                        'dealId' => 2, 'positionId' => 91, 'symbolId' => 5, 'volume' => 100,
                        'tradeSide' => 'BUY',
                        'createTimestamp' => 1785917000000, 'executionTimestamp' => 1785917000000,
                        'executionPrice' => 20000.0,
                        'closePositionDetail' => ['entryPrice' => 20100.0, 'grossProfit' => 10000, 'closedVolume' => 100],
                    ],
                ],
                'hasMore' => false,
            ]),
            // The light list knows 5 but not the retired 331.
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [
                ['symbolId' => 5, 'symbolName' => 'US100.cash'],
            ]]),
            self::frame(self::SYMBOL_BY_ID_RES, [
                'symbol' => [['symbolId' => 5, 'lotSize' => 100]],
                'archivedSymbol' => [['symbolId' => 331, 'name' => 'GER40']],
            ]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchDeals([
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 1,
        ]);

        $names = array_column($result['deals'], 'symbol');
        sort($names);
        $this->assertSame(['GER40', 'US100.cash'], $names);
    }

    public function testFetchDealsDatesAPositionFromItsOpeningDeal(): void
    {
        // The deal list carries the opening deal (no closePositionDetail) as
        // well as the closing one. Without wiring the two together, opened_at
        // fell back to the closing deal's own createTimestamp and every trade
        // looked like it had opened at the instant it closed.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => []]),
            self::frame(self::DEAL_LIST_RES, [
                'deal' => [
                    [
                        'dealId' => 10, 'positionId' => 331, 'symbolId' => 5, 'volume' => 250,
                        'tradeSide' => 'SELL',
                        'createTimestamp' => 1785907740000, 'executionTimestamp' => 1785907740000,
                        'executionPrice' => 26386.34,
                    ],
                    [
                        'dealId' => 11, 'positionId' => 331, 'symbolId' => 5, 'volume' => 250,
                        'tradeSide' => 'BUY',
                        'createTimestamp' => 1785916872000, 'executionTimestamp' => 1785916872000,
                        'executionPrice' => 26300.0,
                        'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 12960, 'closedVolume' => 250],
                    ],
                ],
                'hasMore' => false,
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchDeals([
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 1,
        ]);

        $this->assertCount(1, $result['deals']);
        $this->assertSame('2026-08-05 05:29:00', $result['deals'][0]['opened_at']);
        $this->assertSame('2026-08-05 08:01:12', $result['deals'][0]['closed_at']);
    }

    public function testFetchDealsHoldsBackClosuresOfStillOpenPositions(): void
    {
        // A TP1 is a partial close: the position stays open. Emitting its deal
        // as a closed trade created a second, phantom position — a "BUY with an
        // instant profit" sitting next to the still-open SELL. Those deals must
        // leave fetchDeals as partial exits on the live position instead.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => [[
                'positionId' => 331,
                'price' => 26386.34,
                'tradeData' => ['symbolId' => 5, 'volume' => 150, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
            ]]]),
            self::frame(self::DEAL_LIST_RES, [
                'deal' => [
                    // TP1 on the still-open position 331.
                    [
                        'dealId' => 11, 'positionId' => 331, 'symbolId' => 5, 'volume' => 100,
                        'tradeSide' => 'BUY',
                        'createTimestamp' => 1785916872000, 'executionTimestamp' => 1785916872000,
                        'executionPrice' => 26300.0,
                        'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 10327, 'closedVolume' => 100],
                    ],
                    // A genuinely finished position — must still come through.
                    [
                        'dealId' => 12, 'positionId' => 400, 'symbolId' => 5, 'volume' => 100,
                        'tradeSide' => 'SELL',
                        'createTimestamp' => 1785917000000, 'executionTimestamp' => 1785917000000,
                        'executionPrice' => 26500.0,
                        'closePositionDetail' => ['entryPrice' => 26400.0, 'grossProfit' => 10000, 'closedVolume' => 100],
                    ],
                ],
                'hasMore' => false,
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->fetchDeals([
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 1,
        ]);

        $externalIds = array_column($result['deals'], 'external_id');
        $this->assertSame(['ctrader_400'], $externalIds);
        // Both deals were still fetched — raw_count reports the wire truth.
        $this->assertSame(2, $result['raw_count']);
    }

    public function testFetchOpenPositionsAttachesThePartialExitsSeenInTheSameSync(): void
    {
        // Companion to the test above: what fetchDeals held back has to
        // resurface here, so the journal records the TP1 as a partial exit of
        // the open position and rebuilds the original size (1.5 left + 1 taken).
        $dealsWs = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => [[
                'positionId' => 331,
                'price' => 26386.34,
                'tradeData' => ['symbolId' => 5, 'volume' => 150, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
            ]]]),
            self::frame(self::DEAL_LIST_RES, [
                'deal' => [[
                    'dealId' => 11, 'positionId' => 331, 'symbolId' => 5, 'volume' => 100,
                    'tradeSide' => 'BUY',
                    'createTimestamp' => 1785916872000, 'executionTimestamp' => 1785916872000,
                    'executionPrice' => 26300.0,
                    'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 10327, 'closedVolume' => 100],
                ]],
                'hasMore' => false,
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
            // Second session (fetchOpenPositions). In production each fetch
            // opens its own socket; the stub is shared, so its frames follow
            // on. No symbols list this time round: the name map is memoised for
            // the sync run rather than re-pulling the broker's whole universe.
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => [[
                'positionId' => 331,
                'price' => 26386.34,
                'tradeData' => ['symbolId' => 5, 'volume' => 150, 'tradeSide' => 'SELL', 'openTimestamp' => 1785907740000],
            ]]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $dealsWs);
        $credentials = [
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 1,
        ];

        $connector->fetchDeals($credentials);
        $open = $connector->fetchOpenPositions($credentials);

        $position = $open['positions'][0];
        $this->assertSame('ctrader_331', $position['external_id']);
        $this->assertSame('SELL', $position['direction']);
        $this->assertEquals(2.5, $position['size']);
        $this->assertEquals(1.5, $position['remaining_size']);
        $this->assertCount(1, $position['exits']);
        $this->assertSame('ctrader_deal_11', $position['exits'][0]['external_id']);
        $this->assertEquals(103.27, $position['exits'][0]['pnl']);
        $this->assertEquals(1.0, $position['exits'][0]['size']);
    }

    public function testFetchDealsEmitsAUtcCursorNotAJournalDatetime(): void
    {
        // The cursor is a PROTOCOL value: it goes back out as an epoch through
        // strtotime(), which reads it in the server's timezone. Deriving it
        // from the normalized closed_at — a local wall-clock string since the
        // journal stores display time — made a deal closed at 16:30 in Paris
        // come back as 16:30 UTC, two hours into the future. The other three
        // connectors all track raw API values for exactly this reason.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => []]),
            self::frame(self::DEAL_LIST_RES, [
                'deal' => [[
                    'dealId' => 1, 'positionId' => 90, 'symbolId' => 5, 'volume' => 100,
                    'tradeSide' => 'BUY',
                    'createTimestamp' => 1785916872000,
                    'executionTimestamp' => 1785916872000, // 2026-08-05 08:01:12 UTC
                    'executionPrice' => 26300.0,
                    'closePositionDetail' => ['entryPrice' => 26386.34, 'grossProfit' => 10327, 'closedVolume' => 100],
                ]],
                'hasMore' => false,
            ]),
            self::frame(self::SYMBOLS_LIST_RES, ['symbol' => [['symbolId' => 5, 'symbolName' => 'GER40']]]),
            self::frame(self::SYMBOL_BY_ID_RES, ['symbol' => [['symbolId' => 5, 'lotSize' => 100]]]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);
        $connector->setTimezone('Europe/Paris');

        $result = $connector->fetchDeals([
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 1,
        ]);

        // The journal row is local, the cursor stays UTC.
        $this->assertSame('2026-08-05 10:01:12', $result['deals'][0]['closed_at']);
        $this->assertSame('2026-08-05 08:01:12', $result['cursor']);
    }

    public function testFetchDealsIgnoresACursorThatLandsInTheFuture(): void
    {
        // Recovery path, and it is not optional: a connection whose stored
        // cursor is ahead of now would send fromTimestamp > toTimestamp,
        // cTrader answers INCORRECT_BOUNDARIES, the sync fails — so the cursor
        // is never rewritten and the connection stays broken for good. An
        // unusable cursor falls back to the default window instead.
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => []]),
            self::frame(self::DEAL_LIST_RES, ['deal' => [], 'hasMore' => false]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $connector->fetchDeals([
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 1,
        ], gmdate('Y-m-d H:i:s', time() + 7200)); // two hours ahead

        $dealRequest = null;
        foreach ($ws->sentMessages as $message) {
            $decoded = json_decode($message, true);
            if (($decoded['payloadType'] ?? null) === 2133) {
                $dealRequest = $decoded['payload'];
            }
        }

        $this->assertNotNull($dealRequest);
        $this->assertLessThan(
            $dealRequest['toTimestamp'],
            $dealRequest['fromTimestamp'],
            'a window that starts after it ends is rejected by cTrader',
        );
    }

    public function testFetchDealsWidensTheWindowToCoverStillOpenPositions(): void
    {
        // The sync cursor only ever moves forward, so a position opened weeks
        // before it would fall outside the window and its partial exits would
        // be lost the next time round. The window is pulled back to the oldest
        // still-open position instead.
        $openedAtMs = 1751000000000; // well before the cursor below
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
            self::frame(self::RECONCILE_RES, ['position' => [[
                'positionId' => 331,
                'price' => 26386.34,
                'tradeData' => ['symbolId' => 5, 'volume' => 150, 'tradeSide' => 'SELL', 'openTimestamp' => $openedAtMs],
            ]]]),
            self::frame(self::DEAL_LIST_RES, ['deal' => [], 'hasMore' => false]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $connector->fetchDeals([
            'client_id' => 'a', 'client_secret' => 'b',
            'access_token' => 'tok', 'ctid_trader_account_id' => 1,
        ], '2026-08-04 00:00:00');

        $dealRequest = null;
        foreach ($ws->sentMessages as $message) {
            $decoded = json_decode($message, true);
            if (($decoded['payloadType'] ?? null) === 2133) {
                $dealRequest = $decoded;
            }
        }

        $this->assertNotNull($dealRequest, 'no ProtoOADealListReq was sent');
        $this->assertSame($openedAtMs, $dealRequest['payload']['fromTimestamp']);
    }

    public function testFetchAccountsToleratesAMissingIsLiveFlag(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [['ctidTraderAccountId' => 7, 'traderLogin' => 99]],
            ]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $accounts = $connector->fetchAccounts(['access_token' => 'tok']);

        $this->assertFalse($accounts[0]['is_live']);
    }

    public function testFetchAccountsSkipsEntriesWithoutAnId(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_LIST_RES, [
                'ctidTraderAccount' => [
                    ['traderLogin' => 1],
                    ['ctidTraderAccountId' => 8, 'traderLogin' => 2, 'isLive' => true],
                ],
            ]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $accounts = $connector->fetchAccounts(['access_token' => 'tok']);

        $this->assertCount(1, $accounts);
        $this->assertSame(8, $accounts[0]['ctid_trader_account_id']);
    }

    public function testFetchAccountsPropagatesTheBrokerError(): void
    {
        // A wrong clientSecret must surface as itself here too, otherwise the
        // "load my accounts" button fails silently.
        $ws = $this->makeWsStub([
            self::frame(self::ERROR_RES, [
                'errorCode' => 'CH_CLIENT_AUTH_FAILURE',
                'description' => 'wrong clientSecret',
            ]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $this->expectExceptionMessageMatches('/CH_CLIENT_AUTH_FAILURE/');
        $connector->fetchAccounts(['client_id' => 'a', 'client_secret' => 'bad', 'access_token' => 'tok']);
    }

    // ── Per-connection endpoint (live vs demo) ──────────────────────

    public function testEndpointFollowsTheConnectionEnvironment(): void
    {
        // The host used to come from the global CTRADER_WS_HOST env var, so a
        // demo account and a live account could not coexist across users.
        $connector = new CtraderConnector($this->config);

        $this->assertSame(
            'wss://live.ctraderapi.com:5036',
            $connector->resolveWsUrl(['environment' => 'LIVE']),
        );
        $this->assertSame(
            'wss://demo.ctraderapi.com:5036',
            $connector->resolveWsUrl(['environment' => 'DEMO']),
        );
    }

    public function testEndpointFallsBackToConfiguredHostWhenEnvironmentIsAbsent(): void
    {
        // Connections created before the selector shipped carry no environment
        // key — they keep resolving through the configured host.
        $connector = new CtraderConnector($this->config);

        $this->assertSame('wss://demo.ctraderapi.com:5036', $connector->resolveWsUrl([]));
    }

    public function testEndpointIgnoresAnUnknownEnvironmentValue(): void
    {
        $connector = new CtraderConnector($this->config);

        $this->assertSame('wss://demo.ctraderapi.com:5036', $connector->resolveWsUrl(['environment' => 'STAGING']));
    }

    // ── testConnection surfaces the broker's reason ─────────────────

    public function testTestConnectionExposesTheBrokerErrorMessage(): void
    {
        // Without this, a rotated clientSecret only ever produced a bare
        // "false" and the user had no idea which credential was rejected.
        $ws = $this->makeWsStub([
            self::frame(self::ERROR_RES, [
                'errorCode' => 'CH_CLIENT_AUTH_FAILURE',
                'description' => 'wrong clientSecret',
            ]),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $ok = $connector->testConnection([
            'client_id' => 'a',
            'client_secret' => 'wrong',
            'access_token' => 't',
            'ctid_trader_account_id' => 1,
        ]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('CH_CLIENT_AUTH_FAILURE', $connector->getLastTestError());
        $this->assertStringContainsString('wrong clientSecret', $connector->getLastTestError());
    }

    public function testTestConnectionClearsTheErrorOnSuccess(): void
    {
        $ws = $this->makeWsStub([
            self::frame(self::APP_AUTH_RES),
            self::frame(self::ACCOUNT_AUTH_RES),
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $this->assertTrue($connector->testConnection([
            'client_id' => 'a',
            'client_secret' => 'b',
            'access_token' => 't',
            'ctid_trader_account_id' => 1,
        ]));
        $this->assertNull($connector->getLastTestError());
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
