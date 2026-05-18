-- Migration 020 — TradingView webhooks ingestion
--
-- Adds the storage needed for the TradingView alert → broker order automation:
--   - tradingview_webhooks: one row per webhook URL exposed by the user on an
--     account. Stores SHA-256 of the URL token + SHA-256 of the body secret so
--     neither can be recovered from a DB dump. Soft-revocation via `status`.
--   - tradingview_alert_events: every inbound POST on the webhook endpoint
--     becomes one row here (received, rejected, processed, failed, duplicate).
--     Doubles as audit trail and dedup table — UNIQUE(webhook_id, external_alert_id)
--     ensures TradingView replays don't create twice an order.
--
-- Additive only. Both tables are new — no column drop or type change on
-- existing tables. Idempotent under repeated deploy via the entrypoint
-- migrate.php loop.

CREATE TABLE tradingview_webhooks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    url_token_hash CHAR(64) NOT NULL,
    body_secret_hash CHAR(64) NOT NULL,
    status ENUM('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    last_triggered_at TIMESTAMP NULL DEFAULT NULL,
    total_triggered INT UNSIGNED NOT NULL DEFAULT 0,
    total_errors INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_webhook_token (url_token_hash),
    KEY idx_webhook_account (account_id),
    KEY idx_webhook_user (user_id),
    CONSTRAINT fk_tv_webhook_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tv_webhook_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tradingview_alert_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id BIGINT UNSIGNED NULL,
    account_id INT UNSIGNED NULL,
    external_alert_id VARCHAR(120) NULL,
    payload_raw JSON NOT NULL,
    status ENUM('RECEIVED','REJECTED','PROCESSED','FAILED','DUPLICATE') NOT NULL,
    reject_reason VARCHAR(60) NULL,
    error_message TEXT NULL,
    created_order_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_tv_event_dedup (webhook_id, external_alert_id),
    KEY idx_tv_event_account (account_id),
    KEY idx_tv_event_created (created_at),
    CONSTRAINT fk_tv_event_webhook FOREIGN KEY (webhook_id) REFERENCES tradingview_webhooks(id) ON DELETE SET NULL,
    CONSTRAINT fk_tv_event_order FOREIGN KEY (created_order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
