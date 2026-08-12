-- Migration 037 — Dater le dernier refresh OAuth réussi, pas la dernière écriture
--
-- La migration 036 a introduit un garde-fou dans BrokerSyncService : sauter le
-- renouvellement du token quand les identifiants partagés viennent d'être
-- écrits, pour que deux connexions synchronisées dans le même tick ne
-- présentent pas le même refresh token (cTrader le fait tourner à chaque
-- usage).
--
-- Le garde-fou s'appuyait sur `updated_at`, ce qui confond deux situations
-- opposées :
--   - une autre connexion vient de renouveler le token → il est frais, sauter
--     est correct ;
--   - l'utilisateur vient de SAISIR ses identifiants → l'access token peut
--     dater de plusieurs mois, et sauter le refresh est exactement l'inverse de
--     ce qu'il faut faire. C'est le cas de la toute première synchro après une
--     connexion, soit le moment où le refresh est le plus nécessaire.
--
-- `refreshed_at` n'est donc écrit que par un renouvellement réussi. Une saisie
-- ou une reconfiguration le laisse tel quel : la passe suivante refresh.
--
-- Additive : une colonne nullable. NULL = jamais renouvelé, donc jamais de
-- saut, ce qui est le comportement d'avant 036.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_credentials'
      AND COLUMN_NAME = 'refreshed_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_credentials ADD COLUMN refreshed_at DATETIME NULL DEFAULT NULL AFTER credentials_iv',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
