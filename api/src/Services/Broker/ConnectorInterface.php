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
     * Refresh credentials (e.g. OAuth token refresh).
     * Returns updated credentials array, or the same if no refresh needed.
     */
    public function refreshCredentials(array $credentials): array;

    /**
     * Test the connection with current credentials.
     */
    public function testConnection(array $credentials): bool;
}
