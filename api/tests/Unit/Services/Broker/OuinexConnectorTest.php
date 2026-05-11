<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\OuinexConnector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OuinexConnectorTest extends TestCase
{
    private MockHandler $mock;

    private function createConnector(array $responses): OuinexConnector
    {
        $this->mock = new MockHandler($responses);
        $handler = HandlerStack::create($this->mock);
        $client = new Client(['handler' => $handler]);

        return new OuinexConnector($client, 'https://fake-ouinex.test/graphql');
    }

    private function gqlResponse(array $payload): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $payload]));
    }

    private function gqlError(string $message): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'errors' => [['message' => $message]],
        ]));
    }

    private function makeMarginPosition(array $overrides = []): array
    {
        return array_merge([
            'margin_position_id' => 'mp-1',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'leverage' => 10,
            'amount' => 0.5,
            'entry_price' => 60000.0,
            'exit_price' => 61500.0,
            'pnl' => 750.0,
            'start_ts' => '2026-01-15T10:00:00Z',
            'end_ts' => '2026-01-15T14:30:00Z',
            'stop_loss' => 59500.0,
            'take_profit' => 62000.0,
            'close_reason' => 'TP_HIT',
        ], $overrides);
    }

    // ── Authentication ─────────────────────────────────────────

    public function testTestConnectionReturnsTrueWhenServiceSigninSucceeds(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['service_signin' => ['jwt' => 'fake-jwt', 'expires_at' => '2030-01-01T00:00:00Z']]),
        ]);

        $this->assertTrue($connector->testConnection([
            'service_api_key' => 'key-1',
            'service_api_secret' => 'secret-1',
        ]));
    }

    public function testTestConnectionReturnsFalseWhenSigninFailsHttp(): void
    {
        $connector = $this->createConnector([
            new Response(401, [], json_encode(['errors' => [['message' => 'invalid_credentials']]])),
        ]);

        $this->assertFalse($connector->testConnection([
            'service_api_key' => 'bad',
            'service_api_secret' => 'bad',
        ]));
    }

    public function testTestConnectionReturnsFalseWhenGraphqlReturnsErrors(): void
    {
        $connector = $this->createConnector([
            $this->gqlError('invalid_credentials'),
        ]);

        $this->assertFalse($connector->testConnection([
            'service_api_key' => 'bad',
            'service_api_secret' => 'bad',
        ]));
    }

    // ── refreshCredentials ─────────────────────────────────────

    public function testRefreshCredentialsReturnsCachedJwtWhenStillValid(): void
    {
        $connector = $this->createConnector([]); // No HTTP call expected

        $credentials = [
            'service_api_key' => 'key-1',
            'service_api_secret' => 'secret-1',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600, // 1h ahead
        ];

        $refreshed = $connector->refreshCredentials($credentials);

        $this->assertSame('cached-jwt', $refreshed['jwt']);
    }

    public function testRefreshCredentialsCallsServiceSigninWhenJwtMissing(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['service_signin' => ['jwt' => 'fresh-jwt', 'expires_at' => '2030-01-01T00:00:00Z']]),
        ]);

        $refreshed = $connector->refreshCredentials([
            'service_api_key' => 'key-1',
            'service_api_secret' => 'secret-1',
        ]);

        $this->assertSame('fresh-jwt', $refreshed['jwt']);
        $this->assertGreaterThan(time(), $refreshed['jwt_expires_at']);
        $this->assertSame('key-1', $refreshed['service_api_key']);
        $this->assertSame('secret-1', $refreshed['service_api_secret']);
    }

    public function testRefreshCredentialsCallsServiceSigninWhenJwtExpired(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['service_signin' => ['jwt' => 'fresh-jwt', 'expires_at' => '2030-01-01T00:00:00Z']]),
        ]);

        $refreshed = $connector->refreshCredentials([
            'service_api_key' => 'key-1',
            'service_api_secret' => 'secret-1',
            'jwt' => 'old-jwt',
            'jwt_expires_at' => time() - 60, // already expired
        ]);

        $this->assertSame('fresh-jwt', $refreshed['jwt']);
    }

    public function testRefreshCredentialsCallsServiceSigninWhenJwtAboutToExpire(): void
    {
        // A JWT that expires in 30s should be refreshed (we want at least 60s of margin
        // so the next request doesn't race the expiry).
        $connector = $this->createConnector([
            $this->gqlResponse(['service_signin' => ['jwt' => 'fresh-jwt', 'expires_at' => '2030-01-01T00:00:00Z']]),
        ]);

        $refreshed = $connector->refreshCredentials([
            'service_api_key' => 'key-1',
            'service_api_secret' => 'secret-1',
            'jwt' => 'about-to-expire',
            'jwt_expires_at' => time() + 30,
        ]);

        $this->assertSame('fresh-jwt', $refreshed['jwt']);
    }

    // ── fetchDeals ─────────────────────────────────────────────

    public function testFetchDealsReturnsNormalizedClosedMarginPositions(): void
    {
        $positions = [
            $this->makeMarginPosition([
                'margin_position_id' => 'mp-100',
                'instrument_id' => 'BTCUSDT',
                'side' => 'BUY',
                'amount' => 0.5,
                'entry_price' => 60000,
                'exit_price' => 61500,
                'pnl' => 750.0,
                'start_ts' => '2026-01-15T10:00:00Z',
                'end_ts' => '2026-01-15T14:30:00Z',
            ]),
        ];

        $connector = $this->createConnector([
            $this->gqlResponse(['closed_margin_positions' => $positions]),
            $this->gqlResponse(['closed_margin_positions' => []]), // pagination terminator
        ]);

        $result = $connector->fetchDeals([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertCount(1, $result['deals']);
        $this->assertSame(1, $result['raw_count']);
        $deal = $result['deals'][0];
        $this->assertSame('BTCUSDT', $deal['symbol']);
        $this->assertSame('BUY', $deal['direction']);
        $this->assertEquals(60000, $deal['entry_price']);
        $this->assertEquals(61500, $deal['exit_price']);
        $this->assertEquals(0.5, $deal['size']);
        $this->assertEquals(750.0, $deal['pnl']);
        $this->assertSame('ouinex_mp-100', $deal['external_id']);
    }

    public function testFetchDealsPaginatesUntilEmptyPage(): void
    {
        $page1 = array_map(fn($i) => $this->makeMarginPosition(['margin_position_id' => "mp-$i", 'end_ts' => '2026-01-15T14:30:00Z']), range(1, 100));
        $page2 = array_map(fn($i) => $this->makeMarginPosition(['margin_position_id' => "mp-$i", 'end_ts' => '2026-01-16T14:30:00Z']), range(101, 150));

        $connector = $this->createConnector([
            $this->gqlResponse(['closed_margin_positions' => $page1]),
            $this->gqlResponse(['closed_margin_positions' => $page2]),
            $this->gqlResponse(['closed_margin_positions' => []]),
        ]);

        $result = $connector->fetchDeals([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertCount(150, $result['deals']);
        $this->assertSame(150, $result['raw_count']);
    }

    public function testFetchDealsReturnsLatestEndTsAsCursor(): void
    {
        $positions = [
            $this->makeMarginPosition(['margin_position_id' => 'mp-1', 'end_ts' => '2026-01-15T14:30:00Z']),
            $this->makeMarginPosition(['margin_position_id' => 'mp-2', 'end_ts' => '2026-01-16T18:00:00Z']),
            $this->makeMarginPosition(['margin_position_id' => 'mp-3', 'end_ts' => '2026-01-14T09:00:00Z']),
        ];

        $connector = $this->createConnector([
            $this->gqlResponse(['closed_margin_positions' => $positions]),
            $this->gqlResponse(['closed_margin_positions' => []]),
        ]);

        $result = $connector->fetchDeals([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame('2026-01-16T18:00:00Z', $result['cursor']);
    }

    public function testFetchDealsFiltersOutPositionsOlderThanCursor(): void
    {
        // closed_margin_positions API has no dateRange filter → we filter
        // client-side: only positions whose end_ts is strictly > cursor get
        // included. The cursor is set to the latest end_ts of the page (raw),
        // so the next sync starts exactly past it.
        $positions = [
            $this->makeMarginPosition(['margin_position_id' => 'old', 'end_ts' => '2026-01-10T00:00:00Z']),
            $this->makeMarginPosition(['margin_position_id' => 'new', 'end_ts' => '2026-01-20T00:00:00Z']),
        ];

        $connector = $this->createConnector([
            $this->gqlResponse(['closed_margin_positions' => $positions]),
            $this->gqlResponse(['closed_margin_positions' => []]),
        ]);

        $result = $connector->fetchDeals([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ], '2026-01-15T00:00:00Z');

        $this->assertCount(1, $result['deals']);
        $this->assertSame('ouinex_new', $result['deals'][0]['external_id']);
        // raw_count counts everything fetched, not just kept (so we know
        // pagination did its job).
        $this->assertSame(2, $result['raw_count']);
    }

    public function testFetchDealsRefreshesJwtOnExpiredCredsBeforeQuery(): void
    {
        // Credentials with no JWT → connector must service_signin first, then
        // run the closed_margin_positions query, then a terminator empty page.
        $connector = $this->createConnector([
            $this->gqlResponse(['service_signin' => ['jwt' => 'fresh-jwt', 'expires_at' => '2030-01-01T00:00:00Z']]),
            $this->gqlResponse(['closed_margin_positions' => []]),
        ]);

        $result = $connector->fetchDeals([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            // no jwt
        ]);

        $this->assertSame(0, $result['raw_count']);
    }

    public function testFetchDealsReturnsEmptyResultWhenApiReturnsEmpty(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['closed_margin_positions' => []]),
        ]);

        $result = $connector->fetchDeals([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['deals']);
        $this->assertNull($result['cursor']);
    }

    // ── fetchOpenPositions ──────────────────────────────────────

    private function makeOpenMarginPosition(array $overrides = []): array
    {
        // Same shape as makeMarginPosition() minus the fields a live position
        // doesn't carry yet — no exit_price, no pnl, no end_ts, no close_reason.
        return array_merge([
            'margin_position_id' => 'mp-open-1',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'leverage' => 10,
            'amount' => 0.25,
            'entry_price' => 60000.0,
            'stop_loss' => 59000.0,
            'take_profit' => 62000.0,
            'start_ts' => '2026-05-07T08:00:00Z',
        ], $overrides);
    }

    public function testFetchOpenPositionsReturnsNormalizedSnapshot(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['open_margin_positions' => [
                $this->makeOpenMarginPosition(['margin_position_id' => 'mp-open-42', 'instrument_id' => 'ETHUSDT', 'side' => 'SELL']),
            ]]),
            $this->gqlResponse(['open_margin_positions' => []]),
        ]);

        $result = $connector->fetchOpenPositions([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame(1, $result['raw_count']);
        $this->assertCount(1, $result['positions']);
        $pos = $result['positions'][0];
        $this->assertSame('ETHUSDT', $pos['symbol']);
        $this->assertSame('SELL', $pos['direction']);
        $this->assertSame('ouinex_mp-open-42', $pos['external_id']);
        // Critical: open positions MUST NOT carry closed_at — ImportService
        // uses its absence to decide OPEN vs CLOSED at insert time.
        $this->assertArrayNotHasKey('closed_at', $pos);
        $this->assertNull($pos['pnl'] ?? null);
    }

    public function testFetchOpenPositionsPaginatesUntilEmptyPage(): void
    {
        $page1 = array_map(fn($i) => $this->makeOpenMarginPosition(['margin_position_id' => "mp-open-$i"]), range(1, 100));
        $page2 = array_map(fn($i) => $this->makeOpenMarginPosition(['margin_position_id' => "mp-open-$i"]), range(101, 130));

        $connector = $this->createConnector([
            $this->gqlResponse(['open_margin_positions' => $page1]),
            $this->gqlResponse(['open_margin_positions' => $page2]),
            $this->gqlResponse(['open_margin_positions' => []]),
        ]);

        $result = $connector->fetchOpenPositions([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame(130, $result['raw_count']);
        $this->assertCount(130, $result['positions']);
    }

    public function testFetchOpenPositionsRefreshesJwtIfMissing(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['service_signin' => ['jwt' => 'fresh-jwt', 'expires_at' => '2030-01-01T00:00:00Z']]),
            $this->gqlResponse(['open_margin_positions' => []]),
        ]);

        $result = $connector->fetchOpenPositions([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            // no jwt — connector must signin first
        ]);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['positions']);
    }

    public function testFetchOpenPositionsReturnsEmptyWhenApiReturnsEmpty(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['open_margin_positions' => []]),
        ]);

        $result = $connector->fetchOpenPositions([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['positions']);
    }

    // ── fetchOpenOrders ─────────────────────────────────────────

    private function makeOpenOrder(array $overrides = []): array
    {
        return array_merge([
            'order_id' => 'ord-1',
            'instrument_id' => 'BTCUSDT',
            'side' => 'BUY',
            'order_type' => 'LIMIT',
            'amount' => 0.5,
            'price' => 58000.0,
            'stop_loss' => 57000.0,
            'take_profit' => 62000.0,
            'expires_at' => '2026-06-01T00:00:00Z',
            'created_at' => '2026-05-07T08:00:00Z',
        ], $overrides);
    }

    public function testFetchOpenOrdersReturnsNormalizedSnapshot(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['open_orders' => [
                $this->makeOpenOrder(['order_id' => 'ord-42', 'instrument_id' => 'ETHUSDT', 'side' => 'SELL']),
            ]]),
            $this->gqlResponse(['open_orders' => []]),
        ]);

        $result = $connector->fetchOpenOrders([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame(1, $result['raw_count']);
        $this->assertCount(1, $result['orders']);
        $order = $result['orders'][0];
        $this->assertSame('ETHUSDT', $order['symbol']);
        $this->assertSame('SELL', $order['direction']);
        // Prefix is distinct from margin positions to avoid scope confusion.
        $this->assertSame('ouinex_order_ord-42', $order['external_id']);
    }

    public function testFetchOpenOrdersPaginatesUntilEmptyPage(): void
    {
        $page1 = array_map(fn($i) => $this->makeOpenOrder(['order_id' => "ord-$i"]), range(1, 100));
        $page2 = array_map(fn($i) => $this->makeOpenOrder(['order_id' => "ord-$i"]), range(101, 110));

        $connector = $this->createConnector([
            $this->gqlResponse(['open_orders' => $page1]),
            $this->gqlResponse(['open_orders' => $page2]),
            $this->gqlResponse(['open_orders' => []]),
        ]);

        $result = $connector->fetchOpenOrders([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame(110, $result['raw_count']);
        $this->assertCount(110, $result['orders']);
    }

    public function testFetchOpenOrdersRefreshesJwtIfMissing(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['service_signin' => ['jwt' => 'fresh-jwt', 'expires_at' => '2030-01-01T00:00:00Z']]),
            $this->gqlResponse(['open_orders' => []]),
        ]);

        $result = $connector->fetchOpenOrders([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            // no jwt
        ]);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['orders']);
    }

    public function testFetchOpenOrdersReturnsEmptyWhenApiReturnsEmpty(): void
    {
        $connector = $this->createConnector([
            $this->gqlResponse(['open_orders' => []]),
        ]);

        $result = $connector->fetchOpenOrders([
            'service_api_key' => 'k',
            'service_api_secret' => 's',
            'jwt' => 'cached-jwt',
            'jwt_expires_at' => time() + 3600,
        ]);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['orders']);
    }
}
