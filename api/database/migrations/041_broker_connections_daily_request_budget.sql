-- Migration 041 — Compteur quotidien de requêtes par connexion
--
-- Dernier garde-fou de l'évolution #22, écarté du lot du 2026-08-09 (il
-- demandait une migration et une surface admin).
--
-- Rappel du déclencheur : le 2026-08-07, FTMO a désactivé le compte de trading
-- 7589848 « due to the amount of activity », seuil annoncé 2 000 requêtes par
-- jour. Le compte n'était piloté par aucun EA — seule la synchro du journal y
-- touchait, en lecture. Le refactor du 09/08 a ramené un cycle de 19 requêtes à
-- 9, et la mesure réelle du 13/08 donne 648 requêtes/jour par connexion à
-- l'intervalle actuel. On est loin du plafond, mais rien n'empêche aujourd'hui
-- une boucle de synchro ou un intervalle mal réglé de le franchir sans que
-- personne ne le voie.
--
-- Le compteur est porté par la CONNEXION et non par le provider : le plafond
-- d'une prop firm porte sur un compte de trading, quel que soit le protocole
-- qui l'interroge. MetaTrader couvre les mêmes brokers que cTrader et devra se
-- brancher sur le même compteur — il lui suffira d'exposer getRequestCounts(),
-- le contrat que BrokerSyncService lit déjà sur n'importe quel connecteur.
--
-- `requests_counted_on` porte le jour du compteur, ce qui rend la remise à zéro
-- implicite : un incrément un jour différent écrase au lieu d'additionner. Pas
-- de tâche de purge, pas de fenêtre glissante à entretenir. UTC_DATE(), comme
-- syncing_since et refreshed_at, pour ne pas dépendre du fuseau de session.
--
-- Additive : deux colonnes, dont un compteur à 0 et une date nullable.
-- NULL = jamais compté, donc aucun plafond ne mord tant qu'une synchro n'a rien
-- déclaré.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_connections'
      AND COLUMN_NAME = 'requests_today'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_connections ADD COLUMN requests_today INT UNSIGNED NOT NULL DEFAULT 0 AFTER sync_requested_at',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_connections'
      AND COLUMN_NAME = 'requests_counted_on'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_connections ADD COLUMN requests_counted_on DATE NULL DEFAULT NULL AFTER requests_today',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
