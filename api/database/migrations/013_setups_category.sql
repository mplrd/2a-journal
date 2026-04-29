-- Migration 013 — Setup taxonomy: timeframe / pattern / context
--
-- Adds a category column on setups so the SPA can color-tag them per
-- semantic group (charte audit §3.2). New setups default to 'pattern'
-- to keep existing tags rendering identically until the user reclassifies.
--
-- Validated server-side via an enum allow-list in SetupService::update.
-- Idempotent via INFORMATION_SCHEMA check.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'setups'
      AND COLUMN_NAME = 'category'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE setups ADD COLUMN category ENUM('timeframe','pattern','context') NOT NULL DEFAULT 'pattern' AFTER label",
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
