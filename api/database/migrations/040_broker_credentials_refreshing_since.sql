-- Migration 040 — Réserver le renouvellement du token, au lieu de le dater
--
-- Les migrations 036 et 037 ont posé un garde-fou : sauter le renouvellement du
-- token quand une autre connexion du même utilisateur vient de le renouveler
-- (cTrader fait tourner le refresh token à chaque usage, et depuis la 036 ce
-- token est partagé entre les connexions d'un même provider).
--
-- Ce garde-fou est une lecture d'horloge, donc il ne couvre que les synchros
-- DÉCALÉES. Deux workers qui démarrent dans la même seconde lisent tous les
-- deux « personne n'a renouvelé récemment » — aucun des deux n'a encore fini —
-- puis appellent cTrader ensemble. Le token tourne, le perdant présente un
-- token déjà consommé et lève `cTrader token refresh failed`.
--
-- Constaté en environnement de test : à CHAQUE passe, une des deux connexions
-- échoue puis les deux réussissent (1 FAILED + 2 SUCCESS). Le perdant alterne,
-- c'est bien une course. Les données restent justes — l'autre worker reprend la
-- connexion et saute le refresh, `refreshed_at` étant devenu frais — mais la
-- passe laisse une ligne FAILED, incrémente `consecutive_failures` et gaspille
-- un appel de renouvellement.
--
-- `refreshing_since` transforme la lecture d'horloge en réservation : un UPDATE
-- conditionnel désigne un seul gagnant (même motif que
-- `broker_connections.syncing_since`, cf. migration 035). Le perdant ne
-- rafraîchit pas et poursuit avec son access token, qu'un renouvellement
-- concurrent n'invalide pas — seul le refresh token tourne.
--
-- Additive : une colonne nullable. NULL = aucune réservation en cours.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_credentials'
      AND COLUMN_NAME = 'refreshing_since'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_credentials ADD COLUMN refreshing_since DATETIME NULL DEFAULT NULL AFTER refreshed_at',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
