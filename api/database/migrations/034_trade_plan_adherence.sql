-- Migration 034 — Plan adherence on a trade (docs/83-trading-plans.md)
--
-- A plan is a standalone frame; a robot is one consumer, a manually-entered (or
-- broker-synced) trade another. This links a trade's position to the plan it was
-- taken under and records, AT WRITE TIME, whether it fell inside that plan.
--
--   plan_id                 optional FK to the plan the trade follows (SET NULL
--                           if the plan row ever disappears — never lose a trade)
--   plan_adherence          snapshot verdict: IN_PLAN / OUT_OF_PLAN (NULL = no
--                           plan attached). Frozen at save: editing the plan
--                           later does NOT re-label existing trades.
--   plan_adherence_reason   short reason for OUT_OF_PLAN (e.g. "entry 24610
--                           outside BUY zones"), for the trade's badge tooltip.
--
-- Additive only; each step guarded via INFORMATION_SCHEMA so the file is
-- idempotent and safe on both MariaDB (local) and MySQL (prod).

-- ── plan_id ─────────────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'positions'
      AND COLUMN_NAME = 'plan_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE positions ADD COLUMN plan_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER setup',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── plan_adherence ──────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'positions'
      AND COLUMN_NAME = 'plan_adherence'
);
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE positions ADD COLUMN plan_adherence ENUM('IN_PLAN','OUT_OF_PLAN') NULL DEFAULT NULL AFTER plan_id",
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── plan_adherence_reason ───────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'positions'
      AND COLUMN_NAME = 'plan_adherence_reason'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE positions ADD COLUMN plan_adherence_reason VARCHAR(255) NULL DEFAULT NULL AFTER plan_adherence',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── FK positions.plan_id → trading_plans(id) (auto-creates its index) ──
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'positions'
      AND CONSTRAINT_NAME = 'fk_position_plan'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE positions ADD CONSTRAINT fk_position_plan FOREIGN KEY (plan_id) REFERENCES trading_plans(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
