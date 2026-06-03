-- Migration 027 — Manual balance corrections on accounts (ticket #30)
--
-- `current_capital` is derived read-side as `initial_capital + SUM(trades.pnl
-- where status=CLOSED)` (AccountRepository). Any real-world gap that isn't a
-- recorded trade — forgotten/unrecorded fees, a starting balance slightly off
-- the theoretical initial capital — makes that derivation drift from the
-- broker reality (e.g. initial 10000, real 10018).
--
-- This table keeps an auditable ledger of manual corrections. Each row is a
-- signed delta (amount) with an optional reason. The derived balance becomes:
--   current_capital = initial_capital + SUM(trades.pnl) + SUM(adjustments.amount)
-- `initial_capital` stays untouched (explicit user request).
--
-- Additive only. Idempotent via CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS account_balance_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    reason VARCHAR(255) NULL DEFAULT NULL,
    adjusted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_aba_account_id (account_id),
    CONSTRAINT fk_aba_account FOREIGN KEY (account_id)
        REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
