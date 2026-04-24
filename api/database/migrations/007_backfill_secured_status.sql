-- Migration 007 — Backfill SECURED status on BE-reached trades
--
-- Historical context: before commit dc4b0f8 (fix(trades): transition OPEN to
-- SECURED when BE is reached without partial exit), POST /trades/{id}/be-hit
-- set be_reached=1 but left status untouched. Trades that went through that
-- path are now stuck at status=OPEN despite being de-risked (SL at BE).
--
-- The UI won't re-trigger markBeReached() on those trades (its "next
-- objective" logic skips the BE-mark step when be_reached is already 1),
-- so there's no user-side self-heal path. This migration performs the
-- catch-up once per environment.
--
-- Idempotent: a re-run finds nothing to update (all matching rows are
-- already SECURED after the first pass).

UPDATE trades
SET status = 'SECURED'
WHERE be_reached = 1
  AND status = 'OPEN';
