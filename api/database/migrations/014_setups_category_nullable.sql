-- Migration 014 — Setup taxonomy: allow uncategorized
--
-- Makes the setups.category column nullable so users can leave a setup
-- without a typology bucket ("non catégorisé"). This fits setups that
-- don't fit timeframe / pattern / context cleanly.
--
-- Existing rows keep their current category value (default was 'pattern',
-- or whatever the user assigned). The DEFAULT becomes NULL so newly
-- created setups start uncategorized until the user picks one explicitly.
--
-- Idempotent: only re-applies the ALTER if the column is still NOT NULL.

SET @is_nullable := (
    SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'setups'
      AND COLUMN_NAME = 'category'
);
SET @sql := IF(@is_nullable = 'NO',
    "ALTER TABLE setups MODIFY COLUMN category ENUM('timeframe','pattern','context') NULL DEFAULT NULL",
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
