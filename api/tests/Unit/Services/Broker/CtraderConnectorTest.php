<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\CtraderConnector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class CtraderConnectorTest extends TestCase
{
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
            // no refresh_token
        ];

        $result = $connector->refreshCredentials($credentials);
        $this->assertSame($credentials, $result);
    }

    public function testNormalizeDealListResponse(): void
    {
        // Test the static normalization of a cTrader deal list response
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

        // Only closing deal should be returned
        $this->assertCount(1, $deals);
        $this->assertSame('GER40', $deals[0]['symbol']);
        $this->assertSame('SELL', $deals[0]['direction']);
        $this->assertEquals(19200.00, $deals[0]['entry_price']);
        $this->assertEquals(19226.05, $deals[0]['exit_price']);
        $this->assertEquals(26.05, $deals[0]['pnl']);
        $this->assertSame('ctrader_50', $deals[0]['external_id']);
    }

    public function testBuildWsMessages(): void
    {
        $connector = new CtraderConnector($this->config);

        $appAuth = $connector->buildMessage('ProtoOAApplicationAuthReq', [
            'clientId' => 'test_client_id',
            'clientSecret' => 'test_client_secret',
        ]);

        $decoded = json_decode($appAuth, true);
        $this->assertSame('ProtoOAApplicationAuthReq', $decoded['payloadType']);
        $this->assertSame('test_client_id', $decoded['clientId']);
    }

    private function makeWsStub(array $scriptedResponses): FakeWsClient
    {
        return new FakeWsClient($scriptedResponses);
    }

    public function testPlaceMarketBuyOrderConvertsLotToVolumeAndReturnsOrderId(): void
    {
        $ws = $this->makeWsStub([
            ['payloadType' => 'ProtoOAApplicationAuthRes'],
            ['payloadType' => 'ProtoOAAccountAuthRes'],
            // ProtoOASymbolsListRes — resolve EURUSD → 1
            ['payloadType' => 'ProtoOASymbolsListRes', 'symbol' => [
                ['symbolId' => 1, 'symbolName' => 'EURUSD'],
                ['symbolId' => 2, 'symbolName' => 'GBPUSD'],
            ]],
            // Order ack
            ['payloadType' => 'ProtoOAExecutionEvent', 'order' => [
                'orderId' => 1100,
                'orderStatus' => 'ORDER_STATUS_FILLED',
            ]],
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

        // Verify the NewOrder payload sent: volume = 10 (0.10 * 100)
        $orderRequestJson = $ws->sentMessages[3];
        $sent = json_decode($orderRequestJson, true);
        $this->assertSame('ProtoOANewOrderReq', $sent['payloadType']);
        $this->assertSame(1, $sent['symbolId']);
        $this->assertSame('MARKET', $sent['orderType']);
        $this->assertSame('BUY', $sent['tradeSide']);
        $this->assertSame(10, $sent['volume']);
        $this->assertSame(1.0950, $sent['stopLoss']);
    }

    public function testPlaceLimitOrderIncludesLimitPrice(): void
    {
        $ws = $this->makeWsStub([
            ['payloadType' => 'ProtoOAApplicationAuthRes'],
            ['payloadType' => 'ProtoOAAccountAuthRes'],
            ['payloadType' => 'ProtoOASymbolsListRes', 'symbol' => [['symbolId' => 5, 'symbolName' => 'GBPUSD']]],
            ['payloadType' => 'ProtoOAExecutionEvent', 'order' => ['orderId' => 200, 'orderStatus' => 'ORDER_STATUS_ACCEPTED']],
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $connector->placeOrder(
            ['ctid_trader_account_id' => 1, 'access_token' => 't'],
            ['symbol' => 'GBPUSD', 'direction' => 'SELL', 'order_type' => 'LIMIT', 'size' => 1.0, 'entry_price' => 1.27],
        );

        $sent = json_decode($ws->sentMessages[3], true);
        $this->assertSame('LIMIT', $sent['orderType']);
        $this->assertSame('SELL', $sent['tradeSide']);
        $this->assertSame(1.27, $sent['limitPrice']);
    }

    public function testUnknownSymbolThrowsBrokerOrderException(): void
    {
        $ws = $this->makeWsStub([
            ['payloadType' => 'ProtoOAApplicationAuthRes'],
            ['payloadType' => 'ProtoOAAccountAuthRes'],
            ['payloadType' => 'ProtoOASymbolsListRes', 'symbol' => [['symbolId' => 1, 'symbolName' => 'EURUSD']]],
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

    public function testApiErrorIsMappedToBrokerRejected(): void
    {
        $ws = $this->makeWsStub([
            ['payloadType' => 'ProtoOAApplicationAuthRes'],
            ['payloadType' => 'ProtoOAAccountAuthRes'],
            ['payloadType' => 'ProtoOASymbolsListRes', 'symbol' => [['symbolId' => 1, 'symbolName' => 'EURUSD']]],
            ['payloadType' => 'ProtoOAErrorRes', 'errorCode' => 'NOT_ENOUGH_MONEY', 'description' => 'Insufficient margin'],
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

    public function testCancelOrderSendsProtoOACancelOrderReq(): void
    {
        $ws = $this->makeWsStub([
            ['payloadType' => 'ProtoOAApplicationAuthRes'],
            ['payloadType' => 'ProtoOAAccountAuthRes'],
            ['payloadType' => 'ProtoOAExecutionEvent', 'order' => ['orderId' => 1, 'orderStatus' => 'ORDER_STATUS_CANCELLED']],
        ]);
        $connector = new CtraderConnector($this->config, $ws);

        $result = $connector->cancelOrder(['ctid_trader_account_id' => 1, 'access_token' => 't'], '1');
        $this->assertSame('ORDER_STATUS_CANCELLED', $result['status']);

        $sent = json_decode($ws->sentMessages[2], true);
        $this->assertSame('ProtoOACancelOrderReq', $sent['payloadType']);
        $this->assertSame(1, $sent['orderId']);
    }

    public function testClosePositionFullAndPartial(): void
    {
        $ws1 = $this->makeWsStub([
            ['payloadType' => 'ProtoOAApplicationAuthRes'],
            ['payloadType' => 'ProtoOAAccountAuthRes'],
            ['payloadType' => 'ProtoOAExecutionEvent', 'executionEvent' => ['executionType' => 'ORDER_FILLED']],
        ]);
        $connector1 = new CtraderConnector($this->config, $ws1);
        $full = $connector1->closePosition(['ctid_trader_account_id' => 1, 'access_token' => 't'], 'pos-99');
        $this->assertIsArray($full);
        $sentFull = json_decode($ws1->sentMessages[2], true);
        $this->assertArrayNotHasKey('volume', $sentFull);

        $ws2 = $this->makeWsStub([
            ['payloadType' => 'ProtoOAApplicationAuthRes'],
            ['payloadType' => 'ProtoOAAccountAuthRes'],
            ['payloadType' => 'ProtoOAExecutionEvent', 'executionEvent' => ['executionType' => 'ORDER_PARTIAL_FILL']],
        ]);
        $connector2 = new CtraderConnector($this->config, $ws2);
        $connector2->closePosition(['ctid_trader_account_id' => 1, 'access_token' => 't'], 'pos-99', 0.5);
        $sentPartial = json_decode($ws2->sentMessages[2], true);
        $this->assertSame(50, $sentPartial['volume']);
    }
}

/**
 * Minimal WsClient stand-in: each `text()` call gets paired with the next
 * scripted response from `receive()`. Sent messages are captured in
 * `$sentMessages` for assertions.
 */
class FakeWsClient extends \WebSocket\Client
{
    public array $sentMessages = [];
    private array $responses;
    private int $pos = 0;

    public function __construct(array $scriptedResponses)
    {
        $this->responses = $scriptedResponses;
        // Skip parent constructor — no real socket.
    }

    public function text(string $payload): void
    {
        $this->sentMessages[] = $payload;
    }

    public function receive(): string
    {
        if ($this->pos >= count($this->responses)) {
            throw new \RuntimeException('FakeWsClient ran out of scripted responses');
        }
        return json_encode($this->responses[$this->pos++]);
    }

    public function close(int $status = 1000, string $message = 'ttfn'): void
    {
        // no-op
    }
}
