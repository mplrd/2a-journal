-- Migration 029 — Robot order correlation (docs/70-robots.md, § Gestion d'ordre)
--
-- A robot must correlate later signals (MODIFY / CLOSE / CANCEL) back to the
-- order it opened. The indicator re-emits a stable `client_order_id` on each
-- signal; we also keep the broker's own order id returned by placeOrder so we
-- can address it for amend/cancel/close.
--
--   - client_order_id : stable key supplied by the indicator at OPEN, scoped to
--     the robot/account, used to resolve the order on follow-up signals.
--   - broker_order_id : id returned by the broker after placeOrder, used to
--     target the order on the broker side.
--
-- Additive only (two nullable columns on orders). Idempotent via
-- INFORMATION_SCHEMA guards (same pattern as migrations 024/026/028).

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'client_order_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN client_order_id VARCHAR(120) NULL DEFAULT NULL AFTER status',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND COLUMN_NAME = 'broker_order_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE orders ADD COLUMN broker_order_id VARCHAR(128) NULL DEFAULT NULL AFTER client_order_id',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Lookup index for resolving a follow-up signal to its open order, scoped to
-- the account (ownership) + client_order_id (the indicator-supplied key).
SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'orders'
      AND INDEX_NAME = 'idx_orders_client_order_id'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE orders ADD KEY idx_orders_client_order_id (client_order_id)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
