<?php

namespace App\Services\Broker;

interface ConnectorInterface
{
    /**
     * Fetch closed deals from the broker API.
     *
     * @param array $credentials Decrypted credentials
     * @param ?string $sinceCursor Last sync cursor for incremental sync
     * @return array{deals: array, cursor: string, raw_count: int}
     */
    public function fetchDeals(array $credentials, ?string $sinceCursor = null): array;

    /**
     * Fetch a full snapshot of currently-open positions. Unlike fetchDeals,
     * this is NOT incremental — the broker is the source of truth and we
     * reconcile our DB state against the returned set each run. Connectors
     * that don't support a live snapshot may return an empty list.
     *
     * @param array $credentials Decrypted credentials
     * @return array{positions: array, raw_count: int}
     */
    public function fetchOpenPositions(array $credentials): array;

    /**
     * Fetch a full snapshot of currently-pending orders (limit/stop/conditional
     * orders that haven't triggered yet). Like fetchOpenPositions, this is a
     * full snapshot — no cursor. Connectors that don't expose pending orders
     * may return an empty list.
     *
     * @param array $credentials Decrypted credentials
     * @return array{orders: array, raw_count: int}
     */
    public function fetchOpenOrders(array $credentials): array;

    /**
     * Fetch a snapshot of recently-closed orders with their final status
     * (executed, cancelled, expired). Consumed by the order diff to
     * disambiguate why an order disappeared from open_orders: it executed,
     * it was cancelled by the user, or it expired. Connectors that don't
     * expose closed orders may return an empty list — the diff service
     * falls back to its conservative default (CANCELLED) for unmatched
     * disappearances.
     *
     * @param array $credentials Decrypted credentials
     * @return array{orders: array, raw_count: int}
     */
    public function fetchClosedOrders(array $credentials): array;

    /**
     * Refresh credentials (e.g. OAuth token refresh).
     * Returns updated credentials array, or the same if no refresh needed.
     */
    public function refreshCredentials(array $credentials): array;

    /**
     * Test the connection with current credentials.
     */
    public function testConnection(array $credentials): bool;
}
