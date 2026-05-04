-- Migration 017 — Drawdown alert schema (E-08)
--
-- Adds the storage needed for the "DD approaching" alert feature:
--   - users.dd_alert_threshold_percent: how close to a DD limit (in % of the
--     full DD allowance) should trigger an alert. 5% means "alert when 95%
--     or more of the DD has been consumed". Range 1.00 – 10.00, default 5.00.
--   - accounts.last_max_dd_alert_at / last_daily_dd_alert_at: dedup timestamps
--     so we don't spam the user. Reset semantics live in app code (compared
--     against today's local-midnight in the user's timezone).
--
-- Additive only: no column drop or type change. Idempotent under repeated
-- deploy via the entrypoint migrate.php loop (existing rows untouched, new
-- columns get the default).

ALTER TABLE users
    ADD COLUMN dd_alert_threshold_percent DECIMAL(4,2) NOT NULL DEFAULT 5.00 AFTER be_threshold_percent;

ALTER TABLE accounts
    ADD COLUMN last_max_dd_alert_at TIMESTAMP NULL DEFAULT NULL AFTER profit_split,
    ADD COLUMN last_daily_dd_alert_at TIMESTAMP NULL DEFAULT NULL AFTER last_max_dd_alert_at;
