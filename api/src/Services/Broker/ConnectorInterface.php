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
     * @param ?string $sinceCursor Optional cursor to walk back to (same
     *                              format as fetchDeals's cursor). Connectors
     *                              that don't paginate historically may
     *                              ignore it.
     * @return array{orders: array, raw_count: int}
     */
    public function fetchClosedOrders(array $credentials, ?string $sinceCursor = null): array;

    /**
     * Refresh credentials (e.g. OAuth token refresh).
     * Returns updated credentials array, or the same if no refresh needed.
     */
    public function refreshCredentials(array $credentials): array;

    /**
     * Test the connection with current credentials.
     */
    public function testConnection(array $credentials): bool;

    /**
     * Fetch the current account equity (or balance — connector chooses
     * whichever is the most meaningful "total value" figure the broker
     * exposes, typically equity = balance + unrealized PnL).
     *
     * Returns null when the broker doesn't expose a balance endpoint or
     * the call fails — the sync logs the failure but doesn't abort the
     * rest of the run.
     *
     * @param array $credentials Decrypted credentials
     */
    public function fetchBalance(array $credentials): ?float;

    /**
     * Place an order on the broker. Throws BrokerOrderException on rejection
     * (insufficient margin, invalid symbol, broker offline…). Connectors that
     * do not yet support outbound orders may throw BrokerOrderException with
     * providerCode='NOT_IMPLEMENTED'.
     *
     * $order shape (all keys ASCII snake_case):
     * - symbol (string, required) — broker-side symbol identifier
     * - direction (string, required) — 'BUY' | 'SELL'
     * - order_type (string, required) — 'MARKET' | 'LIMIT' | 'STOP'
     * - size (float, required) — lot/contract size in the broker's unit
     * - entry_price (float|null) — required for LIMIT/STOP, ignored for MARKET
     * - sl_price (float|null) — absolute stop-loss price
     * - tp_prices (float[]) — absolute take-profit prices (broker may only honor [0])
     * - expires_at (string|null) — ISO-8601 UTC; broker may ignore
     * - client_order_id (string|null) — caller-provided idempotency key
     *
     * Return shape:
     * - external_order_id (string) — broker's id for the placed order
     * - status (string|null) — broker's internal status if known
     * - raw (array) — broker's raw response (for audit)
     *
     * @param array $credentials Decrypted credentials
     * @param array $order Normalized order spec (see above)
     * @return array{external_order_id: string, status: string|null, raw: array}
     */
    public function placeOrder(array $credentials, array $order): array;

    /**
     * Cancel a pending order on the broker.
     *
     * @param array $credentials Decrypted credentials
     * @param string $externalOrderId Broker's order id (as returned by placeOrder)
     * @return array{status: string|null, raw: array}
     */
    public function cancelOrder(array $credentials, string $externalOrderId): array;

    /**
     * Close (fully or partially) an open position on the broker.
     *
     * @param array $credentials Decrypted credentials
     * @param string $externalPositionId Broker's position id
     * @param float|null $sizeOverride If null, close the entire position
     * @return array{status: string|null, raw: array}
     */
    public function closePosition(array $credentials, string $externalPositionId, ?float $sizeOverride = null): array;

    /**
     * Modify a live order/position's SL and/or TP on the broker.
     *
     * @param array $credentials Decrypted credentials
     * @param array $modification Normalized spec:
     * - broker_order_id (string) — broker's order/position id to amend
     * - symbol (string|null) — instrument (some brokers require it)
     * - sl_points (float|null) — new stop-loss distance in points (null = leave)
     * - targets (array|null) — new take-profit legs [{points,size},…] (null = leave)
     * @return array{status: string|null, raw: array}
     * @throws \App\Exceptions\BrokerOrderException with code NOT_IMPLEMENTED on
     *         connectors that do not support modify yet.
     */
    public function modifyOrder(array $credentials, array $modification): array;
}
