-- Migration 042 — Instrument ciblé par un plan (docs/83-trading-plans.md)
--
-- Jusqu'ici une zone de prix était un couple de bornes nues : ni `robots`, ni
-- `trading_plans`, ni `trading_plan_zones` ne nommait d'instrument. Le plan
-- comparait donc le prix d'un signal à ses zones SANS regarder le symbole, et
-- un signal portant sur un instrument jamais visé, dont le prix tombait par
-- hasard dans une zone, franchissait le filtre et partait au broker. Pour un
-- garde-fou dont le rôle est d'empêcher un trade hors cadre, l'erreur allait
-- dans le mauvais sens.
--
--   symbol   code de l'actif visé, tel que saisi par l'utilisateur dans ses
--            actifs (même type que positions.symbol). NULL = tous instruments,
--            ce qui est le comportement actuel : les plans déjà en base ne
--            changent donc pas de sens tant que l'utilisateur ne les précise pas.
--
-- Additive uniquement, gardée par INFORMATION_SCHEMA pour rester idempotente et
-- rejouable sur MariaDB (local) comme sur MySQL (prod).

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'trading_plans'
      AND COLUMN_NAME = 'symbol'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE trading_plans ADD COLUMN symbol VARCHAR(50) NULL DEFAULT NULL AFTER name',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
