<?php

namespace App\Services\Broker;

/**
 * cTrader Open API connector via JSON WebSocket (port 5036).
 * Phase 3 implementation — currently a stub.
 */
class CtraderConnector implements ConnectorInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        // TODO: Phase 3 — WebSocket JSON connection to cTrader Open API
        throw new \RuntimeException('cTrader connector not yet implemented');
    }

    public function refreshCredentials(array $credentials): array
    {
        // TODO: Phase 3 — HTTP GET to openapi.ctrader.com/apps/token with refresh_token
        throw new \RuntimeException('cTrader connector not yet implemented');
    }

    public function testConnection(array $credentials): bool
    {
        return false;
    }
}
