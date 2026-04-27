-- Migration 010 — Track last login timestamp
--
-- Used by the admin BO list view to surface "stale" accounts that haven't
-- connected in a while. Set by AuthService::login on successful credentials
-- (after suspension check).
--
-- Idempotent via INFORMATION_SCHEMA check.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'last_login_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER locked_until',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
