-- Migration 026 — Structured details on support tickets
--
-- Adds an optional JSON `details` column to support_tickets. It stores the
-- type-specific structured fields captured at creation time, in addition to the
-- always-required free-text `body` (the first message):
--   - BUG:     { expected_behavior, reproduction_steps }
--   - FEATURE: { benefit, imagined_solution }
--   - SUPPORT: no details (column stays NULL)
--
-- Captured once at creation, read-only afterwards. Existing tickets keep
-- details = NULL and render unchanged.
--
-- Additive only (new nullable column). Idempotent via INFORMATION_SCHEMA guard
-- (same pattern as migration 024).

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'support_tickets'
      AND COLUMN_NAME = 'details'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE support_tickets ADD COLUMN details JSON NULL DEFAULT NULL AFTER subject',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
