<?php

namespace App\Services\Broker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * BingX USDT-M Perpetual Futures connector.
 *
 * Scope (Phase 1): USDT-M Perpetual only. Coin-M and Standard Contracts
 * are out of scope on this branch because they require synthesizing
 * closed positions from order fills (no native `positionHistory`
 * endpoint) — equivalent in effort to the Ouinex spot Phase 2 chantier.
 *
 * Auth: HMAC-SHA256 over ASCII-sorted "key=value&..." canonical string,
 * unencoded. Signature is appended to the query as `&signature=<hex>`.
 * `X-BX-APIKEY` header carries the key. No JWT cycle — refreshCredentials
 * is a no-op (API key/secret are static credentials).
 */
class BingxConnector implements ConnectorInterface
{
    /** Page size for positionHistory + allOrders pagination. */
    private const PAGE_SIZE = 100;

    /**
     * Max span BingX allows per request on positionHistory / allOrders
     * (server-side, returns code 109400 above this). We chunk our actual
     * lookback into windows of this size — this is a broker constraint,
     * not a product choice.
     */
    private const CHUNK_WINDOW_SECONDS = 7 * 24 * 3600;

    private Client $httpClient;
    private string $baseUrl;
    private DealNormalizer $normalizer;

    public function __construct(Client $httpClient, string $baseUrl)
    {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->normalizer = new DealNormalizer();
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        // Step 1: enumerate symbols the user is currently active on. BingX
        // requires `symbol` per positionHistory call, so we use open
        // positions as the symbol seed. Note the limitation: a symbol the
        // user fully closed before the previous sync cursor won't be
        // re-scanned here. The cursor + 3-month window bounds the gap in
        // practice.
        $openPositions = $this->httpGetSigned(
            '/openApi/swap/v2/user/positions',
            [],
            $credentials,
        );
        $symbols = $this->extractActiveSymbols($openPositions);

        if (empty($symbols)) {
            return ['deals' => [], 'cursor' => null, 'raw_count' => 0];
        }

        $deals = [];
        $rawCount = 0;
        $latestCloseTime = null;

        $now = (int) (microtime(true) * 1000);
        $cursorMs = $sinceCursor !== null ? (int) $sinceCursor : null;

        // BingX caps each positionHistory request at 7 days (code 109400),
        // so we chunk. Two distinct stop policies depending on whether the
        // sync has a cursor:
        //
        //   - With cursor (steady-state sync): walk back exactly to cursor,
        //     keep going through empty chunks. A dormant account is still
        //     a live account — empty windows are "no opportunities this
        //     week", not "account gone".
        //
        //   - Without cursor (first sync): walk back as far as the account
        //     has history. Stop at the first empty chunk — that's BingX
        //     telling us there's no more data older than this point.
        foreach ($symbols as $symbol) {
            $chunkEnd = $now;

            while (true) {
                $chunkStart = $chunkEnd - self::CHUNK_WINDOW_SECONDS * 1000 + 1;
                if ($cursorMs !== null) {
                    $chunkStart = max($cursorMs + 1, $chunkStart);
                }
                if ($chunkStart >= $chunkEnd) {
                    break;
                }

                $data = $this->httpGetSigned(
                    '/openApi/swap/v1/trade/positionHistory',
                    [
                        'symbol' => $symbol,
                        'startTs' => (string) $chunkStart,
                        'endTs' => (string) $chunkEnd,
                        'pageSize' => (string) self::PAGE_SIZE,
                    ],
                    $credentials,
                );

                $list = $data['list'] ?? [];
                $rawCount += count($list);

                if (empty($list) && $cursorMs === null) {
                    break;
                }

                foreach ($list as $raw) {
                    $closeTime = (int) ($raw['closeTime'] ?? $raw['updateTime'] ?? 0);

                    if ($closeTime > 0 && ($latestCloseTime === null || $closeTime > $latestCloseTime)) {
                        $latestCloseTime = $closeTime;
                    }

                    if ($cursorMs !== null && $closeTime > 0 && $closeTime <= $cursorMs) {
                        continue;
                    }

                    $normalized = $this->normalizer->normalizeBingxClosedPosition($raw);
                    if ($normalized !== null) {
                        $deals[] = $normalized;
                    }
                }

                // Step the window back by one chunk; the +1ms boundary
                // guarantees no overlap (closeTime is strictly within
                // (startTs, endTs] per BingX semantics).
                $chunkEnd = $chunkStart - 1;
            }
        }

        return [
            'deals' => $deals,
            'cursor' => $latestCloseTime !== null ? (string) $latestCloseTime : null,
            'raw_count' => $rawCount,
        ];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        $data = $this->httpGetSigned('/openApi/swap/v2/user/positions', [], $credentials);

        $positions = [];
        $rawCount = is_array($data) ? count($data) : 0;
        foreach (($data ?? []) as $raw) {
            $normalized = $this->normalizer->normalizeBingxOpenPosition($raw);
            if ($normalized !== null) {
                $positions[] = $normalized;
            }
        }

        return ['positions' => $positions, 'raw_count' => $rawCount];
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

    public function fetchClosedOrders(array $credentials): array
    {
        // Note: allOrders also requires `symbol` per BingX rules. For
        // Phase 1 we query the same symbol set as fetchDeals (the user's
        // currently active ones). If we needed broader coverage we'd
        // persist a "symbols seen" set; out of scope here.
        $openPositions = $this->httpGetSigned('/openApi/swap/v2/user/positions', [], $credentials);
        $symbols = $this->extractActiveSymbols($openPositions);

        if (empty($symbols)) {
            return ['orders' => [], 'raw_count' => 0];
        }

        // Single 7-day window: BingX caps each /trade/allOrders call at
        // 7 days (code 109400). fetchClosedOrders is consumed only by the
        // order diff (matching orders that disappeared from openOrders
        // between two syncs), so any horizon longer than the sync interval
        // is wasted work. Cron runs every 5 min → 7 days covers any
        // realistic gap between two ticks, and the BingX cap matches.
        $endTs = (int) (microtime(true) * 1000);
        $startTs = $endTs - self::CHUNK_WINDOW_SECONDS * 1000;

        $orders = [];
        $rawCount = 0;

        foreach ($symbols as $symbol) {
            $data = $this->httpGetSigned(
                '/openApi/swap/v2/trade/allOrders',
                [
                    'symbol' => $symbol,
                    'startTime' => (string) $startTs,
                    'endTime' => (string) $endTs,
                    'limit' => (string) self::PAGE_SIZE,
                ],
                $credentials,
            );

            $list = $data['orders'] ?? (is_array($data) ? $data : []);
            $rawCount += count($list);

            foreach ($list as $raw) {
                $normalized = $this->normalizer->normalizeBingxClosedOrder($raw);
                if ($normalized !== null) {
                    $orders[] = $normalized;
                }
            }
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
     * Issue a signed POST/DELETE to BingX. Body params are signed the same
     * way as GET (canonical key=value& string, ksorted, raw values) but
     * delivered as a query string so the signature stays consistent across
     * all four verbs.
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

}
