-- Migration 023 — Add external_id to partial_exits for sync dedup
--
-- Le refactor BingX fills-based reconstruit position+trade+partial_exits à
-- partir des fills bruts. Sans identifiant externe stable sur les partial_exits,
-- un sync de la même position encore ouverte ré-insèrerait les mêmes exits à
-- chaque tick (les exits sont identifiés par trade_id + exited_at jusqu'ici,
-- ce qui n'est pas assez robuste face aux re-runs).
--
-- Avec `external_id = bingx_fill_<orderId>` côté broker sync (ou null pour les
-- partial exits créés manuellement par l'utilisateur), on peut faire un check
-- de dédup propre côté repository.
--
-- Additive only : colonne nullable + index. Idempotent via le check
-- INFORMATION_SCHEMA ci-dessous (pour cohérence avec les autres migrations
-- défensives).

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'partial_exits'
      AND COLUMN_NAME = 'external_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE partial_exits ADD COLUMN external_id VARCHAR(128) NULL DEFAULT NULL AFTER target_id',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'partial_exits'
      AND INDEX_NAME = 'idx_partial_exits_external_id'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE partial_exits ADD INDEX idx_partial_exits_external_id (external_id)',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
