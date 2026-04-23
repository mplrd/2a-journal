-- Migration 005 — Per-(symbol, account) point value
-- Purely additive:
--   * new table `symbol_account_settings`
--   * backfill one row per (symbol, account) owned by user, copying current symbols.point_value
--
-- The legacy columns symbols.point_value and symbols.currency stay for now
-- (backwards-safe). A future migration will drop them once prod code no longer
-- reads them. The account's currency is now the source of truth for display.

CREATE TABLE IF NOT EXISTS symbol_account_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    point_value DECIMAL(10,5) NOT NULL DEFAULT 1.00000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_sas_symbol_account (symbol_id, account_id),
    KEY idx_sas_symbol (symbol_id),
    KEY idx_sas_account (account_id),
    CONSTRAINT fk_sas_symbol FOREIGN KEY (symbol_id) REFERENCES symbols (id) ON DELETE CASCADE,
    CONSTRAINT fk_sas_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill one row per (symbol, account) pair owned by the same user,
-- copying the legacy symbols.point_value so behaviour is unchanged for existing users.
INSERT INTO symbol_account_settings (symbol_id, account_id, point_value)
SELECT s.id, a.id, s.point_value
FROM symbols s
INNER JOIN accounts a ON a.user_id = s.user_id
WHERE s.deleted_at IS NULL
  AND a.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM symbol_account_settings sas
      WHERE sas.symbol_id = s.id AND sas.account_id = a.id
  );
