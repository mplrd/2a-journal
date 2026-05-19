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

    /** Max-span limit imposed by BingX positionHistory (3 months). */
    private const MAX_HISTORY_WINDOW_SECONDS = 90 * 24 * 3600;

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

        $startTs = $this->resolveStartTs($sinceCursor);
        $endTs = (int) (microtime(true) * 1000);

        foreach ($symbols as $symbol) {
            $pageIndex = 1;
            do {
                // /v1/trade/positionHistory was the original endpoint (doc 64,
                // Phase 1) and worked at integration time, but BingX silently
                // deprecated it: every call now returns a generic 100001
                // "signature mismatch" (their catch-all for endpoints they no
                // longer route). Migrated to /v2/trade/positionHistory which
                // accepts the same parameter shape; if BingX has reshuffled
                // further, we'll get a different, more actionable error code
                // surfaced via BrokerLogger.
                $data = $this->httpGetSigned(
                    '/openApi/swap/v2/trade/positionHistory',
                    [
                        'symbol' => $symbol,
                        'startTs' => (string) $startTs,
                        'endTs' => (string) $endTs,
                        'pageIndex' => (string) $pageIndex,
                        'pageSize' => (string) self::PAGE_SIZE,
                    ],
                    $credentials,
                );

                $list = $data['list'] ?? [];
                $rawCount += count($list);

                foreach ($list as $raw) {
                    $closeTime = (int) ($raw['closeTime'] ?? $raw['updateTime'] ?? 0);

                    if ($closeTime > 0 && ($latestCloseTime === null || $closeTime > $latestCloseTime)) {
                        $latestCloseTime = $closeTime;
                    }

                    if ($sinceCursor !== null && $closeTime > 0 && $closeTime <= (int) $sinceCursor) {
                        continue;
                    }

                    $normalized = $this->normalizer->normalizeBingxClosedPosition($raw);
                    if ($normalized !== null) {
                        $deals[] = $normalized;
                    }
                }

                $pageIndex++;
            } while (count($list) === self::PAGE_SIZE);
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

        $endTs = (int) (microtime(true) * 1000);
        $startTs = $endTs - self::MAX_HISTORY_WINDOW_SECONDS * 1000;

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

        $timestampMs = (int) (microtime(true) * 1000);
        $params['timestamp'] = (string) $timestampMs;
        $signature = $this->sign($params, $apiSecret);
        $canonical = $this->canonical($params);
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

        $timestampMs = (int) (microtime(true) * 1000);
        $params['timestamp'] = (string) $timestampMs;
        $signature = $this->sign($params, $apiSecret);
        $canonical = $this->canonical($params);
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
            $this->logFailure($path, $canonical, $timestampMs, null, null, ['transport' => $e->getMessage()]);
            throw new \RuntimeException("BingX HTTP error: {$e->getMessage()}", 0, $e);
        }

        $decoded = json_decode($response->getBody()->getContents(), true) ?: [];
        $code = (int) ($decoded['code'] ?? 0);
        if ($code !== 0) {
            $msg = $decoded['msg'] ?? 'unknown';
            $this->logFailure($path, $canonical, $timestampMs, $response->getHeaderLine('Date'), $code, $decoded);
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
    ): void {
        BrokerLogger::failure('bingx', 'request_failed', [
            'path' => $path,
            'canonical' => $canonicalString,
            'local_time_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) ($timestampMs / 1000)),
            'server_date' => $serverDate,
            'clock_skew_seconds' => BrokerLogger::clockSkewSeconds($serverDate),
            'code' => $bingxCode,
            'msg' => $responseBody['msg'] ?? $responseBody['transport'] ?? null,
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

    private function resolveStartTs(?string $sinceCursor): int
    {
        $now = (int) (microtime(true) * 1000);
        // Default lookback: 3 months (BingX max window).
        $defaultStart = $now - self::MAX_HISTORY_WINDOW_SECONDS * 1000;

        if ($sinceCursor === null) {
            return $defaultStart;
        }

        $cursor = (int) $sinceCursor;
        // Clamp to the broker's max window — we can't query further back
        // than 3 months even if the cursor is older.
        return max($cursor, $defaultStart);
    }
}
