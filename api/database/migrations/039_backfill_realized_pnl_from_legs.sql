-- Migration 039 — Reprendre le réalisé des trades encore ouverts
--
-- Une sortie partielle découverte par la synchro était écrite dans
-- `partial_exits` et s'arrêtait là : `trades.pnl` restait NULL jusqu'à la
-- clôture de la position. Or tout ce qui agrège filtre sur `pnl IS NOT NULL`
-- — les cartes de KPI, le calendrier de P&L journalier. L'argent déjà encaissé
-- n'apparaissait donc NULLE PART.
--
-- Le rollup côté service ne se déclenchait que dans la passe qui insérait une
-- jambe. Il ne rattrape donc pas les lignes dont les jambes lui sont
-- antérieures : aucune passe ultérieure n'insère plus rien, donc rien ne le
-- redéclenche jamais. Il tourne désormais à chaque passe (voir
-- BrokerOpenSyncService::bankRealizedFromExits), mais ces lignes-là resteraient
-- fausses tant qu'une nouvelle jambe ne tombe pas — d'où cette reprise.
--
-- Constaté en environnement de test le 2026-08-12 : le trade 10059 portait un
-- take profit de 406.13 pris la veille et `pnl` NULL. Quatre trades dans ce cas.
--
-- Les trois colonnes bougent ensemble parce que les statistiques les lisent
-- séparément : gagnant / perdant / BE se classe sur `pnl_percent` seul. Un
-- trade portant `pnl` sans `pnl_percent` compte au dénominateur du taux de
-- réussite et dans aucune de ses catégories. Formules identiques à
-- TradeService::calculateRealizedMetrics.
--
-- Périmètre volontairement limité aux trades NON clôturés. Sur un trade fermé,
-- c'est le total annoncé par le broker qui fait autorité — swap et commissions
-- compris, que les jambes ne portent pas forcément — et il ne doit pas être
-- recalculé depuis la somme des paliers. Vérifié avant écriture : aucun trade
-- CLOSED n'a de jambes avec `pnl` NULL, ni en test ni en local.
--
-- Idempotente : ne touche que `pnl IS NULL`, une seconde exécution est un no-op.
-- Additive : aucune colonne créée ou supprimée.

UPDATE trades t
INNER JOIN positions p ON p.id = t.position_id
INNER JOIN (
    SELECT trade_id, SUM(pnl) AS realized
    FROM partial_exits
    GROUP BY trade_id
) agg ON agg.trade_id = t.id
SET t.pnl = ROUND(agg.realized, 2),
    t.pnl_percent = CASE
        WHEN p.entry_price * p.size > 0
            THEN ROUND(agg.realized / (p.entry_price * p.size) * 100, 4)
        ELSE 0
    END,
    t.risk_reward = CASE
        WHEN p.size * COALESCE(p.sl_points, 0) > 0
            THEN ROUND(agg.realized / (p.size * p.sl_points), 4)
        ELSE NULL
    END
WHERE t.status <> 'CLOSED'
  AND t.pnl IS NULL;
