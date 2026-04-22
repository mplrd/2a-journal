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
