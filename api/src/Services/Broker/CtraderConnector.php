<?php

namespace App\Services\Broker;

use GuzzleHttp\Client as HttpClient;
use WebSocket\Client as WsClient;

class CtraderConnector implements ConnectorInterface
{
    private array $config;
    private ?WsClient $wsClient;
    private ?HttpClient $httpClient;

    public function __construct(array $config, ?WsClient $wsClient = null, ?HttpClient $httpClient = null)
    {
        $this->config = $config;
        $this->wsClient = $wsClient;
        $this->httpClient = $httpClient;
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        $ws = $this->connectWebSocket();

        try {
            // 1. Application auth (credentials provided by user)
            $this->sendAndReceive($ws, 'ProtoOAApplicationAuthReq', [
                'clientId' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
                'clientSecret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
            ]);

            // 2. Account auth
            $this->sendAndReceive($ws, 'ProtoOAAccountAuthReq', [
                'ctidTraderAccountId' => $credentials['ctid_trader_account_id'],
                'accessToken' => $credentials['access_token'],
            ]);

            // 3. Fetch deals with pagination
            $allDeals = [];
            $fromTimestamp = $sinceCursor
                ? (int) (strtotime($sinceCursor) * 1000)
                : (int) ((time() - 90 * 86400) * 1000);
            $toTimestamp = (int) (time() * 1000);
            $maxRows = 1000;

            do {
                $response = $this->sendAndReceive($ws, 'ProtoOADealListReq', [
                    'ctidTraderAccountId' => $credentials['ctid_trader_account_id'],
                    'fromTimestamp' => $fromTimestamp,
                    'toTimestamp' => $toTimestamp,
                    'maxRows' => $maxRows,
                ]);

                $deals = $response['deal'] ?? [];
                $allDeals = array_merge($allDeals, $deals);
                $hasMore = $response['hasMore'] ?? false;

                if ($hasMore && !empty($deals)) {
                    $lastTimestamp = end($deals)['executionTimestamp'] ?? $toTimestamp;
                    $fromTimestamp = $lastTimestamp + 1;
                }
            } while ($hasMore);

            // 4. Resolve symbol IDs to names
            $symbolIds = array_unique(array_column($allDeals, 'symbolId'));
            $symbolMap = $this->resolveSymbolNames($ws, $credentials['ctid_trader_account_id'], $symbolIds);

            foreach ($allDeals as &$deal) {
                $deal['symbolName'] = $symbolMap[$deal['symbolId']] ?? 'UNKNOWN_' . $deal['symbolId'];
            }
            unset($deal);

            $ws->close();
        } catch (\Throwable $e) {
            try { $ws->close(); } catch (\Throwable) {}
            throw $e;
        }

        $normalized = $this->normalizeDeals($allDeals);

        $latestTimestamp = null;
        foreach ($normalized as $deal) {
            if ($deal['closed_at'] > $latestTimestamp) {
                $latestTimestamp = $deal['closed_at'];
            }
        }

        return [
            'deals' => $normalized,
            'cursor' => $latestTimestamp,
            'raw_count' => count($allDeals),
        ];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        // cTrader open positions are exposed over the OpenAPI ProtoOA stream
        // (ProtoOAReconcileReq → positions[]). Not wired yet for the live
        // sync — the broker-sync feature only consumes closed positions on
        // this connector for now. Returning an empty snapshot is safe: the
        // diff service treats it as "no broker open positions for this run"
        // and never deletes journal-side rows just because a connector is
        // silent.
        return ['positions' => [], 'raw_count' => 0];
    }

    public function fetchOpenOrders(array $credentials): array
    {
        // Same story as fetchOpenPositions for cTrader — pending orders are
        // reachable via ProtoOAReconcileReq but not wired here yet.
        return ['orders' => [], 'raw_count' => 0];
    }

    public function fetchClosedOrders(array $credentials): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function refreshCredentials(array $credentials): array
    {
        if (empty($credentials['refresh_token'])) {
            return $credentials;
        }

        $http = $this->httpClient ?? new HttpClient();

        $response = $http->get($this->config['oauth_token_url'], [
            'query' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $credentials['refresh_token'],
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ],
        ]);

        $tokens = json_decode($response->getBody()->getContents(), true);

        if (!isset($tokens['accessToken'])) {
            throw new \RuntimeException('cTrader token refresh failed');
        }

        $credentials['access_token'] = $tokens['accessToken'];
        if (isset($tokens['refreshToken'])) {
            $credentials['refresh_token'] = $tokens['refreshToken'];
        }

        return $credentials;
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $ws = $this->connectWebSocket();
            $this->sendAndReceive($ws, 'ProtoOAApplicationAuthReq', [
                'clientId' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
                'clientSecret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
            ]);
            $this->sendAndReceive($ws, 'ProtoOAAccountAuthReq', [
                'ctidTraderAccountId' => $credentials['ctid_trader_account_id'],
                'accessToken' => $credentials['access_token'],
            ]);
            $ws->close();
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
        $orderTypeProto = match ($orderType) {
            'MARKET' => 'MARKET',
            'LIMIT' => 'LIMIT',
            'STOP' => 'STOP',
            default => throw new \App\Exceptions\BrokerOrderException(
                "Unsupported order_type {$orderType}",
                'UNSUPPORTED_ORDER',
            ),
        };

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($order, $direction, $orderTypeProto) {
            $symbolId = $this->resolveSymbolId($ws, $accountId, (string) $order['symbol']);

            // cTrader expresses volume in 1/100 lots (one lot = 100k for FX,
            // 100 for index CFDs etc.). The caller passes a fractional lot
            // ("size = 0.10") so we multiply by 100 and floor to int.
            $volume = (int) floor(((float) $order['size']) * 100);
            if ($volume <= 0) {
                throw new \App\Exceptions\BrokerOrderException(
                    'cTrader requires a positive integer volume (100 = 0.01 lot)',
                    'INVALID_VOLUME',
                );
            }

            $payload = [
                'ctidTraderAccountId' => $accountId,
                'symbolId' => $symbolId,
                'orderType' => $orderTypeProto,
                'tradeSide' => $direction,
                'volume' => $volume,
            ];

            if ($orderTypeProto !== 'MARKET' && !empty($order['entry_price'])) {
                $payload[$orderTypeProto === 'LIMIT' ? 'limitPrice' : 'stopPrice'] = (float) $order['entry_price'];
            }
            if (!empty($order['sl_price'])) {
                $payload['stopLoss'] = (float) $order['sl_price'];
            }
            if (!empty($order['tp_prices'][0])) {
                $payload['takeProfit'] = (float) $order['tp_prices'][0];
            }
            if (!empty($order['client_order_id'])) {
                $payload['comment'] = mb_substr((string) $order['client_order_id'], 0, 256);
            }

            $response = $this->sendAndReceive($ws, 'ProtoOANewOrderReq', $payload);

            // cTrader acknowledges with ProtoOAExecutionEvent carrying an order
            // sub-object. The orderId lives at executionEvent.order.orderId.
            $event = $response['order'] ?? $response['executionEvent']['order'] ?? null;
            $orderId = $event['orderId'] ?? $response['orderId'] ?? null;
            if ($orderId === null) {
                throw new \App\Exceptions\BrokerOrderException(
                    'cTrader did not return an orderId in ProtoOAExecutionEvent',
                    'NO_ORDER_ID',
                    is_array($response) ? $response : [],
                );
            }

            return [
                'external_order_id' => (string) $orderId,
                'status' => $event['orderStatus'] ?? null,
                'raw' => $response,
            ];
        });
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($externalOrderId) {
            $response = $this->sendAndReceive($ws, 'ProtoOACancelOrderReq', [
                'ctidTraderAccountId' => $accountId,
                'orderId' => (int) $externalOrderId,
            ]);
            $event = $response['order'] ?? $response['executionEvent']['order'] ?? [];
            return ['status' => $event['orderStatus'] ?? null, 'raw' => $response];
        });
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($externalPositionId, $sizeOverride) {
            $payload = [
                'ctidTraderAccountId' => $accountId,
                'positionId' => (int) $externalPositionId,
            ];
            // 0 = full close in ProtoOAClosePositionReq semantics — but we
            // forward an explicit volume when the caller wants partial close.
            if ($sizeOverride !== null) {
                $partial = (int) floor($sizeOverride * 100);
                if ($partial <= 0) {
                    throw new \App\Exceptions\BrokerOrderException(
                        'cTrader partial close requires a positive volume',
                        'INVALID_VOLUME',
                    );
                }
                $payload['volume'] = $partial;
            }

            $response = $this->sendAndReceive($ws, 'ProtoOAClosePositionReq', $payload);
            return ['status' => $response['executionEvent']['executionType'] ?? null, 'raw' => $response];
        });
    }

    /**
     * Open a WebSocket, run the two-step auth dance (application then account)
     * and pass the live session to the caller. Closes the socket on success
     * AND on any throwable, including BrokerOrderException. Centralises the
     * session boilerplate so the three outbound order methods stay compact.
     */
    private function withAuthenticatedSession(array $credentials, callable $callback): array
    {
        $accountId = $credentials['ctid_trader_account_id'] ?? null;
        $accessToken = $credentials['access_token'] ?? null;
        if (!$accountId || !$accessToken) {
            throw new \App\Exceptions\BrokerOrderException(
                'cTrader credentials missing ctid_trader_account_id/access_token',
                'INVALID_CREDENTIALS',
            );
        }

        try {
            $ws = $this->connectWebSocket();
        } catch (\Throwable $e) {
            throw new \App\Exceptions\BrokerOrderException(
                'cTrader WebSocket connect failed: ' . $e->getMessage(),
                'TRANSPORT_ERROR',
                [],
                $e,
            );
        }

        try {
            $this->sendAndReceive($ws, 'ProtoOAApplicationAuthReq', [
                'clientId' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
                'clientSecret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
            ]);
            $this->sendAndReceive($ws, 'ProtoOAAccountAuthReq', [
                'ctidTraderAccountId' => (int) $accountId,
                'accessToken' => $accessToken,
            ]);

            $result = $callback($ws, (int) $accountId);
            try { $ws->close(); } catch (\Throwable) {}
            return $result;
        } catch (\App\Exceptions\BrokerOrderException $e) {
            try { $ws->close(); } catch (\Throwable) {}
            throw $e;
        } catch (\Throwable $e) {
            try { $ws->close(); } catch (\Throwable) {}
            throw new \App\Exceptions\BrokerOrderException(
                'cTrader request failed: ' . $e->getMessage(),
                'BROKER_REJECTED',
                [],
                $e,
            );
        }
    }

    private function resolveSymbolId(WsClient $ws, int $accountId, string $symbolName): int
    {
        // ProtoOASymbolsListReq returns every tradable symbol on the account
        // with its numeric id. We cache nothing here — placeOrder is rare
        // enough that the extra round-trip is acceptable, and it keeps the
        // connector stateless.
        $response = $this->sendAndReceive($ws, 'ProtoOASymbolsListReq', [
            'ctidTraderAccountId' => $accountId,
        ]);
        $target = strtoupper($symbolName);
        foreach ($response['symbol'] ?? [] as $symbol) {
            if (strtoupper((string) ($symbol['symbolName'] ?? '')) === $target) {
                return (int) $symbol['symbolId'];
            }
        }
        throw new \App\Exceptions\BrokerOrderException(
            "cTrader symbol '{$symbolName}' not found on account {$accountId}",
            'UNKNOWN_SYMBOL',
        );
    }

    /**
     * Normalize raw cTrader deals into import format.
     */
    public function normalizeDeals(array $rawDeals): array
    {
        $normalizer = new DealNormalizer();
        $deals = [];

        foreach ($rawDeals as $deal) {
            $normalized = $normalizer->normalizeCtraderDeal($deal);
            if ($normalized !== null) {
                $deals[] = $normalized;
            }
        }

        return $deals;
    }

    /**
     * Build a JSON message for the cTrader Open API.
     */
    public function buildMessage(string $payloadType, array $payload = []): string
    {
        return json_encode(array_merge(['payloadType' => $payloadType], $payload));
    }

    private function connectWebSocket(): WsClient
    {
        if ($this->wsClient) {
            return $this->wsClient;
        }

        $host = $this->config['ws_host'] ?? 'live.ctraderapi.com';
        $port = $this->config['ws_port'] ?? 5036;

        return new WsClient("wss://{$host}:{$port}", [
            'timeout' => 30,
        ]);
    }

    private function sendAndReceive(WsClient $ws, string $payloadType, array $payload = []): array
    {
        $ws->text($this->buildMessage($payloadType, $payload));
        $response = $ws->receive();

        $decoded = json_decode($response, true);
        if (!$decoded) {
            throw new \RuntimeException("Invalid response from cTrader API for $payloadType");
        }

        if (isset($decoded['payloadType']) && $decoded['payloadType'] === 'ProtoOAErrorRes') {
            $errorCode = $decoded['errorCode'] ?? 'UNKNOWN';
            $description = $decoded['description'] ?? '';
            throw new \RuntimeException("cTrader API error: $errorCode - $description");
        }

        return $decoded;
    }

    private function resolveSymbolNames(WsClient $ws, int $accountId, array $symbolIds): array
    {
        if (empty($symbolIds)) {
            return [];
        }

        $response = $this->sendAndReceive($ws, 'ProtoOASymbolByIdReq', [
            'ctidTraderAccountId' => $accountId,
            'symbolId' => $symbolIds,
        ]);

        $map = [];
        foreach ($response['symbol'] ?? [] as $symbol) {
            $map[$symbol['symbolId']] = $symbol['symbolName'] ?? ('SYM_' . $symbol['symbolId']);
        }

        return $map;
    }
}
