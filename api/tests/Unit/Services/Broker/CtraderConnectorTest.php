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
}
