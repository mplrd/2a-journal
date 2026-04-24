-- Migration 006 — Auto-sync circuit breaker counter
-- Purely additive: new column consecutive_failures on broker_connections.
--
-- Used by the scheduler service (cli/sync-brokers.php) to track consecutive
-- sync failures per connection. When the counter reaches BROKER_SYNC_MAX_FAILURES
-- (env var, default 3), the connection is moved to status=ERROR and won't be
-- picked up again by the auto-sync scheduler — user must re-enable manually.

ALTER TABLE broker_connections
    ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_sync_error;
