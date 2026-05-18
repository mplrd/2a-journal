<?php

namespace Tests\Unit\Services\Broker;

use App\Services\Broker\BingxConnector;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class BingxConnectorTest extends TestCase
{
    private MockHandler $mock;
    /** @var array<int, \Psr\Http\Message\RequestInterface> */
    private array $capturedRequests = [];

    private function createConnector(array $responses): BingxConnector
    {
        $this->capturedRequests = [];
        $this->mock = new MockHandler($responses);
        $handler = HandlerStack::create($this->mock);
        $handler->push(function (callable $next) {
            return function ($request, $options) use ($next) {
                $this->capturedRequests[] = $request;
                return $next($request, $options);
            };
        });
        $client = new Client(['handler' => $handler]);

        return new BingxConnector($client, 'https://fake-bingx.test');
    }

    private function bxResponse(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 0,
            'msg' => '',
            'data' => $data,
        ]));
    }

    private function bxError(int $code, string $msg): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => $code,
            'msg' => $msg,
            'data' => null,
        ]));
    }

    private function makeClosedPositionRaw(array $overrides = []): array
    {
        // Field names are inferred from BingX's open-position schema and
        // standard conventions (positionId, avgPrice for entry, closeAvgPrice
        // for exit, realisedProfit for PnL, openTime/closeTime in ms).
        // If BingX uses different names in positionHistory.list[], the
        // normalizer fallbacks will handle it gracefully.
        return array_merge([
            'positionId' => 'pos-1',
            'symbol' => 'BTC-USDT',
            'positionSide' => 'LONG',
            'avgPrice' => '60000',
            'closeAvgPrice' => '61500',
            'positionAmt' => '0.5',
            'realisedProfit' => '750',
            'openTime' => 1714867200000, // 2024-05-05 00:00:00
            'closeTime' => 1714896000000, // 2024-05-05 08:00:00
        ], $overrides);
    }

    // ── Authentication / signing ───────────────────────────────────

    public function testTestConnectionReturnsTrueWhenBalanceCallSucceeds(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse(['balance' => '1000']),
        ]);

        $this->assertTrue($connector->testConnection([
            'api_key' => 'key-1',
            'api_secret' => 'secret-1',
        ]));

        // Verify a signed request was actually sent
        $this->assertCount(1, $this->capturedRequests);
        $request = $this->capturedRequests[0];
        $this->assertSame('key-1', $request->getHeaderLine('X-BX-APIKEY'));
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertArrayHasKey('signature', $query);
        $this->assertArrayHasKey('timestamp', $query);
        $this->assertSame(64, strlen($query['signature'])); // hex sha256 = 64 chars
    }

    public function testTestConnectionReturnsFalseOnHttpError(): void
    {
        $connector = $this->createConnector([
            new Response(401, [], json_encode(['code' => 100410, 'msg' => 'invalid_api_key'])),
        ]);

        $this->assertFalse($connector->testConnection([
            'api_key' => 'bad', 'api_secret' => 'bad',
        ]));
    }

    public function testTestConnectionReturnsFalseOnBingxBusinessError(): void
    {
        // BingX returns 200 OK with a non-zero `code` for business errors.
        $connector = $this->createConnector([
            $this->bxError(100410, 'invalid_api_key'),
        ]);

        $this->assertFalse($connector->testConnection([
            'api_key' => 'bad', 'api_secret' => 'bad',
        ]));
    }

    public function testSignatureIsBuiltFromSortedParamsAndAppendedToQuery(): void
    {
        // For a known credential pair, verify the HMAC-SHA256 hex of the
        // canonical "key=value&..." (ASCII-sorted, no URL-encoding) matches
        // the signature actually sent.
        $connector = $this->createConnector([
            $this->bxResponse(['balance' => '0']),
        ]);

        $connector->testConnection([
            'api_key' => 'k', 'api_secret' => 'mysecret',
        ]);

        $request = $this->capturedRequests[0];
        parse_str($request->getUri()->getQuery(), $params);
        // Rebuild canonical string excluding signature, sorted.
        $signature = $params['signature'];
        unset($params['signature']);
        ksort($params);
        $canonical = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        // BingX wants the un-encoded canonical; http_build_query encodes.
        // The connector must use the raw key=value form.
        $rawCanonical = [];
        foreach ($params as $k => $v) {
            $rawCanonical[] = "$k=$v";
        }
        $expected = hash_hmac('sha256', implode('&', $rawCanonical), 'mysecret');
        $this->assertSame($expected, $signature);
    }

    // ── refreshCredentials ─────────────────────────────────────────

    public function testRefreshCredentialsIsNoOpForHmac(): void
    {
        // BingX uses static API key/secret — no token to refresh.
        $connector = $this->createConnector([]); // no HTTP call expected
        $credentials = ['api_key' => 'k', 'api_secret' => 's'];
        $this->assertSame($credentials, $connector->refreshCredentials($credentials));
    }

    // ── fetchOpenPositions ─────────────────────────────────────────

    public function testFetchOpenPositionsReturnsNormalizedSnapshot(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse([
                [
                    'positionId' => 'pos-100',
                    'symbol' => 'BTC-USDT',
                    'positionSide' => 'LONG',
                    'positionAmt' => '0.5',
                    'availableAmt' => '0.5',
                    'avgPrice' => '60000',
                    'leverage' => 10,
                    'unrealizedProfit' => '500',
                    'realisedProfit' => '0',
                    'liquidationPrice' => 55000.0,
                    'isolated' => true,
                    'currency' => 'USDT',
                ],
            ]),
        ]);

        $result = $connector->fetchOpenPositions([
            'api_key' => 'k', 'api_secret' => 's',
        ]);

        $this->assertSame(1, $result['raw_count']);
        $this->assertCount(1, $result['positions']);
        $pos = $result['positions'][0];
        $this->assertSame('BTC-USDT', $pos['symbol']);
        $this->assertSame('BUY', $pos['direction']); // LONG → BUY
        $this->assertSame('bingx_pos-100', $pos['external_id']);
        $this->assertEquals(60000.0, $pos['entry_price']);
        $this->assertEquals(0.5, $pos['size']);
        $this->assertArrayNotHasKey('closed_at', $pos);
    }

    public function testFetchOpenPositionsMapsShortToSell(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse([
                ['positionId' => 'p2', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                 'positionAmt' => '1', 'avgPrice' => '4000'],
            ]),
        ]);

        $result = $connector->fetchOpenPositions(['api_key' => 'k', 'api_secret' => 's']);
        $this->assertSame('SELL', $result['positions'][0]['direction']);
    }

    public function testFetchOpenPositionsReturnsEmptyWhenApiReturnsEmptyList(): void
    {
        $connector = $this->createConnector([$this->bxResponse([])]);

        $result = $connector->fetchOpenPositions(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['positions']);
    }

    // ── fetchOpenOrders ────────────────────────────────────────────

    public function testFetchOpenOrdersReturnsNormalizedSnapshot(): void
    {
        // BingX wraps the list under `data.orders`.
        $connector = $this->createConnector([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 0, 'msg' => '',
                'data' => ['orders' => [
                    [
                        'orderId' => 12345,
                        'symbol' => 'BTC-USDT',
                        'side' => 'BUY',
                        'positionSide' => 'LONG',
                        'type' => 'LIMIT',
                        'origQty' => '0.5',
                        'executedQty' => '0',
                        'price' => '58000',
                        'avgPrice' => '0',
                        'status' => 'NEW',
                        'time' => 1714867200000,
                        'updateTime' => 1714867200000,
                    ],
                ]],
            ])),
        ]);

        $result = $connector->fetchOpenOrders(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(1, $result['raw_count']);
        $this->assertCount(1, $result['orders']);
        $order = $result['orders'][0];
        $this->assertSame('BTC-USDT', $order['symbol']);
        $this->assertSame('BUY', $order['direction']);
        $this->assertEquals(58000.0, $order['entry_price']);
        $this->assertEquals(0.5, $order['size']);
        $this->assertSame('bingx_order_12345', $order['external_id']);
    }

    public function testFetchOpenOrdersHandlesBareArrayResponse(): void
    {
        // Tolerate the alternate response shape (some BingX endpoints
        // return data as a bare array, not nested under .orders).
        $connector = $this->createConnector([
            $this->bxResponse([
                ['orderId' => 99, 'symbol' => 'ETH-USDT', 'side' => 'SELL',
                 'type' => 'LIMIT', 'origQty' => '1', 'price' => '4000',
                 'status' => 'NEW', 'time' => 1714867200000, 'updateTime' => 1714867200000],
            ]),
        ]);

        $result = $connector->fetchOpenOrders(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(1, $result['raw_count']);
        $this->assertSame('SELL', $result['orders'][0]['direction']);
    }

    // ── fetchClosedOrders ──────────────────────────────────────────

    public function testFetchClosedOrdersReturnsFinalStatuses(): void
    {
        // fetchClosedOrders needs the active symbol list first (allOrders
        // requires `symbol` per call), then queries each symbol.
        $connector = $this->createConnector([
            $this->bxResponse([
                ['positionId' => 'live', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
                 'positionAmt' => '0.5', 'avgPrice' => '60000'],
            ]),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 0, 'msg' => '',
                'data' => ['orders' => [
                    ['orderId' => 100, 'status' => 'FILLED', 'updateTime' => 1714867200000],
                    ['orderId' => 101, 'status' => 'CANCELED', 'updateTime' => 1714867200000],
                    ['orderId' => 102, 'status' => 'EXPIRED', 'updateTime' => 1714867200000],
                ]],
            ])),
        ]);

        $result = $connector->fetchClosedOrders(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(3, $result['raw_count']);
        $this->assertSame('EXECUTED', $result['orders'][0]['final_status']);
        $this->assertSame('CANCELLED', $result['orders'][1]['final_status']);
        $this->assertSame('EXPIRED', $result['orders'][2]['final_status']);
        $this->assertSame('bingx_order_100', $result['orders'][0]['external_id']);
    }

    // ── fetchDeals (closed positions via positionHistory) ──────────

    public function testFetchDealsIteratesActiveSymbolsAndReturnsNormalizedClosedPositions(): void
    {
        // positionHistory requires a `symbol` per call. To enumerate
        // symbols, the connector first fetches open positions, then queries
        // positionHistory per symbol. (The user may have closed all their
        // positions for a symbol — those won't appear in open_positions,
        // but the previous sync's cursor narrows the next-run window so
        // the gap is bounded.)
        $connector = $this->createConnector([
            // 1) listing symbols via user/positions
            $this->bxResponse([
                ['positionId' => 'live-1', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
                 'positionAmt' => '0.5', 'avgPrice' => '60000'],
                ['positionId' => 'live-2', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                 'positionAmt' => '1', 'avgPrice' => '4000'],
            ]),
            // 2) positionHistory?symbol=BTC-USDT
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 0, 'msg' => '',
                'data' => ['total' => 1, 'list' => [$this->makeClosedPositionRaw([
                    'positionId' => 'closed-btc-1', 'symbol' => 'BTC-USDT',
                ])]],
            ])),
            // 3) positionHistory?symbol=ETH-USDT
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 0, 'msg' => '',
                'data' => ['total' => 1, 'list' => [$this->makeClosedPositionRaw([
                    'positionId' => 'closed-eth-1', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                    'avgPrice' => '4200', 'closeAvgPrice' => '4000', 'realisedProfit' => '200',
                ])]],
            ])),
        ]);

        $result = $connector->fetchDeals(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(2, $result['raw_count']);
        $this->assertCount(2, $result['deals']);
        // Cursor on max closeTime so the next run picks up later closures.
        $this->assertNotNull($result['cursor']);

        $btcDeal = $result['deals'][0];
        $this->assertSame('bingx_closed-btc-1', $btcDeal['external_id']);
        $this->assertEquals(60000.0, $btcDeal['entry_price']);
        $this->assertEquals(61500.0, $btcDeal['exit_price']);
        $this->assertEquals(750.0, $btcDeal['pnl']);
    }

    public function testFetchDealsFiltersClosedPositionsOlderThanCursor(): void
    {
        // Cursor is the closeTime of the latest closed position seen by the
        // previous run. The connector filters out positions whose closeTime
        // is not strictly greater (preventing re-import).
        $oldClose = 1714867200000; // 2024-05-05 00:00:00
        $newClose = 1714896000000; // 2024-05-05 08:00:00

        $connector = $this->createConnector([
            $this->bxResponse([
                ['positionId' => 'live', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
                 'positionAmt' => '0.5', 'avgPrice' => '60000'],
            ]),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 0, 'msg' => '',
                'data' => ['total' => 2, 'list' => [
                    $this->makeClosedPositionRaw(['positionId' => 'old', 'closeTime' => $oldClose]),
                    $this->makeClosedPositionRaw(['positionId' => 'new', 'closeTime' => $newClose]),
                ]],
            ])),
        ]);

        $result = $connector->fetchDeals(['api_key' => 'k', 'api_secret' => 's'], (string) $oldClose);

        // 'old' filtered out (closeTime not > cursor), only 'new' survives.
        $this->assertCount(1, $result['deals']);
        $this->assertSame('bingx_new', $result['deals'][0]['external_id']);
        $this->assertSame((string) $newClose, $result['cursor']);
    }

    public function testFetchDealsReturnsEmptyWhenNoSymbols(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse([]), // no open positions → no symbols to query
        ]);

        $result = $connector->fetchDeals(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['deals']);
        $this->assertNull($result['cursor']);
    }

    public function testPlaceMarketBuyMapsToPositionSideLongAndExtractsOrderId(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse(['order' => ['orderId' => 1755234567, 'status' => 'FILLED']]),
        ]);

        $result = $connector->placeOrder(
            ['api_key' => 'k', 'api_secret' => 's'],
            [
                'symbol' => 'BTC-USDT',
                'direction' => 'BUY',
                'order_type' => 'MARKET',
                'size' => 0.01,
                'sl_price' => 55000.0,
                'tp_prices' => [70000.0],
                'client_order_id' => 'tv-1',
            ]
        );

        $this->assertSame('1755234567', $result['external_order_id']);
        $this->assertSame('FILLED', $result['status']);

        // Sanity: outbound request used POST verb on /trade/order
        $req = $this->capturedRequests[0];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringContainsString('/openApi/swap/v2/trade/order', $req->getUri()->getPath());
    }

    public function testPlaceSellMapsToPositionSideShort(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse(['order' => ['orderId' => 999, 'status' => 'NEW']]),
        ]);

        $connector->placeOrder(
            ['api_key' => 'k', 'api_secret' => 's'],
            [
                'symbol' => 'ETH-USDT',
                'direction' => 'SELL',
                'order_type' => 'LIMIT',
                'size' => 0.5,
                'entry_price' => 4500,
            ]
        );

        $query = [];
        parse_str($this->capturedRequests[0]->getUri()->getQuery(), $query);
        $this->assertSame('SELL', $query['side']);
        $this->assertSame('SHORT', $query['positionSide']);
        $this->assertSame('LIMIT', $query['type']);
        $this->assertSame('4500', $query['price']);
    }

    public function testRejectedOrderThrowsBrokerOrderException(): void
    {
        $connector = $this->createConnector([
            $this->bxError(101104, 'Insufficient margin'),
        ]);

        try {
            $connector->placeOrder(
                ['api_key' => 'k', 'api_secret' => 's'],
                ['symbol' => 'BTC-USDT', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 1000],
            );
            $this->fail('Expected BrokerOrderException');
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->assertSame('101104', $e->getProviderCode());
            $this->assertStringContainsString('Insufficient margin', $e->getMessage());
        }
    }

    public function testMissingCredentialsRaisesInvalidCredentials(): void
    {
        $connector = $this->createConnector([]);

        try {
            $connector->placeOrder([], ['symbol' => 'X', 'direction' => 'BUY', 'order_type' => 'MARKET', 'size' => 1]);
            $this->fail('Expected BrokerOrderException');
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->assertSame('INVALID_CREDENTIALS', $e->getProviderCode());
        }
    }

    public function testCancelOrderUsesDeleteVerb(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse(['order' => ['orderId' => 1, 'status' => 'CANCELED']]),
        ]);

        $result = $connector->cancelOrder(['api_key' => 'k', 'api_secret' => 's'], '1');

        $this->assertSame('CANCELED', $result['status']);
        $this->assertSame('DELETE', $this->capturedRequests[0]->getMethod());
    }

    public function testClosePositionFullCloseHitsClosePositionPath(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse(['orderId' => 42]),
        ]);

        $result = $connector->closePosition(['api_key' => 'k', 'api_secret' => 's'], 'pos-1');
        $this->assertSame('CLOSED', $result['status']);
        $this->assertStringContainsString('/closePosition', $this->capturedRequests[0]->getUri()->getPath());
    }

    public function testPartialCloseExplicitlyUnsupported(): void
    {
        $connector = $this->createConnector([]);

        try {
            $connector->closePosition(['api_key' => 'k', 'api_secret' => 's'], 'pos-1', 0.5);
            $this->fail('Expected BrokerOrderException');
        } catch (\App\Exceptions\BrokerOrderException $e) {
            $this->assertSame('PARTIAL_CLOSE_UNSUPPORTED', $e->getProviderCode());
        }
    }
}
