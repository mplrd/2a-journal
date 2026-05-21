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

    private function emptyChunk(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 0, 'msg' => '', 'data' => ['orders' => []],
        ]));
    }

    private function fillsChunk(array $orders): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 0, 'msg' => '', 'data' => ['orders' => $orders],
        ]));
    }

    /**
     * Empty income response — used after /user/positions in tests where we
     * don't care about cross-symbol enumeration via income (the test
     * symbols are already covered by the live positions snapshot).
     */
    private function emptyIncomeChunk(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 0, 'msg' => '', 'data' => [],
        ]));
    }

    private function incomeChunk(array $records): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 0, 'msg' => '', 'data' => $records,
        ]));
    }

    private function makeFillRaw(array $overrides = []): array
    {
        return array_merge([
            'orderId' => '100',
            'symbol' => 'BTC-USDT',
            'positionSide' => 'LONG',
            'side' => 'BUY',
            'status' => 'FILLED',
            'reduceOnly' => false,
            'executedQty' => '0.01',
            'avgPrice' => '60000',
            'profit' => '0',
            'updateTime' => 1716000000000,
        ], $overrides);
    }

    public function testFetchOpenPositionsSurfacesLivePositionWhenNoFillsHistory(): void
    {
        // Live position exists on BingX but no fills found (account too old
        // for the walk to reach the opening fill). Connector falls back to
        // the live snapshot and surfaces the position with no exits[].
        $connector = $this->createConnector([
            // 1) /user/positions (symbol enumeration seed)
            $this->bxResponse([
                [
                    'positionId' => 'pos-100',
                    'symbol' => 'BTC-USDT',
                    'positionSide' => 'LONG',
                    'positionAmt' => '0.5',
                    'avgPrice' => '60000',
                ],
            ]),
            // 2) /user/income walk → empty (no income history)
            $this->emptyIncomeChunk(),
            // 3) /allOrders chunk for BTC-USDT → empty (no fills in walk)
            $this->emptyChunk(),
        ]);

        $result = $connector->fetchOpenPositions(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertCount(1, $result['positions']);
        $pos = $result['positions'][0];
        $this->assertSame('BTC-USDT', $pos['symbol']);
        $this->assertSame('BUY', $pos['direction']);
        $this->assertEquals(0.5, $pos['size']);
        $this->assertSame([], $pos['exits']);
    }

    public function testFetchOpenPositionsReconstructsExitsForPositionWithPartialClose(): void
    {
        // Live position BTC-USDT, 0.005 remaining. The fills walk uncovers
        // the original open (0.01) and a prior partial close (0.005). The
        // open position surfaces WITH that partial exit in exits[].
        $connector = $this->createConnector([
            // 1) /user/positions
            $this->bxResponse([
                [
                    'positionId' => 'pos-100',
                    'symbol' => 'BTC-USDT',
                    'positionSide' => 'LONG',
                    'positionAmt' => '0.005',
                    'avgPrice' => '60000',
                ],
            ]),
            // 2) /user/income walk → empty (test doesn't exercise income enum)
            $this->emptyIncomeChunk(),
            // 3) /allOrders chunk with the open + partial close fills
            $this->fillsChunk([
                $this->makeFillRaw([
                    'orderId' => 'open1', 'reduceOnly' => false, 'side' => 'BUY',
                    'executedQty' => '0.01', 'avgPrice' => '60000', 'updateTime' => 1000,
                ]),
                $this->makeFillRaw([
                    'orderId' => 'tp1', 'reduceOnly' => true, 'side' => 'SELL',
                    'executedQty' => '0.005', 'avgPrice' => '65000', 'profit' => '25',
                    'updateTime' => 2000,
                ]),
            ]),
            // 4) next chunk back → empty → stop walking
            $this->emptyChunk(),
        ]);

        $result = $connector->fetchOpenPositions(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertCount(1, $result['positions']);
        $pos = $result['positions'][0];
        $this->assertSame('BTC-USDT', $pos['symbol']);
        $this->assertSame('BUY', $pos['direction']);
        $this->assertCount(1, $pos['exits']);
        $this->assertEqualsWithDelta(0.005, $pos['remaining_size'], 0.00001);
        $this->assertSame('bingx_position_open1', $pos['external_id']);
        $this->assertSame('bingx_fill_tp1', $pos['exits'][0]['external_id']);
    }

    public function testFetchOpenPositionsReturnsEmptyWhenLiveAndIncomeAreEmpty(): void
    {
        // No live positions AND no income history → no symbols to walk →
        // connector returns the empty bucket without making any /allOrders
        // call.
        $connector = $this->createConnector([
            // 1) /user/positions → empty
            $this->bxResponse([]),
            // 2) /user/income walk → empty → no symbols discovered
            $this->emptyIncomeChunk(),
        ]);

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
        // fetchClosedOrders now uses the same symbol enumeration as
        // fetchDeals (live positions + income walk) and chunk-walks
        // /allOrders per symbol. Mock order:
        //   1. /user/positions (symbol enumeration)
        //   2. /user/income walk (empty, no historical-only symbols here)
        //   3. /allOrders chunk for BTC-USDT
        //   4. /allOrders next chunk → empty → stop
        $connector = $this->createConnector([
            $this->bxResponse([
                ['positionId' => 'live', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
                 'positionAmt' => '0.5', 'avgPrice' => '60000'],
            ]),
            $this->emptyIncomeChunk(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 0, 'msg' => '',
                'data' => ['orders' => [
                    ['orderId' => 100, 'status' => 'FILLED', 'updateTime' => 1714867200000],
                    ['orderId' => 101, 'status' => 'CANCELED', 'updateTime' => 1714867200000],
                    ['orderId' => 102, 'status' => 'EXPIRED', 'updateTime' => 1714867200000],
                ]],
            ])),
            $this->emptyChunk(),  // next chunk back → stop walk
        ]);

        $result = $connector->fetchClosedOrders(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(3, $result['raw_count']);
        $this->assertSame('EXECUTED', $result['orders'][0]['final_status']);
        $this->assertSame('CANCELLED', $result['orders'][1]['final_status']);
        $this->assertSame('EXPIRED', $result['orders'][2]['final_status']);
        $this->assertSame('bingx_order_100', $result['orders'][0]['external_id']);
    }

    // ── fetchDeals (closed positions reconstructed from fills) ─────

    public function testFetchDealsReconstructsClosedPositionsFromFills(): void
    {
        // BTC-USDT: complete cycle (open + full close) → 1 closed cycle
        // ETH-USDT: complete cycle (open + full close) → 1 closed cycle
        $connector = $this->createConnector([
            // 1) /user/positions for symbol enumeration (positions present so
            // both symbols are picked up as live or income-discovered)
            $this->bxResponse([
                ['positionId' => 'live-1', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
                 'positionAmt' => '0', 'avgPrice' => '60000'],
                ['positionId' => 'live-2', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                 'positionAmt' => '0', 'avgPrice' => '4000'],
            ]),
            // 2) /user/income walk → empty (live snapshot already has both
            // symbols, no need to test income enum here)
            $this->emptyIncomeChunk(),
            // 2) /allOrders BTC-USDT chunk with the complete cycle
            $this->fillsChunk([
                $this->makeFillRaw([
                    'orderId' => 'btc-open', 'reduceOnly' => false, 'side' => 'BUY',
                    'executedQty' => '0.01', 'avgPrice' => '60000', 'updateTime' => 1000,
                ]),
                $this->makeFillRaw([
                    'orderId' => 'btc-close', 'reduceOnly' => true, 'side' => 'SELL',
                    'executedQty' => '0.01', 'avgPrice' => '61500', 'profit' => '15',
                    'updateTime' => 2000,
                ]),
            ]),
            // 3) BTC chunk back → empty → stop on BTC
            $this->emptyChunk(),
            // 4) /allOrders ETH-USDT chunk
            $this->fillsChunk([
                $this->makeFillRaw([
                    'orderId' => 'eth-open', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                    'reduceOnly' => false, 'side' => 'SELL',
                    'executedQty' => '1', 'avgPrice' => '4200', 'updateTime' => 3000,
                ]),
                $this->makeFillRaw([
                    'orderId' => 'eth-close', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                    'reduceOnly' => true, 'side' => 'BUY',
                    'executedQty' => '1', 'avgPrice' => '4000', 'profit' => '200',
                    'updateTime' => 4000,
                ]),
            ]),
            // 5) ETH chunk back → empty
            $this->emptyChunk(),
        ]);

        $result = $connector->fetchDeals(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertCount(2, $result['deals']);
        $this->assertNotNull($result['cursor']);

        // Map by external_id for stable assertions regardless of order.
        $byExternalId = [];
        foreach ($result['deals'] as $deal) {
            $byExternalId[$deal['external_id']] = $deal;
        }

        $this->assertArrayHasKey('bingx_position_btc-open', $byExternalId);
        $btc = $byExternalId['bingx_position_btc-open'];
        $this->assertSame('BTC-USDT', $btc['symbol']);
        $this->assertSame('BUY', $btc['direction']);
        $this->assertEquals(60000.0, $btc['entry_price']);
        $this->assertEquals(61500.0, $btc['exit_price']);
        $this->assertEqualsWithDelta(15.0, $btc['pnl'], 0.001);
        $this->assertCount(1, $btc['exits']);

        $this->assertArrayHasKey('bingx_position_eth-open', $byExternalId);
        $eth = $byExternalId['bingx_position_eth-open'];
        $this->assertSame('SELL', $eth['direction']);
    }

    public function testFetchDealsStopsWalkingAtCursor(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        $cursorMs = $nowMs - 3600 * 1000;       // 1 hour ago
        $fillAfterCursor = $nowMs - 1800 * 1000; // 30 min ago

        $connector = $this->createConnector([
            // /user/positions
            $this->bxResponse([
                ['positionId' => 'live', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
                 'positionAmt' => '0', 'avgPrice' => '60000'],
            ]),
            // /user/income walk → single chunk to cursor (in-cursor sync), empty
            $this->emptyIncomeChunk(),
            // /allOrders — single chunk: open + close, both within the cursor window
            $this->fillsChunk([
                $this->makeFillRaw([
                    'orderId' => 'recent-open', 'reduceOnly' => false, 'side' => 'BUY',
                    'executedQty' => '0.01', 'avgPrice' => '60000',
                    'updateTime' => $fillAfterCursor - 100,
                ]),
                $this->makeFillRaw([
                    'orderId' => 'recent-close', 'reduceOnly' => true, 'side' => 'SELL',
                    'executedQty' => '0.01', 'avgPrice' => '61000', 'profit' => '10',
                    'updateTime' => $fillAfterCursor,
                ]),
            ]),
        ]);

        $result = $connector->fetchDeals(
            ['api_key' => 'k', 'api_secret' => 's'],
            (string) $cursorMs,
        );

        $this->assertCount(1, $result['deals']);
        $this->assertSame('bingx_position_recent-open', $result['deals'][0]['external_id']);
        // Cursor advances to the latest fill seen.
        $this->assertSame((string) $fillAfterCursor, $result['cursor']);
    }

    public function testFetchDealsReturnsEmptyWhenNoSymbolsAndNoIncome(): void
    {
        $connector = $this->createConnector([
            $this->bxResponse([]),        // no open positions
            $this->emptyIncomeChunk(),    // no income history either
        ]);

        $result = $connector->fetchDeals(['api_key' => 'k', 'api_secret' => 's']);

        $this->assertSame(0, $result['raw_count']);
        $this->assertSame([], $result['deals']);
        $this->assertNull($result['cursor']);
    }

    public function testFetchDealsDiscoversHistoricalSymbolsViaIncome(): void
    {
        // Critical scenario: user has trades on ETH-USDT historically but no
        // currently-open ETH position. The legacy seed (live positions only)
        // would miss ETH entirely. /user/income surfaces it from past P&L
        // records so the fills walk picks it up.
        $connector = $this->createConnector([
            // /user/positions — only BTC-USDT currently
            $this->bxResponse([
                ['positionId' => 'live', 'symbol' => 'BTC-USDT', 'positionSide' => 'LONG',
                 'positionAmt' => '0', 'avgPrice' => '60000'],
            ]),
            // /user/income — chunk with ETH-USDT income records
            $this->incomeChunk([
                ['symbol' => 'ETH-USDT', 'incomeType' => 'REALIZED_PNL', 'income' => '50', 'time' => 1716000000000],
                ['symbol' => 'ETH-USDT', 'incomeType' => 'COMMISSION', 'income' => '-0.5', 'time' => 1716000000000],
            ]),
            // /user/income next chunk → empty → stop income walk
            $this->emptyIncomeChunk(),
            // /allOrders BTC-USDT chunk
            $this->fillsChunk([
                $this->makeFillRaw([
                    'orderId' => 'btc-open', 'reduceOnly' => false, 'side' => 'BUY',
                    'executedQty' => '0.01', 'avgPrice' => '60000', 'updateTime' => 1000,
                ]),
                $this->makeFillRaw([
                    'orderId' => 'btc-close', 'reduceOnly' => true, 'side' => 'SELL',
                    'executedQty' => '0.01', 'avgPrice' => '61000', 'profit' => '10',
                    'updateTime' => 2000,
                ]),
            ]),
            $this->emptyChunk(),  // BTC next chunk → stop
            // /allOrders ETH-USDT chunk (only fetched because income surfaced this symbol)
            $this->fillsChunk([
                $this->makeFillRaw([
                    'orderId' => 'eth-open', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                    'reduceOnly' => false, 'side' => 'SELL',
                    'executedQty' => '1', 'avgPrice' => '4000', 'updateTime' => 3000,
                ]),
                $this->makeFillRaw([
                    'orderId' => 'eth-close', 'symbol' => 'ETH-USDT', 'positionSide' => 'SHORT',
                    'reduceOnly' => true, 'side' => 'BUY',
                    'executedQty' => '1', 'avgPrice' => '3950', 'profit' => '50',
                    'updateTime' => 4000,
                ]),
            ]),
            $this->emptyChunk(),  // ETH next chunk → stop
        ]);

        $result = $connector->fetchDeals(['api_key' => 'k', 'api_secret' => 's']);

        // 2 closed cycles: 1 BTC + 1 ETH. Without income discovery, ETH
        // would have been missed entirely.
        $this->assertCount(2, $result['deals']);
        $externalIds = array_column($result['deals'], 'external_id');
        $this->assertContains('bingx_position_btc-open', $externalIds);
        $this->assertContains('bingx_position_eth-open', $externalIds);
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
