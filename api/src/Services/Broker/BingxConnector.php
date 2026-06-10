<?php

namespace App\Services\Broker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * BingX USDT-M Perpetual Futures connector.
 *
 * Source of truth: raw FILLS pulled from /openApi/swap/v2/trade/allOrders
 * (status IN FILLED, PARTIALLY_FILLED), grouped into position lifecycles
 * by BingxFillReconstructor. Position lifecycles split into:
 *   - closed cycles → fed to fetchDeals (with their exits[]) → journal
 *     creates positions + trades CLOSED + partial_exits
 *   - open cycles  → fed to fetchOpenPositions (with their exits[]) →
 *     journal creates/updates positions + trades OPEN + partial_exits for
 *     prior partial closes
 *
 * The connector memoises the fills walk per (credentials, cursor) pair so
 * a single sync only ever pulls allOrders once even though both fetch
 * methods are called.
 *
 * Auth: HMAC-SHA256 over the canonical "key=value&..." string in URL order.
 * `X-BX-APIKEY` header carries the key. No JWT cycle — refreshCredentials
 * is a no-op (API key/secret are static credentials).
 */
class BingxConnector implements ConnectorInterface
{
    /** Page size for allOrders pagination per chunk. */
    private const PAGE_SIZE = 100;

    /**
     * Max span BingX allows per request on /allOrders and other history
     * endpoints (server-side, returns code 109400 above this). We chunk
     * our actual lookback into windows of this size — broker constraint,
     * not a product choice.
     */
    private const CHUNK_WINDOW_SECONDS = 7 * 24 * 3600;

    /**
     * First-sync stop rule: how many CONSECUTIVE empty 7-day windows we
     * tolerate before concluding we've walked back past the account's origin
     * (or BingX's retention horizon) and stopping. This is the fix for the
     * "stop at the first empty window" bug: a single quiet week — or any gap
     * shorter than this many weeks — no longer halts the whole history walk.
     * A trading dormancy LONGER than (this × 7 days) would still truncate
     * older history, so it's tuned generously and overridable per connection.
     */
    private const DEFAULT_MAX_EMPTY_CHUNKS = 12; // ~84 days of continuous silence

    /**
     * Absolute safety floor for a first sync: never walk windows older than
     * this, even if BingX keeps dribbling sparse non-empty chunks. Pure
     * runaway backstop — the consecutive-empty rule is the normal stop.
     */
    private const FIRST_SYNC_MAX_LOOKBACK_SECONDS = 5 * 365 * 24 * 3600;

    private Client $httpClient;
    private string $baseUrl;
    private DealNormalizer $normalizer;
    private BingxFillReconstructor $reconstructor;

    /** @see self::DEFAULT_MAX_EMPTY_CHUNKS */
    private int $maxEmptyChunks;

    /**
     * Symbols persisted across syncs (from broker_connections.symbols_seen).
     * Set by the caller before fetch* methods so the connector can walk
     * fills for symbols the user has fully closed since the last sync.
     * @var string[]
     */
    private array $knownSymbols = [];

    /**
     * Memoised reconstruction result for the current sync run, keyed by
     * the sinceCursor value passed to fetchDeals/fetchOpenPositions. Reset
     * by resetSyncCache() between syncs (called by BrokerSyncService).
     */
    private ?array $cachedReconstruction = null;
    private ?string $cachedCursorKey = null;

    /**
     * Memoised symbol-discovery result, keyed by the cursor passed in.
     * Used by both runReconstruction (drives the fills walk) and
     * fetchClosedOrders (drives the orders walk). Avoids hitting the
     * income endpoint twice per sync.
     */
    private ?array $cachedSymbols = null;
    private ?string $cachedSymbolsCursorKey = null;
    private ?array $cachedLiveSnapshot = null;

    public function __construct(Client $httpClient, string $baseUrl, ?int $maxEmptyChunks = null)
    {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->normalizer = new DealNormalizer();
        $this->reconstructor = new BingxFillReconstructor();
        $this->maxEmptyChunks = ($maxEmptyChunks !== null && $maxEmptyChunks >= 1)
            ? $maxEmptyChunks
            : self::DEFAULT_MAX_EMPTY_CHUNKS;
    }

    /**
     * Symbols the caller knows have been seen on this connection previously
     * (read from broker_connections.symbols_seen). The connector unions them
     * with currently-open symbols when walking fills, so a symbol the user
     * fully closed since last sync still gets re-scanned for late fills.
     *
     * @param string[] $symbols
     */
    public function setKnownSymbols(array $symbols): void
    {
        $this->knownSymbols = array_values(array_unique(array_filter($symbols, 'is_string')));
    }

    /**
     * Force the next fetch* call to repull from BingX rather than reuse a
     * cached reconstruction. Called by BrokerSyncService at the start of
     * each sync to keep instances safely reusable.
     */
    public function resetSyncCache(): void
    {
        $this->cachedReconstruction = null;
        $this->cachedCursorKey = null;
        $this->cachedSymbols = null;
        $this->cachedSymbolsCursorKey = null;
        $this->cachedLiveSnapshot = null;
    }

    /**
     * Symbols actually observed during the latest reconstruction, used by
     * the caller to update broker_connections.symbols_seen.
     *
     * @return string[]
     */
    public function getSeenSymbols(): array
    {
        return $this->cachedReconstruction['seenSymbols'] ?? [];
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        $reconstruction = $this->runReconstruction($credentials, $sinceCursor);

        return [
            'deals' => $reconstruction['closed'],
            'cursor' => $reconstruction['latestFillTime'] !== null
                ? (string) $reconstruction['latestFillTime']
                : null,
            'raw_count' => $reconstruction['rawCount'],
        ];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        // Same reconstruction the closed-deals path uses — the run is
        // memoised so we don't double-pull allOrders. The "open" bucket
        // is positions that still have non-zero remaining_size after the
        // walk; each carries its exits[] array so any partial closes
        // already taken before the position fully closes are inserted
        // into the journal as partial_exits.
        $reconstruction = $this->runReconstruction($credentials, null);

        $positions = $reconstruction['open'];
        // Cross-check with the live /user/positions snapshot we already
        // pulled inside runReconstruction (no extra HTTP call). Positions
        // visible to BingX but absent from the fills walk (because their
        // opening fill is older than what BingX serves) are surfaced
        // minimally so the journal still has a row.
        $liveSnapshotMissing = $this->buildLiveOpenPositionsNotInReconstruction(
            $reconstruction['liveSnapshot'] ?? [],
            $reconstruction['open']
        );
        foreach ($liveSnapshotMissing as $live) {
            $positions[] = $live;
        }

        return [
            'positions' => $positions,
            'raw_count' => count($positions),
        ];
    }

    /**
     * Memoised wrapper around the actual fills walk + reconstruction. Same
     * input → same output, second call within a sync is free.
     *
     * @return array{closed: array, open: array, latestFillTime: ?int, rawCount: int, seenSymbols: string[]}
     */
    private function runReconstruction(array $credentials, ?string $sinceCursor): array
    {
        $key = (string) $sinceCursor;
        if ($this->cachedReconstruction !== null && $this->cachedCursorKey === $key) {
            return $this->cachedReconstruction;
        }

        // 1. Enumerate symbols (memoised, shared with fetchClosedOrders).
        $now = (int) (microtime(true) * 1000);
        $cursorMs = $sinceCursor !== null && $sinceCursor !== '' ? (int) $sinceCursor : null;
        $symbols = $this->discoverAllSymbols($credentials, $cursorMs, $now);
        $openPositionsRaw = $this->cachedLiveSnapshot ?? [];

        if (empty($symbols)) {
            $result = [
                'closed' => [],
                'open' => [],
                'latestFillTime' => null,
                'rawCount' => 0,
                'seenSymbols' => [],
                'liveSnapshot' => $openPositionsRaw,
            ];
            $this->cachedReconstruction = $result;
            $this->cachedCursorKey = $key;
            return $result;
        }

        // 2. Walk fills per symbol, accumulate into a single normalised list.
        // $now and $cursorMs were computed above for the income enumeration.

        $allFills = [];
        $rawCount = 0;
        $latestFillTime = null;
        $seenSymbols = [];

        foreach ($symbols as $symbol) {
            $hasFillsOnSymbol = false;
            $this->walkChunks(
                '/openApi/swap/v2/trade/allOrders',
                ['symbol' => $symbol, 'limit' => (string) self::PAGE_SIZE],
                $credentials,
                $cursorMs,
                $now,
                function (mixed $data) use (&$allFills, &$rawCount, &$latestFillTime, &$hasFillsOnSymbol): int {
                    $list = $data['orders'] ?? (is_array($data) ? $data : []);
                    $rawCount += count($list);

                    foreach ($list as $raw) {
                        $fill = $this->normalizer->normalizeBingxFill($raw);
                        if ($fill === null) {
                            continue;
                        }
                        $hasFillsOnSymbol = true;
                        $allFills[] = $fill;
                        if ($fill['time'] > 0 && ($latestFillTime === null || $fill['time'] > $latestFillTime)) {
                            $latestFillTime = $fill['time'];
                        }
                    }

                    return count($list);
                },
            );
            if ($hasFillsOnSymbol) {
                $seenSymbols[] = $symbol;
            }
        }

        // 3. Reconstruct position lifecycles from fills.
        $reconstructed = $this->reconstructor->reconstruct($allFills);

        // Map reconstructed rows to the journal-side normalised shape.
        $closed = array_map([$this, 'toJournalClosedShape'], $reconstructed['closed']);
        $open = array_map([$this, 'toJournalOpenShape'], $reconstructed['open']);

        $result = [
            'closed' => $closed,
            'open' => $open,
            'latestFillTime' => $latestFillTime,
            'rawCount' => $rawCount,
            'seenSymbols' => array_values(array_unique($seenSymbols)),
            'liveSnapshot' => $openPositionsRaw,
        ];
        $this->cachedReconstruction = $result;
        $this->cachedCursorKey = $key;
        return $result;
    }

    /**
     * Convert a reconstructor "closed" cycle to the row shape downstream
     * services (ImportService.importNormalizedPositions) consume.
     */
    private function toJournalClosedShape(array $cycle): array
    {
        return [
            'symbol' => $cycle['symbol'],
            'direction' => $cycle['direction'],
            'entry_price' => round((float) $cycle['entry_price'], 5),
            'exit_price' => round((float) $cycle['exit_price'], 5),
            'size' => round((float) $cycle['size'], 5),
            'pnl' => round((float) $cycle['pnl'], 2),
            'opened_at' => $this->msToDatetime((int) $cycle['opened_at']),
            'closed_at' => $this->msToDatetime((int) $cycle['closed_at']),
            'external_id' => $cycle['external_id'],
            'pips' => null,
            'comment' => null,
            'exits' => array_map([$this, 'mapExitToJournal'], $cycle['exits']),
        ];
    }

    /**
     * Convert a reconstructor "open" cycle to the row shape
     * BrokerOpenSyncService consumes for INSERT/UPDATE on currently-open
     * positions, carrying any prior partial exits so the journal logs them
     * as partial_exits attached to the OPEN trade.
     */
    private function toJournalOpenShape(array $cycle): array
    {
        return [
            'symbol' => $cycle['symbol'],
            'direction' => $cycle['direction'],
            'entry_price' => round((float) $cycle['entry_price'], 5),
            'size' => round((float) $cycle['size'], 5),
            'remaining_size' => round((float) $cycle['remaining_size'], 5),
            'sl_price' => null,
            'tp_price' => null,
            'opened_at' => $this->msToDatetime((int) $cycle['opened_at']),
            'external_id' => $cycle['external_id'],
            'pnl' => round((float) $cycle['pnl'], 2),
            'comment' => null,
            'exits' => array_map([$this, 'mapExitToJournal'], $cycle['exits']),
        ];
    }

    private function mapExitToJournal(array $exit): array
    {
        return [
            'exit_price' => round((float) $exit['exit_price'], 5),
            'size' => round((float) $exit['size'], 5),
            'pnl' => round((float) $exit['pnl'], 2),
            'closed_at' => $this->msToDatetime((int) $exit['exited_at']),
            'exit_type' => $exit['exit_type'] ?? 'MANUAL',
            'external_id' => $exit['external_id'] ?? null,
            'pips' => null,
        ];
    }

    private function msToDatetime(int $ms): ?string
    {
        if ($ms <= 0) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', (int) ($ms / 1000));
    }

    /**
     * For positions reported live by /user/positions but absent from the
     * reconstructed "open" bucket (because the fills walk didn't reach
     * the opening fill — typically a position opened months before any
     * fill BingX still serves): surface them minimally so the journal
     * still has a row. They carry no exits[] because we have no fill
     * record for them.
     *
     * Operates on the already-fetched raw snapshot from runReconstruction
     * to avoid a duplicate HTTP call.
     *
     * Dedup is by symbol+direction since the legacy normalizer uses
     * `bingx_<positionId>` while the reconstructor uses
     * `bingx_position_<orderId>` — different identifiers for the same
     * real-world position. Matching by (symbol, direction) is sufficient
     * because BingX only allows one open position per (symbol, side) in
     * one-way mode, and in hedge mode positionSide gives the direction.
     */
    private function buildLiveOpenPositionsNotInReconstruction(array $liveSnapshot, array $reconstructedOpen): array
    {
        $reconstructedKeys = [];
        foreach ($reconstructedOpen as $pos) {
            $reconstructedKeys[$pos['symbol'] . '|' . $pos['direction']] = true;
        }

        $live = [];
        foreach ($liveSnapshot as $raw) {
            $normalized = $this->normalizer->normalizeBingxOpenPosition($raw);
            if ($normalized === null) {
                continue;
            }
            $key = $normalized['symbol'] . '|' . $normalized['direction'];
            if (isset($reconstructedKeys[$key])) {
                continue;
            }
            $normalized['remaining_size'] = $normalized['size'];
            $normalized['exits'] = [];
            $live[] = $normalized;
        }
        return $live;
    }

    public function fetchOpenOrders(array $credentials): array
    {
        $data = $this->httpGetSigned('/openApi/swap/v2/trade/openOrders', [], $credentials);

        // BingX wraps the list under `data.orders` here, but some legacy
        // shapes also surface bare arrays — tolerate both.
        $list = $data['orders'] ?? (is_array($data) ? $data : []);

        $orders = [];
        $rawCount = count($list);
        foreach ($list as $raw) {
            $normalized = $this->normalizer->normalizeBingxOpenOrder($raw);
            if ($normalized !== null) {
                $orders[] = $normalized;
            }
        }

        return ['orders' => $orders, 'raw_count' => $rawCount];
    }

    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        // /allOrders requires `symbol` per call. Same symbol enumeration as
        // fetchDeals: union of live positions + income-discovered history
        // + persisted symbols_seen. Memoised within a single sync.
        $now = (int) (microtime(true) * 1000);
        $cursorMs = $sinceCursor !== null && $sinceCursor !== '' ? (int) $sinceCursor : null;
        $symbols = $this->discoverAllSymbols($credentials, $cursorMs, $now);

        if (empty($symbols)) {
            return ['orders' => [], 'raw_count' => 0];
        }

        // Chunk-walk /allOrders per symbol, same pattern as fetchDeals:
        // walk to cursor on incremental sync, walk to first empty chunk
        // on first sync. No arbitrary cap — the broker's empty response
        // is the only stop signal.
        $orders = [];
        $rawCount = 0;

        foreach ($symbols as $symbol) {
            $this->walkChunks(
                '/openApi/swap/v2/trade/allOrders',
                ['symbol' => $symbol, 'limit' => (string) self::PAGE_SIZE],
                $credentials,
                $cursorMs,
                $now,
                function (mixed $data) use (&$orders, &$rawCount): int {
                    $list = $data['orders'] ?? (is_array($data) ? $data : []);
                    $rawCount += count($list);

                    foreach ($list as $raw) {
                        $normalized = $this->normalizer->normalizeBingxClosedOrder($raw);
                        if ($normalized !== null) {
                            $orders[] = $normalized;
                        }
                    }

                    return count($list);
                },
            );
        }

        return ['orders' => $orders, 'raw_count' => $rawCount];
    }

    public function refreshCredentials(array $credentials): array
    {
        // HMAC API key/secret are static — no token rotation needed.
        return $credentials;
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $this->httpGetSigned('/openApi/swap/v3/user/balance', [], $credentials);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetchBalance(array $credentials): ?float
    {
        try {
            $data = $this->httpGetSigned('/openApi/swap/v3/user/balance', [], $credentials);
        } catch (\Throwable) {
            return null;
        }

        // BingX swap v3 response shape (best-effort, alias-tolerant):
        //   data.balance.equity  → preferred (balance + unrealized PnL)
        //   data.balance.balance → fallback (just available + frozen)
        //   data.equity / data.balance → some accounts/versions
        $inner = is_array($data) ? ($data['balance'] ?? $data) : [];
        $equity = $inner['equity'] ?? $inner['balance'] ?? null;
        if ($equity === null) {
            return null;
        }
        return (float) $equity;
    }

    public function placeOrder(array $credentials, array $order): array
    {
        $direction = strtoupper($order['direction'] ?? '');
        $orderType = strtoupper($order['order_type'] ?? 'MARKET');
        if (!in_array($direction, ['BUY', 'SELL'], true)) {
            throw new \App\Exceptions\BrokerOrderException(
                "Unsupported direction {$direction}",
                'UNSUPPORTED_ORDER',
            );
        }
        if (!in_array($orderType, ['MARKET', 'LIMIT', 'STOP'], true)) {
            throw new \App\Exceptions\BrokerOrderException(
                "Unsupported order_type {$orderType}",
                'UNSUPPORTED_ORDER',
            );
        }

        // BingX USDT-M perp uses hedge-mode-aware fields: `side` (BUY/SELL =
        // direction of the trade) + `positionSide` (LONG/SHORT = which book
        // to put it on). For a fresh entry we mirror direction → positionSide
        // (BUY=LONG, SELL=SHORT). Closing is handled via closePosition().
        $params = [
            'symbol' => $order['symbol'],
            'side' => $direction,
            'positionSide' => $direction === 'BUY' ? 'LONG' : 'SHORT',
            'type' => $orderType === 'STOP' ? 'TRIGGER_MARKET' : $orderType,
            'quantity' => (string) (float) $order['size'],
        ];

        if ($orderType === 'LIMIT' && !empty($order['entry_price'])) {
            $params['price'] = (string) (float) $order['entry_price'];
            $params['timeInForce'] = 'GTC';
        }
        if ($orderType === 'STOP' && !empty($order['entry_price'])) {
            $params['stopPrice'] = (string) (float) $order['entry_price'];
        }
        if (!empty($order['sl_price'])) {
            $params['stopLoss'] = json_encode([
                'type' => 'STOP_MARKET',
                'stopPrice' => (float) $order['sl_price'],
                'workingType' => 'MARK_PRICE',
            ]);
        }
        if (!empty($order['tp_prices'][0])) {
            $params['takeProfit'] = json_encode([
                'type' => 'TAKE_PROFIT_MARKET',
                'stopPrice' => (float) $order['tp_prices'][0],
                'workingType' => 'MARK_PRICE',
            ]);
        }
        if (!empty($order['client_order_id'])) {
            $params['clientOrderID'] = mb_substr((string) $order['client_order_id'], 0, 40);
        }

        $data = $this->httpPostSigned('/openApi/swap/v2/trade/order', $params, $credentials);

        // BingX returns { order: { orderId, status, ... } } under `data`.
        $orderData = (is_array($data) && isset($data['order']) && is_array($data['order'])) ? $data['order'] : (array) $data;
        $externalId = $orderData['orderId'] ?? null;
        if ($externalId === null) {
            throw new \App\Exceptions\BrokerOrderException(
                'BingX did not return an orderId',
                'NO_ORDER_ID',
                $orderData,
            );
        }

        return [
            'external_order_id' => (string) $externalId,
            'status' => $orderData['status'] ?? null,
            'raw' => $orderData,
        ];
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        $data = $this->httpPostSigned(
            '/openApi/swap/v2/trade/order',
            ['orderId' => $externalOrderId],
            $credentials,
            'DELETE',
        );
        $orderData = (is_array($data) && isset($data['order'])) ? $data['order'] : (array) $data;
        return ['status' => $orderData['status'] ?? null, 'raw' => $orderData];
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        // BingX has /closePosition for full-close-by-positionId. For partial
        // closes we issue a market order in the opposite direction with
        // reduceOnly=true.
        if ($sizeOverride === null) {
            $data = $this->httpPostSigned(
                '/openApi/swap/v2/trade/closePosition',
                ['positionId' => $externalPositionId],
                $credentials,
            );
            return ['status' => 'CLOSED', 'raw' => is_array($data) ? $data : []];
        }

        // Partial close: caller has to provide enough context (symbol +
        // direction of the open position) so we know which side to flip.
        // closePosition is the wrong abstraction here — for partials, the
        // caller should use placeOrder with reduceOnly. We keep the existing
        // signature for full closes and reject partials cleanly.
        throw new \App\Exceptions\BrokerOrderException(
            'BingX partial close requires placing an opposite reduceOnly order via placeOrder() — '
            . 'closePosition() supports full close only.',
            'PARTIAL_CLOSE_UNSUPPORTED',
        );
    }

    /**
     * Amend the SL and/or TP of a live order on BingX (PUT on the order
     * endpoint). Expects absolute prices, not points: at modify time we have no
     * entry reference to convert points, and a modify is about setting concrete
     * levels. The caller (robot pipeline) passes `sl_price`/`tp_price`.
     *
     * @param array{broker_order_id:string, symbol?:?string, sl_price?:?float, tp_price?:?float} $modification
     */
    public function modifyOrder(array $credentials, array $modification): array
    {
        $orderId = (string) ($modification['broker_order_id'] ?? '');
        $symbol = $modification['symbol'] ?? null;
        if ($orderId === '' || !$symbol) {
            throw new \App\Exceptions\BrokerOrderException(
                'BingX modifyOrder requires broker_order_id and symbol',
                'UNSUPPORTED_ORDER',
            );
        }

        $params = ['symbol' => $symbol, 'orderId' => $orderId];
        if (!empty($modification['sl_price'])) {
            $params['stopLoss'] = json_encode([
                'type' => 'STOP_MARKET',
                'stopPrice' => (float) $modification['sl_price'],
                'workingType' => 'MARK_PRICE',
            ]);
        }
        if (!empty($modification['tp_price'])) {
            $params['takeProfit'] = json_encode([
                'type' => 'TAKE_PROFIT_MARKET',
                'stopPrice' => (float) $modification['tp_price'],
                'workingType' => 'MARK_PRICE',
            ]);
        }
        if (!isset($params['stopLoss']) && !isset($params['takeProfit'])) {
            throw new \App\Exceptions\BrokerOrderException(
                'BingX modifyOrder needs at least sl_price or tp_price',
                'UNSUPPORTED_ORDER',
            );
        }

        $data = $this->httpPostSigned('/openApi/swap/v2/trade/order', $params, $credentials, 'PUT');
        $orderData = (is_array($data) && isset($data['order']) && is_array($data['order'])) ? $data['order'] : (array) $data;

        return ['status' => $orderData['status'] ?? null, 'raw' => $orderData];
    }

    /**
     * Issue a signed POST/DELETE/PUT to BingX. Body params are signed the same
     * way as GET (canonical key=value& string, ksorted, raw values) but
     * delivered as a query string so the signature stays consistent across
     * all verbs.
     */
    private function httpPostSigned(string $path, array $params, array $credentials, string $method = 'POST'): mixed
    {
        $apiKey = $credentials['api_key'] ?? null;
        $apiSecret = $credentials['api_secret'] ?? null;
        if (!$apiKey || !$apiSecret) {
            throw new \App\Exceptions\BrokerOrderException(
                'BingX credentials missing api_key/api_secret',
                'INVALID_CREDENTIALS',
            );
        }

        // Same canonical-order bug as httpGetSigned: BingX rebuilds canonical
        // from URL-received order, so sort params BEFORE signing AND before
        // handing them to Guzzle, then append signature last (BingX strips
        // it before rebuilding so its trailing position is fine).
        $timestampMs = (int) (microtime(true) * 1000);
        $params['recvWindow'] = (string) 5000;
        $params['timestamp'] = (string) $timestampMs;
        ksort($params);
        $canonical = $this->canonical($params);
        $signature = hash_hmac('sha256', $canonical, $apiSecret);
        $params['signature'] = $signature;

        try {
            $response = $this->httpClient->request($method, $this->baseUrl . $path, [
                'query' => $params,
                'headers' => [
                    'X-BX-APIKEY' => $apiKey,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logFailure($path, $canonical, $timestampMs, null, null, ['transport' => $e->getMessage()]);
            throw new \App\Exceptions\BrokerOrderException(
                "BingX HTTP error: {$e->getMessage()}",
                'TRANSPORT_ERROR',
                [],
                $e,
            );
        }

        $decoded = json_decode($response->getBody()->getContents(), true) ?: [];
        $code = (int) ($decoded['code'] ?? 0);
        if ($code !== 0) {
            $this->logFailure($path, $canonical, $timestampMs, $response->getHeaderLine('Date'), $code, $decoded);
            throw new \App\Exceptions\BrokerOrderException(
                ($decoded['msg'] ?? "BingX API error (code {$code})") . ' [' . $this->codeHint($code) . ']',
                (string) $code,
                $decoded,
            );
        }

        return $decoded['data'] ?? null;
    }

    /**
     * Issue a signed GET to BingX. Adds timestamp + signature, sets API
     * key header. Throws RuntimeException on transport error or BingX
     * business error (non-zero `code`).
     *
     * Returns the unwrapped `data` payload (BingX wraps everything in
     * `{ code, msg, data }`).
     */
    private function httpGetSigned(string $path, array $params, array $credentials): mixed
    {
        $apiKey = $credentials['api_key'] ?? null;
        $apiSecret = $credentials['api_secret'] ?? null;
        if (!$apiKey || !$apiSecret) {
            throw new \RuntimeException('BingX credentials missing api_key/api_secret');
        }

        // CRITICAL: BingX rebuilds its canonical in the order params arrive
        // in the URL — NOT alphabetically. We always ksort()'d when signing
        // (correctly producing an alphabetical canonical) but then handed
        // the *unsorted* array to Guzzle, which serialised in insertion
        // order. Result: single-param endpoints like /v2/user/positions
        // happened to work (insertion == alphabetical with one key), but
        // multi-param endpoints like /v1/trade/positionHistory had the
        // canonical we signed diverge from what BingX rebuilt → generic
        // 100001 signature mismatch. Sort BEFORE both signing and the HTTP
        // call so the URL order and the signed canonical are byte-identical.
        $timestampMs = (int) (microtime(true) * 1000);
        $params['recvWindow'] = (string) 5000;
        $params['timestamp'] = (string) $timestampMs;
        ksort($params);
        $canonical = $this->canonical($params);
        $signature = hash_hmac('sha256', $canonical, $apiSecret);
        $params['signature'] = $signature;

        try {
            $response = $this->httpClient->get($this->baseUrl . $path, [
                'query' => $params,
                'headers' => [
                    'X-BX-APIKEY' => $apiKey,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logFailure($path, $canonical, $timestampMs, null, null, ['transport' => $e->getMessage()], $signature, strlen($apiSecret), null);
            throw new \RuntimeException("BingX HTTP error: {$e->getMessage()}", 0, $e);
        }

        $decoded = json_decode($response->getBody()->getContents(), true) ?: [];
        $code = (int) ($decoded['code'] ?? 0);
        if ($code !== 0) {
            $msg = $decoded['msg'] ?? 'unknown';
            $this->logFailure(
                $path,
                $canonical,
                $timestampMs,
                $response->getHeaderLine('Date'),
                $code,
                $decoded,
                $signature,
                strlen($apiSecret),
                (string) $response->getBody(),
            );
            throw new \RuntimeException("BingX API error (code {$code}): {$msg} [" . $this->codeHint($code) . "]");
        }

        return $decoded['data'] ?? null;
    }

    private function logFailure(
        string $path,
        string $canonicalString,
        int $timestampMs,
        ?string $serverDate,
        ?int $bingxCode,
        array $responseBody,
        ?string $signatureHex = null,
        ?int $secretLength = null,
        ?string $rawBody = null,
    ): void {
        BrokerLogger::failure('bingx', 'request_failed', [
            'path' => $path,
            'canonical' => $canonicalString,
            'signature' => $signatureHex,
            'secret_length' => $secretLength,
            'local_time_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) ($timestampMs / 1000)),
            'server_date' => $serverDate,
            'clock_skew_seconds' => BrokerLogger::clockSkewSeconds($serverDate),
            'code' => $bingxCode,
            'msg' => $responseBody['msg'] ?? $responseBody['transport'] ?? null,
            'raw_body' => $rawBody !== null ? mb_substr($rawBody, 0, 500) : null,
        ]);
    }

    /**
     * Human-readable hint for the common BingX error codes so the message
     * surfaced in the UI tells the user where to look (key permissions vs IP
     * whitelist vs signing) instead of just "code 100001".
     */
    private function codeHint(int $code): string
    {
        return match ($code) {
            100001 => 'signing or clock — check server logs for the canonical string and clock_skew_seconds',
            100403 => 'API key lacks permission for this endpoint (enable Perpetual Futures on the key)',
            100410 => 'IP whitelist on the BingX key blocks this host',
            100413 => 'invalid API key',
            default => "unknown — search BingX docs for code {$code}",
        };
    }

    /**
     * Reconstruct the canonical signed string. Used both by sign() during
     * the request and by logFailure() to surface what we actually signed.
     */
    private function canonical(array $params): string
    {
        ksort($params);
        $segments = [];
        foreach ($params as $k => $v) {
            $segments[] = "{$k}={$v}";
        }
        return implode('&', $segments);
    }

    /**
     * Build HMAC-SHA256 over the canonical "key=value&..." string with
     * ASCII-sorted keys and UN-encoded values. BingX is strict on this:
     * any URL-encoding before hashing will fail validation server-side.
     */
    private function sign(array $params, string $secret): string
    {
        return hash_hmac('sha256', $this->canonical($params), $secret);
    }

    /**
     * Extract a unique, sorted list of symbols from /user/positions
     * response so we can drive positionHistory and allOrders queries.
     */
    private function extractActiveSymbols(mixed $openPositionsData): array
    {
        if (!is_array($openPositionsData)) {
            return [];
        }
        $symbols = [];
        foreach ($openPositionsData as $row) {
            if (!empty($row['symbol'])) {
                $symbols[$row['symbol']] = true;
            }
        }
        $list = array_keys($symbols);
        sort($list);
        return $list;
    }

    /**
     * Full symbol discovery for a sync run: union of live positions,
     * historically-active symbols (from /user/income walk), and the
     * symbols_seen set persisted from prior syncs. Memoised per cursor
     * key so the income walk runs once per sync at most.
     *
     * @return string[] Union of symbols to scan for this sync
     */
    private function discoverAllSymbols(array $credentials, ?int $cursorMs, int $now): array
    {
        $key = (string) $cursorMs;
        if ($this->cachedSymbols !== null && $this->cachedSymbolsCursorKey === $key) {
            return $this->cachedSymbols;
        }

        $openPositionsRaw = $this->httpGetSigned('/openApi/swap/v2/user/positions', [], $credentials);
        $liveSymbols = $this->extractActiveSymbols($openPositionsRaw);
        $incomeSymbols = $this->discoverSymbolsFromIncome($credentials, $cursorMs, $now);

        $union = array_values(array_unique(array_merge($liveSymbols, $incomeSymbols, $this->knownSymbols)));
        sort($union);

        $this->cachedSymbols = $union;
        $this->cachedSymbolsCursorKey = $key;
        $this->cachedLiveSnapshot = $openPositionsRaw;
        return $union;
    }

    /**
     * Walk a BingX history endpoint backwards in CHUNK_WINDOW_SECONDS windows,
     * newest-first, invoking $handle() on each chunk's decoded `data` payload.
     *
     * Stop rules:
     *  - Incremental sync ($cursorMs !== null): walk down to the cursor, never
     *    past it. Empty windows do NOT stop the walk — a 7-day slice can be
     *    empty while activity sits closer to the cursor.
     *  - First sync ($cursorMs === null): walk back TOLERATING empty windows
     *    until $maxEmptyChunks CONSECUTIVE empties (origin / retention horizon
     *    reached), bounded by FIRST_SYNC_MAX_LOOKBACK_SECONDS as a backstop.
     *    This replaces the old "stop at the first empty window" rule, which
     *    made a single quiet recent week hide the entire account history.
     *
     * $handle receives the raw decoded payload and returns the number of raw
     * items it saw (0 = empty window, drives the consecutive-empty counter).
     *
     * @param array<string,string> $baseParams Endpoint params besides the time
     *        window (e.g. `symbol`, `limit`). startTime/endTime are added here.
     * @param callable(mixed):int $handle
     */
    private function walkChunks(
        string $path,
        array $baseParams,
        array $credentials,
        ?int $cursorMs,
        int $now,
        callable $handle
    ): void {
        $floorMs = $cursorMs ?? ($now - self::FIRST_SYNC_MAX_LOOKBACK_SECONDS * 1000);
        $chunkEnd = $now;
        $consecutiveEmpty = 0;

        while ($chunkEnd > $floorMs) {
            $chunkStart = $chunkEnd - self::CHUNK_WINDOW_SECONDS * 1000 + 1;
            $chunkStart = max($floorMs + 1, $chunkStart);
            if ($chunkStart >= $chunkEnd) {
                break;
            }

            $data = $this->httpGetSigned($path, $baseParams + [
                'startTime' => (string) $chunkStart,
                'endTime' => (string) $chunkEnd,
            ], $credentials);

            $count = $handle($data);

            if ($count === 0) {
                if ($cursorMs === null && ++$consecutiveEmpty >= $this->maxEmptyChunks) {
                    break;
                }
            } else {
                $consecutiveEmpty = 0;
            }

            $chunkEnd = $chunkStart - 1;
        }
    }

    /**
     * Discover all symbols the user has ever had trading activity on, by
     * walking /openApi/swap/v2/user/income (P&L, commission, funding fee
     * records). Called WITHOUT a `symbol` param, BingX returns income
     * records across every symbol in one call — exactly what we need to
     * enumerate the historical breadth of an account.
     *
     * Chunk-walked in 7-day windows (BingX caps every history endpoint at
     * 7 days per request, code 109400). Walks to cursor on incremental
     * syncs, to the first empty chunk on first sync. No arbitrary cap on
     * lookback — the broker's empty response is the only stop signal.
     *
     * @return string[] Unique symbols observed in income records
     */
    private function discoverSymbolsFromIncome(array $credentials, ?int $cursorMs, int $now): array
    {
        $found = [];

        $this->walkChunks(
            '/openApi/swap/v2/user/income',
            ['limit' => '1000'],
            $credentials,
            $cursorMs,
            $now,
            function (mixed $data) use (&$found): int {
                // Response shape: array of income records (no wrapping under
                // "incomes" or similar at the time of writing). Tolerate both
                // for safety.
                $list = is_array($data) ? $data : ($data['incomes'] ?? []);
                foreach ($list as $row) {
                    $symbol = $row['symbol'] ?? '';
                    if ($symbol !== '') {
                        $found[$symbol] = true;
                    }
                }
                return count($list);
            },
        );

        $out = array_keys($found);
        sort($out);
        return $out;
    }

}
