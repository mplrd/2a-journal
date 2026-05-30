-- Migration 022 — Persistent "symbols seen" set per broker connection
--
-- Today the BingX connector enumerates symbols to scan from /user/positions
-- (currently OPEN positions). Conséquence : un symbol où l'utilisateur a fermé
-- toutes ses positions n'est plus interrogé, et son historique disparaît du
-- radar du sync incrémental. Doc 64 mentionnait cette limitation comme
-- backlog.
--
-- Le refactor "fills-based" (doc 67 à venir) a besoin de scanner les fills sur
-- TOUS les symbols jamais utilisés par le compte broker — pas seulement ceux
-- actuellement ouverts. On persiste donc un set "symbols vus" par connexion
-- qu'on enrichit à chaque sync (union avec ce qu'on voit).
--
-- Additive only : nouvelle colonne nullable, défaut NULL. Idempotent via ADD
-- COLUMN sur MySQL 8.0+ / MariaDB 10.4+ (échoue silencieusement si la colonne
-- existe déjà — protégé par le check INFORMATION_SCHEMA ci-dessous pour les
-- environnements plus anciens où ADD COLUMN n'est pas idempotent).

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'broker_connections'
      AND COLUMN_NAME = 'symbols_seen'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE broker_connections ADD COLUMN symbols_seen JSON NULL DEFAULT NULL AFTER consecutive_failures',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
