-- Migration 031 — Currency of the broker-reported balance
--
-- `broker_balance` (migration 024) stocke une valeur nue, sans devise. Or le
-- solde courtier est dans la devise de règlement du broker (USDT pour BingX
-- USDT-M Perpetual), qui peut différer de `accounts.currency` choisie par
-- l'utilisateur. Sans la devise du solde, impossible de détecter ce mismatch
-- proprement (on ne peut que le deviner « USDT parce que BingX »).
--
-- On stocke la devise telle que reportée par le broker au dernier sync. L'UI
-- compare `accounts.currency` vs `broker_balance_currency` et alerte si elles
-- diffèrent (pas de conversion FX — simple signalement).
--
-- Additive only : colonne nullable. Idempotent via check INFORMATION_SCHEMA.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounts'
      AND COLUMN_NAME = 'broker_balance_currency'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE accounts ADD COLUMN broker_balance_currency VARCHAR(10) NULL DEFAULT NULL AFTER broker_balance',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
