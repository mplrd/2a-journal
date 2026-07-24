-- Migration 033 — Trading plans (docs/83-trading-plans.md)
--
-- v2 of the robots feature (docs/70). A trading plan is a REUSABLE, account-
-- agnostic decision frame. A robot follows 0..N plans (many-to-many via
-- robot_plans); a signal is accepted if it is applicable to AT LEAST ONE
-- attached plan (OR). With no attached plan the robot keeps executing every
-- signal (v1 robot behaviour, unchanged).
--
-- A plan's four filters are OPTIONAL and combined with AND within one plan:
--   - allowed_direction  (NULL = both sides)
--   - price zones        (per direction; entry must fall in >=1 zone of the
--                          signal's direction, else no price constraint there)
--   - time windows       (days_mask + start/end, evaluated in the plan tz)
--   - max_risk_percent   (NULL = off; % of the account capital)
--
-- Plans only gate OPEN signals; MODIFY/CLOSE/CANCEL bypass them.
-- Idempotent: every statement is CREATE TABLE IF NOT EXISTS, safe to re-run.

-- ── 1. trading_plans ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS trading_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    allowed_direction ENUM('BUY','SELL') NULL DEFAULT NULL,   -- NULL = both sides
    timezone VARCHAR(64) NULL DEFAULT NULL,                   -- IANA tz for windows
    max_risk_percent DECIMAL(6,3) NULL DEFAULT NULL,          -- % of account capital
    status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_plan_user (user_id),
    KEY idx_plan_status (status),
    CONSTRAINT fk_plan_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. trading_plan_zones (N price zones per direction) ─────────
CREATE TABLE IF NOT EXISTS trading_plan_zones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('BUY','SELL') NOT NULL,
    low_price DECIMAL(15,5) NOT NULL,
    high_price DECIMAL(15,5) NOT NULL,
    KEY idx_zone_plan (plan_id),
    CONSTRAINT fk_zone_plan FOREIGN KEY (plan_id) REFERENCES trading_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. trading_plan_windows (session time windows) ─────────────
CREATE TABLE IF NOT EXISTS trading_plan_windows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    days_mask SMALLINT UNSIGNED NOT NULL,   -- bit0=Mon … bit6=Sun (1..127)
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    KEY idx_window_plan (plan_id),
    CONSTRAINT fk_window_plan FOREIGN KEY (plan_id) REFERENCES trading_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. robot_plans (many-to-many robots ↔ plans) ───────────────
CREATE TABLE IF NOT EXISTS robot_plans (
    robot_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (robot_id, plan_id),
    KEY idx_rp_plan (plan_id),
    CONSTRAINT fk_rp_robot FOREIGN KEY (robot_id) REFERENCES robots(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_plan FOREIGN KEY (plan_id) REFERENCES trading_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
