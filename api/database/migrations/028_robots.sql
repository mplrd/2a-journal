-- Migration 028 — Trading robots
--
-- Introduces the `robots` entity (docs/70-robots.md). A robot is the first-class
-- owner of a TradingView webhook: it points to one account, can be active or
-- paused, and (v2) will follow a trading plan that filters incoming signals.
--
-- The TradingView webhooks feature (migration 020) shipped but was never
-- activated (flag OFF everywhere), so there is NO production data to preserve:
-- we repoint `tradingview_webhooks` from `account_id` to `robot_id` by dropping
-- and recreating the column + FK, rather than backfilling.
--
-- Idempotent: guarded on the column/table existence so repeated deploys are safe.

-- ── 1. robots table ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS robots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    status ENUM('ACTIVE','PAUSED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    -- v2: plan_id BIGINT UNSIGNED NULL → FK trading_plans (the robot follows a plan)
    last_triggered_at TIMESTAMP NULL DEFAULT NULL,
    total_triggered INT UNSIGNED NOT NULL DEFAULT 0,
    total_errors INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_robot_user (user_id),
    KEY idx_robot_account (account_id),
    KEY idx_robot_status (status),
    CONSTRAINT fk_robot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_robot_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. tradingview_webhooks: account_id → robot_id ──────────────
-- Drop the legacy account FK + column, add robot_id + FK. The webhook becomes a
-- pure auth channel owned by a robot; the target account is read via the robot.
-- Counters move to the robot, so drop them here too.

-- Drop legacy account FK (name from migration 020).
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tradingview_webhooks'
      AND CONSTRAINT_NAME = 'fk_tv_webhook_account'
);
SET @sql := IF(@fk_exists > 0,
    'ALTER TABLE tradingview_webhooks DROP FOREIGN KEY fk_tv_webhook_account',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop legacy account_id column.
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tradingview_webhooks' AND COLUMN_NAME = 'account_id'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE tradingview_webhooks DROP COLUMN account_id',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop counters now carried by the robot.
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tradingview_webhooks' AND COLUMN_NAME = 'last_triggered_at'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE tradingview_webhooks DROP COLUMN last_triggered_at, DROP COLUMN total_triggered, DROP COLUMN total_errors',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop the legacy status column (revocation now lives on the robot).
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tradingview_webhooks' AND COLUMN_NAME = 'status'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE tradingview_webhooks DROP COLUMN status',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add robot_id + FK.
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tradingview_webhooks' AND COLUMN_NAME = 'robot_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tradingview_webhooks
        ADD COLUMN robot_id BIGINT UNSIGNED NOT NULL AFTER user_id,
        ADD KEY idx_webhook_robot (robot_id),
        ADD CONSTRAINT fk_tv_webhook_robot FOREIGN KEY (robot_id) REFERENCES robots(id) ON DELETE CASCADE',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
