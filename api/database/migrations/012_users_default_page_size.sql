-- Migration 012 — User-configurable default pagination
--
-- Allows each user to pick their preferred page size (10, 25, 50, 100) for
-- DataTables across the SPA. Default 10 reduces scrolling on entry screens
-- (was 25 hardcoded in components).
--
-- Validated server-side against an allow-list in AuthService::updateProfile.
-- Idempotent via INFORMATION_SCHEMA check.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'default_page_size'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE users ADD COLUMN default_page_size INT UNSIGNED NOT NULL DEFAULT 10 AFTER be_threshold_percent',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
