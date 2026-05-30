-- Migration 024 — Broker-reported balance on accounts
--
-- Le `current_capital` actuel est dérivé de `initial_capital + SUM(trades.pnl
-- where status=CLOSED)` côté lecture (`AccountRepository::findById`). Pour un
-- compte synced à un broker, le broker est en réalité la source de vérité du
-- solde : il prend en compte les commissions, funding fees, transferts, et le
-- P&L non-réalisé qui ne sont pas dans notre dérivation.
--
-- On ajoute une colonne `broker_balance` qui stocke l'equity reportée par le
-- broker au dernier sync. L'UI peut l'afficher en priorité quand une
-- broker_connection ACTIVE existe sur le compte ; sinon elle tombe sur la
-- valeur dérivée comme avant.
--
-- Additive only : colonne nullable. Idempotent via check INFORMATION_SCHEMA.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'broker_balance'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE accounts ADD COLUMN broker_balance DECIMAL(15,2) NULL DEFAULT NULL AFTER current_capital',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'broker_balance_synced_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE accounts ADD COLUMN broker_balance_synced_at TIMESTAMP NULL DEFAULT NULL AFTER broker_balance',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
