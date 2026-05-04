-- Migration 016 — Nullify risk_reward where SL is missing
--
-- Until this release, TradeService::calculateRealizedMetrics() (and the
-- finalize path at calculateFinalMetrics()) stored risk_reward as 0 when
-- the trade had no SL renseigned (sl_points NULL or 0). That 0 was a
-- placeholder for "not calculable", but it polluted aggregations:
--   - StatsRepository::getOverview / getStatsByXxx use AVG(risk_reward) →
--     SQL AVG ignores NULL natively, but treats 0 as a real value, so the
--     mean was dragged toward 0 by every no-SL trade.
--   - StatsRepository::getRrDistribution bucketed those 0s into the "0-1"
--     bracket, inflating it.
--
-- Going forward, calculateRealizedMetrics() / calculateFinalMetrics() now
-- store NULL when sl_points is missing. This migration backfills the same
-- semantics on existing rows by nullifying risk_reward where the position
-- has no SL.
--
-- Idempotent: re-running after backfill is a no-op (no row left where
-- risk_reward IS NOT NULL and sl_points is missing).

UPDATE trades t
INNER JOIN positions p ON p.id = t.position_id
SET t.risk_reward = NULL
WHERE t.risk_reward IS NOT NULL
  AND (p.sl_points IS NULL OR p.sl_points = 0);
