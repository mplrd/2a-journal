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
            // 1. Application auth
            $this->sendAndReceive($ws, 'ProtoOAApplicationAuthReq', [
                'clientId' => $this->config['client_id'],
                'clientSecret' => $this->config['client_secret'],
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
                'clientId' => $this->config['client_id'],
                'clientSecret' => $this->config['client_secret'],
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
