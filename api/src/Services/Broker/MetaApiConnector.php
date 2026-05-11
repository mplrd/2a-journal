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

    public function fetchClosedOrders(array $credentials): array
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
}
