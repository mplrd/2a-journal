-- Migration 003 — BE threshold for stats classification
-- Adds a per-user tolerance (% of entry price) used to classify near-zero trades
-- as break-even in statistics. Default 0 preserves legacy behavior.

ALTER TABLE users
    ADD COLUMN be_threshold_percent DECIMAL(6,4) NOT NULL DEFAULT 0 AFTER theme;

-- Backfill pnl_percent on closed trades where it was left NULL (e.g. imported trades)
-- so the new classification rule can be applied uniformly.
-- Formula: pnl / (entry_price * size) * 100
UPDATE trades t
INNER JOIN positions p ON p.id = t.position_id
SET t.pnl_percent = ROUND(t.pnl / (p.entry_price * p.size) * 100, 4)
WHERE t.pnl_percent IS NULL
  AND t.status = 'CLOSED'
  AND t.pnl IS NOT NULL
  AND p.entry_price > 0
  AND p.size > 0;
