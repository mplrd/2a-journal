-- Migration 043 — Risque cumulé plafonné par plan (docs/83-trading-plans.md)
--
-- `max_risk_percent` plafonne le risque d'UN signal. Respecter 1 % par trade
-- n'empêche pourtant pas d'être exposé à 20 % en ayant vingt positions ouvertes
-- en même temps : le plafond par trade ne dit rien de l'exposition totale, et
-- c'est précisément ce qu'un robot peut construire en quelques minutes.
--
--   max_plan_risk_percent  plafond du risque CUMULÉ des positions encore
--                          exposées sous ce plan, sur le compte visé, signal
--                          entrant compris. NULL = pas de plafond cumulé, ce
--                          qui est le comportement actuel : les plans déjà en
--                          base ne changent pas de sens.
--
-- Même type que max_risk_percent (DECIMAL(6,3)) : un pourcentage cumulé se lit
-- sur la même échelle qu'un pourcentage par trade, et dépasser 999,999 % de
-- risque n'a pas de sens à exprimer.
--
-- Additive uniquement, gardée par INFORMATION_SCHEMA pour rester idempotente et
-- rejouable sur MariaDB (local) comme sur MySQL (prod).

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'trading_plans'
      AND COLUMN_NAME = 'max_plan_risk_percent'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE trading_plans ADD COLUMN max_plan_risk_percent DECIMAL(6,3) NULL DEFAULT NULL AFTER max_risk_percent',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
