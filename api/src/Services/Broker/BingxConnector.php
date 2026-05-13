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
                $data = $this->httpGetSigned(
                    '/openApi/swap/v1/trade/positionHistory',
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

        $params['timestamp'] = (string) ((int) (microtime(true) * 1000));
        $params['signature'] = $this->sign($params, $apiSecret);

        try {
            $response = $this->httpClient->get($this->baseUrl . $path, [
                'query' => $params,
                'headers' => [
                    'X-BX-APIKEY' => $apiKey,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("BingX HTTP error: {$e->getMessage()}", 0, $e);
        }

        $decoded = json_decode($response->getBody()->getContents(), true) ?: [];
        $code = (int) ($decoded['code'] ?? 0);
        if ($code !== 0) {
            $msg = $decoded['msg'] ?? 'unknown';
            throw new \RuntimeException("BingX API error (code {$code}): {$msg}");
        }

        return $decoded['data'] ?? null;
    }

    /**
     * Build HMAC-SHA256 over the canonical "key=value&..." string with
     * ASCII-sorted keys and UN-encoded values. BingX is strict on this:
     * any URL-encoding before hashing will fail validation server-side.
     */
    private function sign(array $params, string $secret): string
    {
        ksort($params);
        $segments = [];
        foreach ($params as $k => $v) {
            $segments[] = "{$k}={$v}";
        }
        return hash_hmac('sha256', implode('&', $segments), $secret);
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
