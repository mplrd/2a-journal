-- Migration 045 — `trades.pnl` dans la devise du compte (évolution #24)
--
-- Deux sources écrivaient la même colonne dans deux unités. La saisie manuelle
-- stockait `(exit_price - entry_price) × size`, un écart de prix brut qui n'est
-- ni des points ni de l'argent. La synchro broker stockait le P&L en devise,
-- commissions comprises. `point_value` n'intervenait nulle part dans le P&L,
-- alors que le reste de l'app lit ce chiffre comme de l'argent :
-- `accounts.current_capital` en fait la somme, DrawdownService le compare à un
-- montant de drawdown, les plans divisent un risque par le capital qu'il nourrit.
--
-- Le P&L devient `nb de points × taille de la jambe × valeur du point`, dans la
-- devise du compte. La valeur du point est FIGÉE sur la position à sa création :
-- modifier plus tard la valeur d'un actif ne déplace que les trades suivants,
-- jamais le capital et le drawdown déjà en place.
--
-- ─── AUCUNE REPRISE DE L'HISTORIQUE, ET C'EST DÉLIBÉRÉ ──────────────────────
--
-- Une première version de cette migration repeuplait `point_value` depuis le
-- réglage courant de chaque actif, puis recalculait les jambes. Essai à blanc
-- sur un jeu de données réaliste, trois comptes :
--
--   FTMO Challenge   P&L +1 030  ->  +15 660   objectif de gain franchi d'un coup
--   MFF Évaluation   P&L   -330  ->   -7 500   drawdown consommé 6,6 % -> 150 %
--
-- La raison est simple : `symbols.point_value` n'a jamais servi qu'au calcul de
-- risque (`SignalRiskCalculator`). Personne n'a jamais eu de raison de curer ces
-- valeurs, et `autoMaterializeForUser()` matérialise les réglages par compte en
-- recopiant ce défaut — un réglage « explicite » est donc indiscernable d'un
-- défaut hérité. Un DAX à 25 €/pt sur un compte prop firm où le point vaut 1
-- n'est pas un cas tordu, c'est le cas courant.
--
-- Reprendre l'historique avec ces valeurs, ce n'est pas retrouver une donnée
-- perdue : c'est en inventer une, et détruire au passage la seule copie du P&L
-- réel. Les positions existantes restent donc à 1, ce qui laisse leur P&L
-- rigoureusement inchangé — quelle que soit l'unité dans laquelle il est.
--
-- Conséquence assumée : l'historique garde son unité d'origine, les nouveaux
-- trades sont en devise. Un repricing de l'historique reste possible, mais il
-- demande des valeurs de point que l'utilisateur a réellement validées, actif
-- par actif et compte par compte — donc un geste explicite dans l'interface, pas
-- une migration silencieuse. C'est au backlog (`docs/evolutions.md`).
--
-- Additive et sans mouvement de données : une colonne à défaut neutre, rien
-- d'autre. Idempotente via le check INFORMATION_SCHEMA.

SET @col_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'positions'
      AND COLUMN_NAME = 'point_value'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE positions ADD COLUMN point_value DECIMAL(10,5) NOT NULL DEFAULT 1 AFTER size',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
