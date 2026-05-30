<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\MetaApiConnector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class MetaApiConnectorTest extends TestCase
{
    private function createConnectorWithMock(array $responses): MetaApiConnector
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        return new MetaApiConnector($client, 'https://fake-api.test');
    }

    public function testFetchDealsReturnsNormalizedDeals(): void
    {
        $apiResponse = [
            [
                'id' => 'deal-1',
                'type' => 'DEAL_TYPE_BUY',
                'time' => '2024-11-22T07:43:00.000Z',
                'symbol' => 'GER40.cash',
                'volume' => 1.0,
                'price' => 19200.00,
                'profit' => 0,
                'commission' => 0,
                'swap' => 0,
                'positionId' => 'pos-100',
                'entryType' => 'DEAL_ENTRY_IN',
            ],
            [
                'id' => 'deal-2',
                'type' => 'DEAL_TYPE_SELL',
                'time' => '2024-11-22T07:44:00.000Z',
                'symbol' => 'GER40.cash',
                'volume' => 1.0,
                'price' => 19226.05,
                'profit' => 26.05,
                'commission' => -0.50,
                'swap' => 0,
                'positionId' => 'pos-100',
                'entryType' => 'DEAL_ENTRY_OUT',
            ],
        ];

        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode($apiResponse)),
        ]);

        $result = $connector->fetchDeals(['api_token' => 'test', 'metaapi_account_id' => 'acc-1']);

        // Only closing deals are returned
        $this->assertCount(1, $result['deals']);
        $this->assertSame('GER40.cash', $result['deals'][0]['symbol']);
        $this->assertSame('BUY', $result['deals'][0]['direction']); // closing SELL → position was BUY
        $this->assertEquals(19226.05, $result['deals'][0]['exit_price']);
        $this->assertEquals(26.05, $result['deals'][0]['pnl']);
        $this->assertSame('metaapi_pos-100', $result['deals'][0]['external_id']);
        $this->assertSame(2, $result['raw_count']);
    }

    public function testFetchDealsWithSinceCursor(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode([])),
        ]);

        $result = $connector->fetchDeals(
            ['api_token' => 'test', 'metaapi_account_id' => 'acc-1'],
            '2024-11-22T00:00:00Z'
        );

        $this->assertCount(0, $result['deals']);
        $this->assertSame(0, $result['raw_count']);
    }

    public function testFetchDealsReturnsLatestTimestampAsCursor(): void
    {
        $apiResponse = [
            [
                'id' => 'd1', 'type' => 'DEAL_TYPE_SELL', 'time' => '2024-11-20T10:00:00.000Z',
                'symbol' => 'EURUSD', 'volume' => 0.5, 'price' => 1.09, 'profit' => 5,
                'positionId' => 'p1', 'entryType' => 'DEAL_ENTRY_OUT',
            ],
            [
                'id' => 'd2', 'type' => 'DEAL_TYPE_BUY', 'time' => '2024-11-22T15:30:00.000Z',
                'symbol' => 'GER40', 'volume' => 1.0, 'price' => 19000, 'profit' => -10,
                'positionId' => 'p2', 'entryType' => 'DEAL_ENTRY_OUT',
            ],
        ];

        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode($apiResponse)),
        ]);

        $result = $connector->fetchDeals(['api_token' => 'test', 'metaapi_account_id' => 'acc-1']);

        // Cursor should be the latest deal time
        $this->assertSame('2024-11-22T15:30:00.000Z', $result['cursor']);
    }

    public function testTestConnectionReturnsTrue(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode(['_id' => 'acc-1', 'state' => 'DEPLOYED'])),
        ]);

        $this->assertTrue($connector->testConnection(['api_token' => 'test', 'metaapi_account_id' => 'acc-1']));
    }

    public function testTestConnectionReturnsFalseOnError(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(401, [], json_encode(['message' => 'Unauthorized'])),
        ]);

        $this->assertFalse($connector->testConnection(['api_token' => 'test', 'metaapi_account_id' => 'acc-1']));
    }

    public function testRefreshCredentialsIsNoOp(): void
    {
        $connector = $this->createConnectorWithMock([]);
        $credentials = ['api_token' => 'test', 'metaapi_account_id' => 'acc-1'];

        $this->assertSame($credentials, $connector->refreshCredentials($credentials));
    }

    public function testPlaceMarketOrderMapsToOrderTypeBuyAndReturnsExternalId(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode([
                'numericCode' => 10009,
                'stringCode' => 'TRADE_RETCODE_DONE',
                'message' => 'Request completed',
                'orderId' => '987654',
                'positionId' => '987654',
            ])),
        ]);

        $result = $connector->placeOrder(
            ['api_token' => 'test', 'metaapi_account_id' => 'acc-1'],
            [
                'symbol' => 'EURUSD',
                'direction' => 'BUY',
                'order_type' => 'MARKET',
                'size' => 0.10,
                'sl_price' => 1.0950,
                'tp_prices' => [1.1050],
                'client_order_id' => 'tv-alert-1',
            ]
        );

        $this->assertSame('987654', $result['external_order_id']);
        $this->assertSame('TRADE_RETCODE_DONE', $result['status']);
    }

    public function testPlaceLimitOrderIncludesOpenPrice(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode([
                'stringCode' => 'TRADE_RETCODE_PLACED',
                'orderId' => '100',
            ])),
        ]);

        $result = $connector->placeOrder(
            ['api_token' => 't', 'metaapi_account_id' => 'a'],
            [
                'symbol' => 'GBPUSD',
                'direction' => 'SELL',
                'order_type' => 'LIMIT',
                'size' => 1.0,
                'entry_price' => 1.2700,
            ]
        );

        $this->assertSame('100', $result['external_order_id']);
        $this->assertSame('TRADE_RETCODE_PLACED', $result['status']);
    }

    public function testRejectedTradeThrowsBrokerOrderException(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode([
                'numericCode' => 10019,
                'stringCode' => 'TRADE_RETCODE_NO_MONEY',
                'message' => 'There is not enough money',
            ])),
        ]);

        $this->expectException(\App\Exceptions\BrokerOrderException::class);
        $this->expectExceptionMessage('There is not enough money');

        $connector->placeOrder(
            ['api_token' => 't', 'metaapi_account_id' => 'a'],
            ['symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 100],
        );
    }

    public function testMissingCredentialsThrowsBrokerOrderException(): void
    {
        $connector = $this->createConnectorWithMock([]);

        try {
            $connector->placeOrder([], ['symbol' => 'EURUSD', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 1]);
            $this->fail('Expected BrokerOrderException');
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->assertSame('INVALID_CREDENTIALS', $e->getProviderCode());
        }
    }

    public function testCancelOrderHitsOrderCancelAction(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode(['stringCode' => 'TRADE_RETCODE_DONE'])),
        ]);

        $result = $connector->cancelOrder(['api_token' => 't', 'metaapi_account_id' => 'a'], 'ord-99');
        $this->assertSame('TRADE_RETCODE_DONE', $result['status']);
    }

    public function testClosePositionFullVsPartial(): void
    {
        $connector = $this->createConnectorWithMock([
            new Response(200, [], json_encode(['stringCode' => 'TRADE_RETCODE_DONE'])),
            new Response(200, [], json_encode(['stringCode' => 'TRADE_RETCODE_DONE'])),
        ]);

        $full = $connector->closePosition(['api_token' => 't', 'metaapi_account_id' => 'a'], 'pos-1');
        $partial = $connector->closePosition(['api_token' => 't', 'metaapi_account_id' => 'a'], 'pos-1', 0.5);

        $this->assertSame('TRADE_RETCODE_DONE', $full['status']);
        $this->assertSame('TRADE_RETCODE_DONE', $partial['status']);
    }
}
