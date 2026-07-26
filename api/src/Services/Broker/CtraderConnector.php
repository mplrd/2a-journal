<?php

namespace App\Services\Broker;

use GuzzleHttp\Client as HttpClient;
use WebSocket\Client as WsClient;

class CtraderConnector implements ConnectorInterface
{
    /**
     * ProtoOAPayloadType numeric codes (OpenApiModelMessages.proto). The
     * cTrader JSON API keys every frame by these integers — NOT the message
     * name — with the message fields nested under a `payload` object.
     */
    private const PAYLOAD_TYPES = [
        'ProtoOAApplicationAuthReq' => 2100,
        'ProtoOAAccountAuthReq'     => 2102,
        'ProtoOAReconcileReq'       => 2124,
        'ProtoOATraderReq'          => 2121,
        'ProtoOAOrderListReq'       => 2175,
        'ProtoOADealListReq'        => 2133,
        'ProtoOASymbolsListReq'     => 2114,
        'ProtoOASymbolByIdReq'      => 2116,
        'ProtoOANewOrderReq'        => 2106,
        'ProtoOACancelOrderReq'     => 2108,
        'ProtoOAClosePositionReq'   => 2111,
        'ProtoOAErrorRes'           => 2142,
    ];

    /** Base-protocol heartbeat (ProtoPayloadType.HEARTBEAT_EVENT). */
    private const HEARTBEAT_EVENT = 51;

    private array $config;
    private ?WsClient $wsClient;
    private ?HttpClient $httpClient;
    private int $msgCounter = 0;

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

    /**
     * Live open positions via ProtoOAReconcileReq → position[]. Best-effort:
     * returns an empty snapshot when credentials are missing so the diff
     * service treats it as "no broker open positions this run" rather than
     * fataling.
     */
    public function fetchOpenPositions(array $credentials): array
    {
        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return ['positions' => [], 'raw_count' => 0];
        }

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) {
            $response = $this->sendAndReceive($ws, 'ProtoOAReconcileReq', [
                'ctidTraderAccountId' => $accountId,
            ]);
            $positions = $response['position'] ?? [];

            $symbolMap = $this->resolveSymbolNames($ws, $accountId, $this->collectSymbolIds($positions));

            $normalizer = new DealNormalizer();
            $normalized = [];
            foreach ($positions as $position) {
                $symbolId = $position['tradeData']['symbolId'] ?? null;
                $position['symbolName'] = $symbolMap[$symbolId] ?? ('UNKNOWN_' . $symbolId);
                $row = $normalizer->normalizeCtraderOpenPosition($position);
                if ($row !== null) {
                    $normalized[] = $row;
                }
            }

            return ['positions' => $normalized, 'raw_count' => count($positions)];
        });
    }

    /**
     * Live pending orders via ProtoOAReconcileReq → order[]. Protective
     * closing orders (SL/TP bound to an open position) are excluded — they're
     * already represented via the position's own sl/tp, not standalone
     * pending orders the user placed.
     */
    public function fetchOpenOrders(array $credentials): array
    {
        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return ['orders' => [], 'raw_count' => 0];
        }

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) {
            $response = $this->sendAndReceive($ws, 'ProtoOAReconcileReq', [
                'ctidTraderAccountId' => $accountId,
            ]);
            $rawOrders = $response['order'] ?? [];

            $orders = array_values(array_filter($rawOrders, fn($o) => empty($o['closingOrder'])));

            $symbolMap = $this->resolveSymbolNames($ws, $accountId, $this->collectSymbolIds($orders));

            $normalizer = new DealNormalizer();
            $normalized = [];
            foreach ($orders as $order) {
                $symbolId = $order['tradeData']['symbolId'] ?? null;
                $order['symbolName'] = $symbolMap[$symbolId] ?? ('UNKNOWN_' . $symbolId);
                $row = $normalizer->normalizeCtraderOpenOrder($order);
                if ($row !== null) {
                    $normalized[] = $row;
                }
            }

            return ['orders' => $normalized, 'raw_count' => count($rawOrders)];
        });
    }

    /**
     * Recently-closed orders via ProtoOAOrderListReq (orders in a time
     * window). Only terminal states (filled/cancelled/expired) survive
     * normalization — the order diff uses them to disambiguate why a pending
     * order disappeared, instead of always defaulting to CANCELLED.
     */
    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return ['orders' => [], 'raw_count' => 0];
        }

        return $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) use ($sinceCursor) {
            $fromTimestamp = $sinceCursor
                ? (int) (strtotime($sinceCursor) * 1000)
                : (int) ((time() - 90 * 86400) * 1000);
            $toTimestamp = (int) (time() * 1000);

            $response = $this->sendAndReceive($ws, 'ProtoOAOrderListReq', [
                'ctidTraderAccountId' => $accountId,
                'fromTimestamp' => $fromTimestamp,
                'toTimestamp' => $toTimestamp,
            ]);
            $orders = $response['order'] ?? [];

            $normalizer = new DealNormalizer();
            $normalized = [];
            foreach ($orders as $order) {
                $row = $normalizer->normalizeCtraderClosedOrder($order);
                if ($row !== null) {
                    $normalized[] = $row;
                }
            }

            return ['orders' => $normalized, 'raw_count' => count($orders)];
        });
    }

    /**
     * Collect the distinct, non-null symbolId values from a set of positions
     * or orders (both carry symbolId under tradeData) so we can resolve their
     * names in a single ProtoOASymbolByIdReq.
     *
     * @return int[]
     */
    private function collectSymbolIds(array $entities): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn($e) => $e['tradeData']['symbolId'] ?? null, $entities),
            fn($id) => $id !== null,
        )));
    }

    public function refreshCredentials(array $credentials): array
    {
        if (empty($credentials['refresh_token'])) {
            return $credentials;
        }

        $http = $this->httpClient ?? new HttpClient();

        // The cTrader OAuth app credentials are stored per-connection (the
        // user enters them in the connect dialog), so prefer the credentials'
        // client_id/secret and fall back to config. oauth_token_url falls back
        // to the known cTrader endpoint when not configured.
        $tokenUrl = $this->config['oauth_token_url'] ?? 'https://openapi.ctrader.com/apps/token';
        $response = $http->get($tokenUrl, [
            'query' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $credentials['refresh_token'],
                'client_id' => $credentials['client_id'] ?? $this->config['client_id'] ?? '',
                'client_secret' => $credentials['client_secret'] ?? $this->config['client_secret'] ?? '',
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

    /**
     * Account balance via ProtoOATraderReq → trader. cTrader expresses money
     * as an integer scaled by moneyDigits (e.g. balance 10053099944 with
     * moneyDigits 8 → 100.53099944). Best-effort: any failure returns null so
     * the sync leaves the previous balance alone.
     */
    public function fetchBalance(array $credentials): ?float
    {
        if (empty($credentials['ctid_trader_account_id']) || empty($credentials['access_token'])) {
            return null;
        }

        try {
            $result = $this->withAuthenticatedSession($credentials, function (WsClient $ws, int $accountId) {
                $response = $this->sendAndReceive($ws, 'ProtoOATraderReq', [
                    'ctidTraderAccountId' => $accountId,
                ]);
                $trader = $response['trader'] ?? [];
                if (!isset($trader['balance'])) {
                    return ['balance' => null];
                }
                $moneyDigits = (int) ($trader['moneyDigits'] ?? 2);
                return ['balance' => ((float) $trader['balance']) / (10 ** $moneyDigits)];
            });

            return $result['balance'];
        } catch (\Throwable) {
            return null;
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
            // ProtoOAExecutionEvent carries executionType at the payload root.
            return ['status' => $response['executionType'] ?? $response['executionEvent']['executionType'] ?? null, 'raw' => $response];
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
            BrokerLogger::failure('ctrader', 'ws_connect_failed', [
                'account_id' => (int) $accountId,
                'msg' => $e->getMessage(),
            ]);
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
            BrokerLogger::failure('ctrader', 'request_failed', [
                'account_id' => (int) $accountId,
                'msg' => $e->getMessage(),
            ]);
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
     * Build a JSON message for the cTrader Open API. Wire format:
     * {clientMsgId, payloadType:<int>, payload:{…}} — numeric payloadType and
     * nested payload, per https://help.ctrader.com/open-api/sending-receiving-json/.
     * The (object) cast keeps an empty payload serialized as `{}`, not `[]`.
     */
    public function buildMessage(string $payloadType, array $payload = []): string
    {
        return json_encode([
            'clientMsgId' => (string) (++$this->msgCounter),
            'payloadType' => self::PAYLOAD_TYPES[$payloadType],
            'payload' => (object) $payload,
        ]);
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

        // The Open API is asynchronous: heartbeats (payloadType 51) and other
        // unsolicited events can arrive interleaved. Keep reading until a real
        // response frame arrives rather than mistaking a heartbeat for it.
        while (true) {
            $response = $ws->receive();

            $decoded = json_decode($response, true);
            if (!$decoded) {
                throw new \RuntimeException("Invalid response from cTrader API for $payloadType");
            }

            $type = $decoded['payloadType'] ?? null;
            if ($type === self::HEARTBEAT_EVENT) {
                continue;
            }

            // Every ProtoOA frame nests its fields under `payload`.
            $inner = $decoded['payload'] ?? [];

            // Error detection MUST compare the numeric code — the old string
            // comparison never matched, so real errors were swallowed.
            if ($type === self::PAYLOAD_TYPES['ProtoOAErrorRes']) {
                $errorCode = $inner['errorCode'] ?? 'UNKNOWN';
                $description = $inner['description'] ?? '';
                throw new \RuntimeException("cTrader API error: $errorCode - $description");
            }

            return $inner;
        }
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

    /** Order modification not implemented for cTrader yet (docs/70 v1: BingX only). */
    public function modifyOrder(array $credentials, array $modification): array
    {
        throw new \App\Exceptions\BrokerOrderException(
            'modifyOrder not implemented for cTrader',
            'NOT_IMPLEMENTED',
        );
    }
}
