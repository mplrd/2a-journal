<?php

namespace Tests\Integration\Broker;

use App\Services\Broker\ConnectorInterface;

/**
 * Minimal connector double for the broker connection tests: only
 * testConnection()/getLastTestError() and the cTrader-only fetchAccounts()
 * carry behaviour, everything else answers the empty shape the interface
 * promises.
 *
 * Lives in its own PSR-4 file rather than beside a test class: two test files
 * now use it, and a class declared inside another test file only exists once
 * PHPUnit happens to have loaded that file first.
 */
class FakeConnector implements ConnectorInterface
{
    public function __construct(
        private bool $testResult = true,
        private ?string $lastError = null,
        private bool $throws = false,
    ) {}

    public function testConnection(array $credentials): bool
    {
        if ($this->throws) {
            throw new \RuntimeException('boom');
        }
        return $this->testResult;
    }

    /** cTrader-only, resolved via method_exists — not part of ConnectorInterface. */
    public function fetchAccounts(array $credentials): array
    {
        if ($this->throws) {
            throw new \RuntimeException('boom');
        }
        return [['ctid_trader_account_id' => 42111, 'trader_login' => '1234567', 'is_live' => true]];
    }

    public function getLastTestError(): ?string
    {
        return $this->lastError;
    }

    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array
    {
        return ['deals' => [], 'cursor' => '', 'raw_count' => 0];
    }

    public function fetchOpenPositions(array $credentials): array
    {
        return ['positions' => [], 'raw_count' => 0];
    }

    public function fetchOpenOrders(array $credentials): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array
    {
        return ['orders' => [], 'raw_count' => 0];
    }

    public function refreshCredentials(array $credentials): array
    {
        return $credentials;
    }

    public function fetchBalance(array $credentials): ?float
    {
        return null;
    }

    public function placeOrder(array $credentials, array $order): array
    {
        return ['external_order_id' => '1', 'status' => null, 'raw' => []];
    }

    public function cancelOrder(array $credentials, string $externalOrderId): array
    {
        return ['status' => null, 'raw' => []];
    }

    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array
    {
        return ['status' => null, 'raw' => []];
    }

    public function modifyOrder(array $credentials, array $modification): array
    {
        return ['status' => null, 'raw' => []];
    }
}
