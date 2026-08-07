-- Migration 035 — Réservation atomique des synchronisations broker
--
-- Jusqu'ici rien ne sérialise une synchro : le cron et le clic UI peuvent
-- traiter la même connexion en même temps (aucun verrou sur le chemin HTTP),
-- et le `flock` global du CLI n'empêche que deux tours de cron d'un même
-- conteneur. C'est aussi ce qui interdit toute parallélisation du scheduler.
--
-- `syncing_since` porte la réservation : un UPDATE conditionnel sur cette
-- colonne fait office de verrou, atomique côté base, sans SELECT préalable.
-- `sync_requested_at` porte la demande de synchro manuelle, reprise par le
-- prochain tick du cron (le bouton devient non bloquant).
--
-- DATETIME et non TIMESTAMP : pas de conversion selon le fuseau de session, la
-- valeur écrite par UTC_TIMESTAMP() est comparée telle quelle à UTC_TIMESTAMP().
--
-- Additive only : deux colonnes nullables + un index. Idempotent via les checks
-- INFORMATION_SCHEMA, comme 023.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_connections'
      AND COLUMN_NAME = 'syncing_since'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_connections ADD COLUMN syncing_since DATETIME NULL DEFAULT NULL AFTER sync_cursor',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_connections'
      AND COLUMN_NAME = 'sync_requested_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_connections ADD COLUMN sync_requested_at DATETIME NULL DEFAULT NULL AFTER syncing_since',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_connections'
      AND INDEX_NAME = 'idx_broker_conn_syncing'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE broker_connections ADD INDEX idx_broker_conn_syncing (syncing_since)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
