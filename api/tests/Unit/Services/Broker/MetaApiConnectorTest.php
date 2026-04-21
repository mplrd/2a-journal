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
}
