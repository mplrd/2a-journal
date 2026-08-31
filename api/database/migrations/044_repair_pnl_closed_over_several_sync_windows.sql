-- Migration 044 — Reprendre le P&L des trades clôturés sur plusieurs fenêtres de synchro
--
-- Les deals sont récupérés depuis le curseur de synchro. Une position fermée en
-- plusieurs fois sur plusieurs jours a donc ses take profits dans UNE fenêtre et
-- son stop final dans une autre. À la clôture, BrokerOpenSyncService écrivait
-- dans `trades.pnl` le total annoncé par la fenêtre de clôture — c'est-à-dire
-- les seules jambes de cette fenêtre — écrasant le cumul que le rollup avait
-- déjà encaissé pour les jambes antérieures.
--
-- Constaté en production le 2026-08-28 : deux take profits pour 670, le solde
-- stoppé à -32, et le trade laissé à -32. Le calendrier de P&L journalier
-- aggravait la chose : il impute `pnl - SUM(jambes)` à la date de clôture pour
-- rattraper ce qu'un broker annonce au niveau position au-delà de ses propres
-- jambes (swap, commissions). Les 670 effacés revenaient donc en négatif sur le
-- jour du stop : une journée qui avait réalisé -33 s'affichait à -703.
--
-- Le service est corrigé (BrokerOpenSyncService::realizedOnClose) : les jambes
-- en base font la base, et l'écart entre le total du broker et ses propres
-- jambes est ajouté par-dessus. Cette migration reprend les lignes déjà
-- clôturées de travers, qu'aucune passe ultérieure ne rattrapera — la position
-- est fermée, plus rien ne la resynchronise.
--
-- Elle corrige au passage la limite que 039 s'était explicitement donnée
-- (« sur un trade fermé, c'est le total annoncé par le broker qui fait
-- autorité ») : c'est vrai uniquement quand la position se ferme entièrement
-- dans une seule fenêtre.
--
-- Périmètre — quatre garde-fous cumulés, pour ne toucher QUE la signature du
-- bug et laisser tranquilles les trades dont l'écart est un vrai frais :
--   1. trade CLOSED, position synchronisée d'un connecteur broker ;
--   2. au moins deux jambes, dont la somme des tailles couvre exactement la
--      taille de la position — la preuve que les jambes en base décrivent la
--      position entière et que leur somme EST le vrai total ;
--   3. `pnl` s'écarte de cette somme de plus d'un centime ;
--   4. `pnl` est égal à la somme d'un SUFFIXE des jambes (toutes celles à
--      partir d'un certain moment) — exactement ce que produit l'écrasement par
--      une fenêtre de clôture. Un écart de frais ne satisfait pas ce test.
--
-- Les trois figures réalisées bougent ensemble : gagnant / perdant / BE se
-- classe sur `pnl_percent` seul (StatsRepository::isWin). Formules identiques à
-- TradeService::calculateRealizedMetrics.
--
-- Additive : crée une table, n'en modifie aucune structure. Idempotente : après
-- reprise, `pnl` colle aux jambes, la détection ne matche plus, et l'UPDATE est
-- gardé par `t.pnl <=> r.old_pnl`. Réversible : `trade_pnl_repairs` conserve
-- l'avant ET l'après de chaque ligne touchée, volontairement sans clé étrangère
-- pour survivre à la suppression d'un trade.

CREATE TABLE IF NOT EXISTS trade_pnl_repairs (
    trade_id INT UNSIGNED NOT NULL PRIMARY KEY,
    old_pnl DECIMAL(15,2) NULL,
    old_pnl_percent DECIMAL(8,4) NULL,
    old_risk_reward DECIMAL(8,4) NULL,
    old_avg_exit_price DECIMAL(15,5) NULL,
    new_pnl DECIMAL(15,2) NULL,
    new_pnl_percent DECIMAL(8,4) NULL,
    new_risk_reward DECIMAL(8,4) NULL,
    new_avg_exit_price DECIMAL(15,5) NULL,
    repaired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO trade_pnl_repairs
    (trade_id, old_pnl, old_pnl_percent, old_risk_reward, old_avg_exit_price,
     new_pnl, new_pnl_percent, new_risk_reward, new_avg_exit_price)
SELECT t.id,
       t.pnl,
       t.pnl_percent,
       t.risk_reward,
       t.avg_exit_price,
       ROUND(agg.legs_total, 2),
       CASE
           WHEN p.entry_price * p.size > 0
               THEN ROUND(agg.legs_total / (p.entry_price * p.size) * 100, 4)
           ELSE 0
       END,
       CASE
           WHEN p.size * COALESCE(p.sl_points, 0) > 0
               THEN ROUND(agg.legs_total / (p.size * p.sl_points), 4)
           ELSE NULL
       END,
       ROUND(agg.weighted_exit / agg.legs_size, 5)
FROM trades t
INNER JOIN positions p ON p.id = t.position_id
INNER JOIN (
    SELECT trade_id,
           COUNT(*) AS legs_count,
           SUM(pnl) AS legs_total,
           SUM(size) AS legs_size,
           SUM(exit_price * size) AS weighted_exit
    FROM partial_exits
    GROUP BY trade_id
) agg ON agg.trade_id = t.id
WHERE t.status = 'CLOSED'
  AND t.pnl IS NOT NULL
  AND p.external_id REGEXP '^(ctrader|metaapi|ouinex|bingx)_'
  AND agg.legs_count >= 2
  AND agg.legs_size > 0
  AND ABS(agg.legs_size - p.size) < 0.0001
  AND ABS(t.pnl - agg.legs_total) > 0.01
  AND EXISTS (
      SELECT 1
      FROM partial_exits cut
      WHERE cut.trade_id = t.id
        AND cut.exited_at > (
            SELECT MIN(first_leg.exited_at)
            FROM partial_exits first_leg
            WHERE first_leg.trade_id = t.id
        )
        AND ABS(t.pnl - (
            SELECT SUM(tail.pnl)
            FROM partial_exits tail
            WHERE tail.trade_id = t.id
              AND tail.exited_at >= cut.exited_at
        )) <= 0.01
  );

UPDATE trades t
INNER JOIN trade_pnl_repairs r ON r.trade_id = t.id
SET t.pnl = r.new_pnl,
    t.pnl_percent = r.new_pnl_percent,
    t.risk_reward = r.new_risk_reward,
    t.avg_exit_price = r.new_avg_exit_price
WHERE t.pnl <=> r.old_pnl;
