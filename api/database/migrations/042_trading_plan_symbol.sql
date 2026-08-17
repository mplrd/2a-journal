-- Migration 042 — L'actif visé par un plan (docs/83-trading-plans.md, docs/99)
--
-- Jusqu'ici une zone de prix était un couple de bornes nues : ni `robots`, ni
-- `trading_plans`, ni `trading_plan_zones` ne nommait d'actif. Le plan comparait
-- donc le prix d'un signal à ses zones SANS regarder l'instrument, et un signal
-- portant sur un actif jamais visé, dont le prix tombait par hasard dans une
-- zone, franchissait le filtre et partait au broker. Pour un garde-fou dont le
-- rôle est d'empêcher un trade hors cadre, l'erreur allait dans le mauvais sens.
--
--   symbol_id   RÉFÉRENCE l'actif (`symbols.id`). NULL = tous les actifs, ce
--               qui est le comportement actuel : les plans déjà en base ne
--               changent pas de sens tant que l'utilisateur ne les précise pas.
--
-- Une clé étrangère, PAS une recopie du code de l'actif. La première version de
-- cette migration posait `symbol VARCHAR(50)`, en copiant le motif de
-- `positions.symbol` et `symbol_aliases.journal_symbol`. C'était une faute :
-- `symbols.code` est modifiable depuis « Mes actifs », et rien ne propageait le
-- changement — renommer DE40.CASH en GER40 laissait le plan viser un code que
-- plus rien ne portait, donc ne matcher aucun signal, en silence.
-- `symbol_account_settings` faisait déjà pointer `symbol_id` avec une FK ; c'est
-- ce modèle-là qu'il fallait suivre.
--
-- ON DELETE RESTRICT, et pas SET NULL : NULL veut dire « tous les actifs », donc
-- une suppression élargirait le filtre au lieu de le supprimer. Un garde-fou ne
-- doit jamais s'ouvrir tout seul. Les actifs sont de toute façon supprimés en
-- douceur (`deleted_at`), la contrainte ne se déclenche donc qu'en suppression
-- définitive.
--
-- Additive uniquement, gardée par INFORMATION_SCHEMA pour rester idempotente et
-- rejouable sur MariaDB (local) comme sur MySQL (prod).

-- ── colonne ─────────────────────────────────────────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'trading_plans'
      AND COLUMN_NAME = 'symbol_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE trading_plans ADD COLUMN symbol_id INT UNSIGNED NULL DEFAULT NULL AFTER name',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── clé étrangère (crée son index au passage) ───────────────────
SET @fk_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'trading_plans'
      AND CONSTRAINT_NAME = 'fk_plan_symbol'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE trading_plans ADD CONSTRAINT fk_plan_symbol FOREIGN KEY (symbol_id) REFERENCES symbols(id) ON DELETE RESTRICT',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
