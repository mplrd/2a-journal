<?php

namespace App\Services\Broker;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class MetaApiConnector implements ConnectorInterface
{
    private Client $httpClient;
    private string $baseUrl;

    public function __construct(Client $httpClient, string $baseUrl)
    {
        $this->httpClient = $httpClient;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        $accountId = $credentials['metaapi_account_id'];
        $token = $credentials['api_token'];

        $startTime = $sinceCursor ?? gmdate('Y-m-d\TH:i:s\Z', strtotime('-90 days'));
        $endTime = gmdate('Y-m-d\TH:i:s\Z');

        $response = $this->httpClient->get(
            "{$this->baseUrl}/users/current/accounts/{$accountId}/history-deals/time/{$startTime}/{$endTime}",
            ['headers' => ['auth-token' => $token, 'Accept' => 'application/json']]
        );

        $rawDeals = json_decode($response->getBody()->getContents(), true) ?: [];
        $normalizer = new DealNormalizer();

        $deals = [];
        $latestTime = null;

        foreach ($rawDeals as $rawDeal) {
            $normalized = $normalizer->normalizeMetaApiDeal($rawDeal);
            if ($normalized !== null) {
                $deals[] = $normalized;
            }

            $dealTime = $rawDeal['time'] ?? null;
            if ($dealTime !== null && ($latestTime === null || $dealTime > $latestTime)) {
                $latestTime = $dealTime;
            }
        }

        return [
            'deals' => $deals,
            'cursor' => $latestTime,
            'raw_count' => count($rawDeals),
        ];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        // MetaApi exposes open positions via /accounts/{id}/positions. Not
        // wired yet for live sync — broker-sync only consumes closed deals
        // here for now. Returning an empty snapshot is safe: the diff
        // service won't delete anything just because this connector is silent.
        return ['positions' => [], 'raw_count' => 0];
    }

    public function fetchOpenOrders(array $credentials): array
    {
        // MetaApi pending orders live at /accounts/{id}/orders. Not wired
        // here yet — broker-sync only consumes closed deals on MetaApi.
        return ['orders' => [], 'raw_count' => 0];
    }

    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function refreshCredentials(array $credentials): array
    {
        // MetaApi tokens are managed via dashboard, no refresh needed
        return $credentials;
    }

    public function testConnection(array $credentials): bool
    {
        try {
            $accountId = $credentials['metaapi_account_id'];
            $token = $credentials['api_token'];

            $response = $this->httpClient->get(
                "{$this->baseUrl}/users/current/accounts/{$accountId}",
                ['headers' => ['auth-token' => $token, 'Accept' => 'application/json']]
            );

            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    public function fetchBalance(array $credentials): ?float
    {
        // MetaApi balance lives on /accounts/{id}/account-information.equity
        // — not wired yet on this connector. Return null so BrokerSyncService
        // skips the update silently for MetaApi-synced accounts.
        return null;
    }

    public function placeOrder(array $credentials, array $order): array
    {
        $actionType = $this->mapActionType($order['direction'] ?? '', $order['order_type'] ?? 'MARKET');

        $body = [
            'actionType' => $actionType,
            'symbol' => $order['symbol'],
            'volume' => (float) $order['size'],
        ];

        // openPrice is required for LIMIT/STOP orders and optional for MARKET.
        // Pass it for non-market types only — MetaApi rejects MARKET with openPrice
        // if it doesn't match the current bid/ask.
        if (($order['order_type'] ?? 'MARKET') !== 'MARKET' && !empty($order['entry_price'])) {
            $body['openPrice'] = (float) $order['entry_price'];
        }
        if (!empty($order['sl_price'])) {
            $body['stopLoss'] = (float) $order['sl_price'];
        }
        if (!empty($order['tp_prices'][0])) {
            $body['takeProfit'] = (float) $order['tp_prices'][0];
        }
        if (!empty($order['client_order_id'])) {
            $body['comment'] = mb_substr((string) $order['client_order_id'], 0, 31);
        }

        $response = $this->tradeRequest($credentials, $body);
        $this->ensureTradeOk($response);

        return [
            'external_order_id' => (string) ($response['orderId'] ?? $response['positionId'] ?? ''),
            'status' => $response['stringCode'] ?? null,
            'raw' => $response,
        ];
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        $response = $this->tradeRequest($credentials, [
            'actionType' => 'ORDER_CANCEL',
            'orderId' => $externalOrderId,
        ]);
        $this->ensureTradeOk($response);

        return ['status' => $response['stringCode'] ?? null, 'raw' => $response];
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        $body = $sizeOverride === null
            ? ['actionType' => 'POSITION_CLOSE_ID', 'positionId' => $externalPositionId]
            : ['actionType' => 'POSITION_PARTIAL', 'positionId' => $externalPositionId, 'volume' => $sizeOverride];

        $response = $this->tradeRequest($credentials, $body);
        $this->ensureTradeOk($response);

        return ['status' => $response['stringCode'] ?? null, 'raw' => $response];
    }

    private function tradeRequest(array $credentials, array $body): array
    {
        $accountId = $credentials['metaapi_account_id'] ?? null;
        $token = $credentials['api_token'] ?? null;
        if (!$accountId || !$token) {
            throw new \App\Exceptions\BrokerOrderException(
                'MetaApi credentials missing metaapi_account_id/api_token.',
                'INVALID_CREDENTIALS',
            );
        }

        $path = "/users/current/accounts/{$accountId}/trade";

        try {
            $response = $this->httpClient->post(
                $this->baseUrl . $path,
                [
                    'headers' => ['auth-token' => $token, 'Accept' => 'application/json'],
                    'json' => $body,
                ]
            );
        } catch (GuzzleException $e) {
            BrokerLogger::failure('metaapi', 'request_failed', [
                'path' => $path,
                'action' => $body['actionType'] ?? null,
                'symbol' => $body['symbol'] ?? null,
                'msg' => $e->getMessage(),
            ]);
            throw new \App\Exceptions\BrokerOrderException(
                'MetaApi trade request failed: ' . $e->getMessage(),
                'TRANSPORT_ERROR',
                [],
                $e,
            );
        }

        $decoded = json_decode($response->getBody()->getContents(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * MetaApi returns a `stringCode` echoing the underlying MT5 TRADE_RETCODE_*.
     * Only DONE/DONE_PARTIAL/PLACED are success; anything else is a broker
     * rejection (insufficient margin, invalid stops, market closed, …).
     */
    private function ensureTradeOk(array $response): void
    {
        $code = (string) ($response['stringCode'] ?? '');
        $ok = in_array($code, [
            'TRADE_RETCODE_DONE',
            'TRADE_RETCODE_DONE_PARTIAL',
            'TRADE_RETCODE_PLACED',
        ], true);
        if ($ok) {
            return;
        }
        BrokerLogger::failure('metaapi', 'trade_rejected', [
            'string_code' => $code,
            'numeric_code' => $response['numericCode'] ?? null,
            'msg' => $response['message'] ?? null,
        ]);
        throw new \App\Exceptions\BrokerOrderException(
            $response['message'] ?? "MetaApi rejected trade ({$code})",
            $code !== '' ? $code : 'UNKNOWN',
            $response,
        );
    }

    private function mapActionType(string $direction, string $orderType): string
    {
        $key = strtoupper($direction) . '_' . strtoupper($orderType);
        return match ($key) {
            'BUY_MARKET' => 'ORDER_TYPE_BUY',
            'SELL_MARKET' => 'ORDER_TYPE_SELL',
            'BUY_LIMIT' => 'ORDER_TYPE_BUY_LIMIT',
            'SELL_LIMIT' => 'ORDER_TYPE_SELL_LIMIT',
            'BUY_STOP' => 'ORDER_TYPE_BUY_STOP',
            'SELL_STOP' => 'ORDER_TYPE_SELL_STOP',
            default => throw new \App\Exceptions\BrokerOrderException(
                "Unsupported direction/order_type combination: {$direction}/{$orderType}",
                'UNSUPPORTED_ORDER',
            ),
        };
    }

    /** Order modification not implemented for MetaApi yet (docs/70 v1: BingX only). */
    public function modifyOrder(array $credentials, array $modification): array
    {
        throw new \App\Exceptions\BrokerOrderException(
            'modifyOrder not implemented for MetaApi',
            'NOT_IMPLEMENTED',
        );
    }
}
