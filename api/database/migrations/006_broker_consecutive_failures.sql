-- Migration 006 — Broker connections tables + auto-sync circuit breaker counter
--
-- Historical note: broker_connections and sync_logs were added to schema.sql
-- when the broker feature (docs/22) was merged, but no migration file was
-- ever included. Envs provisioned via migrate.php (Railway) therefore lacked
-- these tables, and any deploy that tried to ALTER them would fail with
-- "Base table or view not found". This migration retrofits them idempotently
-- and then adds the consecutive_failures counter required by the auto-sync
-- scheduler (docs/31).

-- Tables: defined WITHOUT consecutive_failures so the ALTER below is always
-- meaningful regardless of whether the table pre-existed.
CREATE TABLE IF NOT EXISTS broker_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    provider ENUM('CTRADER','METAAPI') NOT NULL,
    status ENUM('PENDING','ACTIVE','ERROR','REVOKED') NOT NULL DEFAULT 'PENDING',
    credentials_encrypted TEXT NOT NULL,
    credentials_iv VARCHAR(32) NOT NULL,
    last_sync_at TIMESTAMP NULL DEFAULT NULL,
    last_sync_status ENUM('SUCCESS','PARTIAL','FAILED') NULL DEFAULT NULL,
    last_sync_error TEXT NULL DEFAULT NULL,
    sync_cursor VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_broker_conn_account (account_id),
    KEY idx_broker_conn_user (user_id),
    CONSTRAINT fk_broker_conn_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_broker_conn_account FOREIGN KEY (account_id)
        REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    broker_connection_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    import_batch_id INT UNSIGNED NULL DEFAULT NULL,
    status ENUM('STARTED','SUCCESS','PARTIAL','FAILED') NOT NULL,
    deals_fetched INT UNSIGNED NOT NULL DEFAULT 0,
    deals_imported INT UNSIGNED NOT NULL DEFAULT 0,
    deals_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL DEFAULT NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,

    KEY idx_sync_logs_conn (broker_connection_id),
    CONSTRAINT fk_sync_logs_conn FOREIGN KEY (broker_connection_id)
        REFERENCES broker_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent ADD COLUMN via dynamic SQL (portable across MySQL 5.7+ and MariaDB,
-- avoids relying on the MySQL 8.0.29+ / MariaDB-specific "ADD COLUMN IF NOT EXISTS").
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_connections'
      AND COLUMN_NAME = 'consecutive_failures'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_connections ADD COLUMN consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_sync_error',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
