-- Migration 004 — Stripe billing (pay-only, 5€/mois)
-- Adds subscription gating with 14-day grace period, bypass flag, and subscription tracking.

-- 1. Users: bypass flag, grace period, Stripe customer link
ALTER TABLE users
    ADD COLUMN bypass_subscription TINYINT(1) NOT NULL DEFAULT 0 AFTER be_threshold_percent,
    ADD COLUMN grace_period_end TIMESTAMP NULL DEFAULT NULL AFTER bypass_subscription,
    ADD COLUMN stripe_customer_id VARCHAR(255) NULL DEFAULT NULL AFTER grace_period_end,
    ADD UNIQUE KEY uk_users_stripe_customer (stripe_customer_id);

-- Backfill: give 14 days grace to existing active users so the paywall rollout is not brutal.
UPDATE users
SET grace_period_end = DATE_ADD(NOW(), INTERVAL 14 DAY)
WHERE grace_period_end IS NULL AND deleted_at IS NULL;

-- 2. Subscriptions table (1-1 with users). Mirrors the authoritative state kept in Stripe.
CREATE TABLE IF NOT EXISTS subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL,
    status ENUM('incomplete','incomplete_expired','trialing','active','past_due','canceled','unpaid','paused') NOT NULL,
    current_period_end TIMESTAMP NULL DEFAULT NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_subscriptions_user (user_id),
    UNIQUE KEY uk_subscriptions_stripe (stripe_subscription_id),
    CONSTRAINT fk_subscriptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Webhook events ledger (idempotence: Stripe may redeliver an event).
CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_webhook_stripe_event (stripe_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
