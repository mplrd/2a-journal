-- Migration 015 — Backfill realized P&L on SECURED trades with partial exits
--
-- Until this release, trades.pnl / pnl_percent / risk_reward were populated
-- only at full close. SECURED trades (partials taken but still open) stayed
-- with NULL pnl, and their partial realized P&L was invisible to all stats
-- queries. The new TradeService::close() updates these fields on every exit.
--
-- This migration recomputes the same aggregates for trades that were already
-- SECURED before the deploy, using the existing partial_exits.pnl rows. It
-- rebuilds the same numbers TradeService would produce going forward.
--
-- Idempotent: targets only SECURED trades where pnl IS NULL. Re-running after
-- the new code is in place is a no-op (subsequent partials populate pnl, so
-- no SECURED row remains with NULL pnl + non-empty partial_exits).
--
-- pnl_percent formula: pnl / (entry_price * size) * 100
-- risk_reward formula: pnl / (size * sl_points)
-- Both mirror calculateRealizedMetrics() in TradeService.

UPDATE trades t
INNER JOIN positions p ON p.id = t.position_id
INNER JOIN (
    SELECT trade_id, SUM(pnl) AS realized_pnl
    FROM partial_exits
    GROUP BY trade_id
) agg ON agg.trade_id = t.id
SET
    t.pnl = ROUND(agg.realized_pnl, 2),
    t.pnl_percent = CASE
        WHEN p.entry_price * p.size > 0
            THEN ROUND(agg.realized_pnl / (p.entry_price * p.size) * 100, 4)
        ELSE 0
    END,
    t.risk_reward = CASE
        WHEN p.size * p.sl_points > 0
            THEN ROUND(agg.realized_pnl / (p.size * p.sl_points), 4)
        ELSE 0
    END
WHERE t.status = 'SECURED'
  AND t.pnl IS NULL;
