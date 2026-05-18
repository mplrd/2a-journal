<?php

namespace App\Services\Broker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OuinexConnector implements ConnectorInterface
{
    /**
     * Refresh the JWT a minute before its real expiry — keeps a safety margin
     * so a request issued right at the boundary doesn't race the expiration.
     */
    private const JWT_EXPIRY_MARGIN_SECONDS = 60;

    /**
     * Default JWT lifetime when the API doesn't return an `expires_at` we
     * can parse. One hour is a conservative middle ground.
     */
    private const DEFAULT_JWT_TTL_SECONDS = 3600;

    /**
     * Page size for closed_margin_positions pagination. Ouinex's collection
     * shows 101 as a typical example; keep it round at 100 here.
     */
    private const PAGE_LIMIT = 100;

    private Client $httpClient;
    private string $graphqlUrl;
    private DealNormalizer $normalizer;

    public function __construct(Client $httpClient, string $graphqlUrl)
    {
        $this->httpClient = $httpClient;
        $this->graphqlUrl = $graphqlUrl;
        $this->normalizer = new DealNormalizer();
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        $credentials = $this->refreshCredentials($credentials);
        $jwt = $credentials['jwt'];

        $deals = [];
        $rawCount = 0;
        $latestEndTs = null;
        $offset = 0;

        do {
            $response = $this->graphqlRequest($jwt, $this->closedMarginPositionsQuery(), [
                'pager' => ['offset' => $offset, 'limit' => self::PAGE_LIMIT],
            ]);

            $positions = $response['data']['closed_margin_positions'] ?? [];
            $rawCount += count($positions);

            foreach ($positions as $position) {
                $endTs = $position['end_ts'] ?? null;

                // Track the cursor on raw positions (before any client-side
                // filtering) so the next sync can start past everything we've
                // already seen, including positions we explicitly skipped.
                if ($endTs !== null && ($latestEndTs === null || $endTs > $latestEndTs)) {
                    $latestEndTs = $endTs;
                }

                // closed_margin_positions doesn't accept a dateRange filter
                // server-side, so we paginate everything and drop entries
                // we've already imported via cursor comparison.
                if ($sinceCursor !== null && $endTs !== null && $endTs <= $sinceCursor) {
                    continue;
                }

                $normalized = $this->normalizer->normalizeOuinexMarginPosition($position);
                if ($normalized !== null) {
                    $deals[] = $normalized;
                }
            }

            $offset += self::PAGE_LIMIT;
        } while (count($positions) === self::PAGE_LIMIT);

        return [
            'deals' => $deals,
            'cursor' => $latestEndTs,
            'raw_count' => $rawCount,
        ];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        $credentials = $this->refreshCredentials($credentials);
        $jwt = $credentials['jwt'];

        $positions = [];
        $rawCount = 0;
        $offset = 0;

        // open_margin_positions has no cursor concept — it's the current
        // live state. We paginate the full set on every sync; the diff
        // service downstream is what reconciles against journal-side state.
        do {
            $response = $this->graphqlRequest($jwt, $this->openMarginPositionsQuery(), [
                'pager' => ['offset' => $offset, 'limit' => self::PAGE_LIMIT],
            ]);

            $page = $response['data']['open_margin_positions'] ?? [];
            $rawCount += count($page);

            foreach ($page as $raw) {
                $normalized = $this->normalizer->normalizeOuinexOpenMarginPosition($raw);
                if ($normalized !== null) {
                    $positions[] = $normalized;
                }
            }

            $offset += self::PAGE_LIMIT;
        } while (count($page) === self::PAGE_LIMIT);

        return [
            'positions' => $positions,
            'raw_count' => $rawCount,
        ];
    }

    public function fetchOpenOrders(array $credentials): array
    {
        $credentials = $this->refreshCredentials($credentials);
        $jwt = $credentials['jwt'];

        $orders = [];
        $rawCount = 0;
        $offset = 0;

        // open_orders is a full snapshot of pending orders (limit/stop/etc.).
        // No cursor — the broker is the source of truth and the diff service
        // reconciles against journal state on each run.
        do {
            $response = $this->graphqlRequest($jwt, $this->openOrdersQuery(), [
                'pager' => ['offset' => $offset, 'limit' => self::PAGE_LIMIT],
            ]);

            $page = $response['data']['open_orders'] ?? [];
            $rawCount += count($page);

            foreach ($page as $raw) {
                $normalized = $this->normalizer->normalizeOuinexOpenOrder($raw);
                if ($normalized !== null) {
                    $orders[] = $normalized;
                }
            }

            $offset += self::PAGE_LIMIT;
        } while (count($page) === self::PAGE_LIMIT);

        return [
            'orders' => $orders,
            'raw_count' => $rawCount,
        ];
    }

    public function fetchClosedOrders(array $credentials): array
    {
        $credentials = $this->refreshCredentials($credentials);
        $jwt = $credentials['jwt'];

        $orders = [];
        $rawCount = 0;
        $offset = 0;

        // closed_orders is the history of finalized orders (executed,
        // cancelled, expired). Snapshot mode — no cursor. The diff service
        // matches by order_id with the journal's PENDING set.
        do {
            $response = $this->graphqlRequest($jwt, $this->closedOrdersQuery(), [
                'pager' => ['offset' => $offset, 'limit' => self::PAGE_LIMIT],
            ]);

            $page = $response['data']['closed_orders'] ?? [];
            $rawCount += count($page);

            foreach ($page as $raw) {
                $normalized = $this->normalizer->normalizeOuinexClosedOrder($raw);
                if ($normalized !== null) {
                    $orders[] = $normalized;
                }
            }

            $offset += self::PAGE_LIMIT;
        } while (count($page) === self::PAGE_LIMIT);

        return [
            'orders' => $orders,
            'raw_count' => $rawCount,
        ];
    }

    public function refreshCredentials(array $credentials): array
    {
        $jwt = $credentials['jwt'] ?? null;
        $expiresAt = $credentials['jwt_expires_at'] ?? 0;

        // Re-signin if no JWT, or it's expired, or it's about to expire
        // within the safety margin (a 30s-old JWT is treated as expired so
        // the next request doesn't race the real boundary).
        if ($jwt && $expiresAt > time() + self::JWT_EXPIRY_MARGIN_SECONDS) {
            return $credentials;
        }

        return $this->signin($credentials);
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $this->signin($credentials);
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

        $jwt = $this->ensureJwt($credentials);

        $variables = [
            'input' => [
                'instrument_id' => $order['symbol'],
                'side' => $direction === 'BUY' ? 'LONG' : 'SHORT',
                'order_type' => $orderType,
                'amount' => (float) $order['size'],
                'stop_loss' => isset($order['sl_price']) ? (float) $order['sl_price'] : null,
                'take_profit' => isset($order['tp_prices'][0]) ? (float) $order['tp_prices'][0] : null,
                'price' => $orderType !== 'MARKET' && isset($order['entry_price'])
                    ? (float) $order['entry_price']
                    : null,
                'client_order_id' => isset($order['client_order_id'])
                    ? mb_substr((string) $order['client_order_id'], 0, 64)
                    : null,
            ],
        ];

        try {
            $response = $this->graphqlRequest($jwt, $this->placeMarginOrderMutation(), $variables);
        } catch (\RuntimeException $e) {
            throw new \App\Exceptions\BrokerOrderException(
                $e->getMessage(),
                'BROKER_REJECTED',
                [],
                $e,
            );
        }

        $orderData = $response['data']['place_margin_order'] ?? null;
        if (!is_array($orderData) || empty($orderData['margin_order_id'])) {
            throw new \App\Exceptions\BrokerOrderException(
                'Ouinex did not return a margin_order_id',
                'NO_ORDER_ID',
                is_array($response) ? $response : [],
            );
        }

        return [
            'external_order_id' => (string) $orderData['margin_order_id'],
            'status' => $orderData['status'] ?? null,
            'raw' => $orderData,
        ];
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        $jwt = $this->ensureJwt($credentials);

        try {
            $response = $this->graphqlRequest($jwt, $this->cancelMarginOrderMutation(), [
                'margin_order_id' => $externalOrderId,
            ]);
        } catch (\RuntimeException $e) {
            throw new \App\Exceptions\BrokerOrderException(
                $e->getMessage(),
                'BROKER_REJECTED',
                [],
                $e,
            );
        }

        $orderData = $response['data']['cancel_margin_order'] ?? [];
        return ['status' => $orderData['status'] ?? null, 'raw' => $orderData];
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        $jwt = $this->ensureJwt($credentials);

        try {
            $response = $this->graphqlRequest($jwt, $this->closeMarginPositionMutation(), [
                'margin_position_id' => $externalPositionId,
                'amount' => $sizeOverride,
            ]);
        } catch (\RuntimeException $e) {
            throw new \App\Exceptions\BrokerOrderException(
                $e->getMessage(),
                'BROKER_REJECTED',
                [],
                $e,
            );
        }

        $positionData = $response['data']['close_margin_position'] ?? [];
        return ['status' => $positionData['status'] ?? null, 'raw' => $positionData];
    }

    /**
     * Centralise the "no JWT or invalid credentials" branch for the three
     * outbound order methods so they emit the same provider code.
     */
    private function ensureJwt(array $credentials): string
    {
        try {
            $fresh = $this->refreshCredentials($credentials);
        } catch (\Throwable $e) {
            throw new \App\Exceptions\BrokerOrderException(
                'Ouinex credentials invalid: ' . $e->getMessage(),
                'INVALID_CREDENTIALS',
                [],
                $e,
            );
        }
        $jwt = $fresh['jwt'] ?? null;
        if (!$jwt) {
            throw new \App\Exceptions\BrokerOrderException(
                'Ouinex returned no JWT',
                'INVALID_CREDENTIALS',
            );
        }
        return $jwt;
    }

    /**
     * Trade the API key/secret for a fresh JWT via the service_signin
     * mutation. Returns updated credentials with `jwt` and `jwt_expires_at`
     * (Unix timestamp) added.
     */
    private function signin(array $credentials): array
    {
        $apiKey = $credentials['service_api_key'] ?? null;
        $apiSecret = $credentials['service_api_secret'] ?? null;

        if (!$apiKey || !$apiSecret) {
            throw new \RuntimeException('Ouinex credentials missing service_api_key/service_api_secret');
        }

        $response = $this->graphqlRequest(null, $this->serviceSigninMutation(), [
            'service_api_key' => $apiKey,
            'service_api_secret' => $apiSecret,
        ]);

        $token = $response['data']['service_signin']['jwt'] ?? null;
        if (!$token) {
            throw new \RuntimeException('Ouinex service_signin returned no jwt');
        }

        $expiresAtRaw = $response['data']['service_signin']['expires_at'] ?? null;
        $expiresAt = $this->parseExpiresAt($expiresAtRaw);

        return [
            'service_api_key' => $apiKey,
            'service_api_secret' => $apiSecret,
            'jwt' => $token,
            'jwt_expires_at' => $expiresAt,
        ];
    }

    /**
     * @param ?string $jwt Bearer token to attach (omit on signin itself).
     * @param string $query GraphQL query/mutation source.
     * @param array $variables Variables map for the operation.
     * @return array Decoded JSON response.
     * @throws \RuntimeException When the GraphQL response carries `errors`.
     */
    private function graphqlRequest(?string $jwt, string $query, array $variables): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($jwt !== null) {
            $headers['Authorization'] = "Bearer {$jwt}";
        }

        try {
            $response = $this->httpClient->post($this->graphqlUrl, [
                'headers' => $headers,
                'json' => [
                    'query' => $query,
                    'variables' => $variables,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Ouinex HTTP error: {$e->getMessage()}", 0, $e);
        }

        $decoded = json_decode($response->getBody()->getContents(), true) ?: [];

        if (!empty($decoded['errors'])) {
            $first = $decoded['errors'][0]['message'] ?? 'unknown GraphQL error';
            throw new \RuntimeException("Ouinex GraphQL error: {$first}");
        }

        return $decoded;
    }

    private function parseExpiresAt(?string $expiresAt): int
    {
        if (!$expiresAt) {
            return time() + self::DEFAULT_JWT_TTL_SECONDS;
        }
        $ts = strtotime($expiresAt);
        return $ts !== false ? $ts : (time() + self::DEFAULT_JWT_TTL_SECONDS);
    }

    private function serviceSigninMutation(): string
    {
        return <<<'GQL'
        mutation ($service_api_key: String!, $service_api_secret: String!) {
          service_signin(
            service_api_key: $service_api_key
            service_api_secret: $service_api_secret
          ) {
            jwt
            expires_at
          }
        }
        GQL;
    }

    private function placeMarginOrderMutation(): string
    {
        // Field naming follows the same snake_case + lowercase enum
        // convention as closed_margin_positions. The exact mutation signature
        // is not exhaustively documented in the public Ouinex docs — the
        // shape here is best-effort based on the read-side schema and should
        // be adjusted if the broker returns "Cannot query field" errors.
        return <<<'GQL'
        mutation ($input: PlaceMarginOrderInput!) {
          place_margin_order(input: $input) {
            margin_order_id
            margin_position_id
            status
            instrument_id
            side
            amount
            entry_price
          }
        }
        GQL;
    }

    private function cancelMarginOrderMutation(): string
    {
        return <<<'GQL'
        mutation ($margin_order_id: ID!) {
          cancel_margin_order(margin_order_id: $margin_order_id) {
            margin_order_id
            status
          }
        }
        GQL;
    }

    private function closeMarginPositionMutation(): string
    {
        return <<<'GQL'
        mutation ($margin_position_id: ID!, $amount: Float) {
          close_margin_position(margin_position_id: $margin_position_id, amount: $amount) {
            margin_position_id
            status
            exit_price
            pnl
          }
        }
        GQL;
    }

    private function closedMarginPositionsQuery(): string
    {
        return <<<'GQL'
        query ($pager: PagerInput) {
          closed_margin_positions(pager: $pager) {
            margin_position_id
            instrument_id
            side
            leverage
            amount
            entry_price
            exit_price
            pnl
            stop_loss
            take_profit
            start_ts
            end_ts
            close_reason
          }
        }
        GQL;
    }

    private function openMarginPositionsQuery(): string
    {
        // Same shape as closed_margin_positions minus the exit-side fields
        // (exit_price, pnl, end_ts, close_reason) — those don't exist while
        // the position is still live. stop_loss/take_profit are kept so the
        // journal can render the risk plan currently in force.
        return <<<'GQL'
        query ($pager: PagerInput) {
          open_margin_positions(pager: $pager) {
            margin_position_id
            instrument_id
            side
            leverage
            amount
            entry_price
            stop_loss
            take_profit
            start_ts
          }
        }
        GQL;
    }

    private function openOrdersQuery(): string
    {
        // Pending margin orders (limits/stops/conditionals not yet triggered).
        // price = the limit/trigger level. stop_loss/take_profit describe the
        // risk plan the user attached to the order. expires_at is optional
        // (orders without TTL stay open until triggered or cancelled).
        return <<<'GQL'
        query ($pager: PagerInput) {
          open_orders(pager: $pager) {
            order_id
            instrument_id
            side
            order_type
            amount
            price
            stop_loss
            take_profit
            expires_at
            created_at
          }
        }
        GQL;
    }

    private function closedOrdersQuery(): string
    {
        // Closed orders are queried only for their final status — used to
        // disambiguate why an order disappeared from open_orders (executed,
        // cancelled, expired). The journal doesn't store extra closed-order
        // metadata at this stage, so the query stays minimal.
        return <<<'GQL'
        query ($pager: PagerInput) {
          closed_orders(pager: $pager) {
            order_id
            status
            updated_at
          }
        }
        GQL;
    }
}
