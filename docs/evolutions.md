# Évolutions à prévoir

Liste des améliorations identifiées en cours de route mais sortant du scope d'une feature en cours. À planifier / prioriser quand les branches concernées seront mergées.

## Statut / calculs

### RR négatif sur un compte 100% shorts en profit

**Contexte** : sur un compte qui n'a que des trades SELL et qui est globalement profitable, le R:R affiché ressort négatif. Symptôme probable : un signe non inversé quelque part pour les SELL dans le calcul du R:R agrégé (StatsRepository ou StatsService).

**Diag 2026-04-24** :
- Code relu : `TradeService::calculateFinalMetrics` (l.568) calcule `rr = totalPnl / (size * slPoints)` où `totalPnl` agrège des `partial_exits.pnl` déjà signés via `directionMultiplier` (l.287) → OK.
- Agrégation : `StatsRepository::getOverview` et `dimensionStatsSelect` font simplement `AVG(t.risk_reward)` sur la valeur stockée → OK.
- Seeder démo : formule cohérente BUY/SELL, avg_rr SELL = +0.537 en démo.
- Tentative de reproduction en réimportant le compte réel : **non reproductible au 2026-04-24**.
- Guard test posé : `StatsFlowTest::testAvgRrPositiveWhenAllSellsProfitable` (crée 3 SELL gagnants via l'API, vérifie `avg_rr > 0` sur `/stats/overview` et `/stats/by-direction`).

**À faire si ça revient** :
- Screenshot + URL (filtres actifs dans la querystring).
- Snapshot du trade concerné : `SELECT pnl, risk_reward, direction, entry_price, avg_exit_price, size, sl_points FROM trades t JOIN positions p ON p.id=t.position_id WHERE ...`.
- Vérifier si `risk_reward` est négatif au niveau d'un trade (bug de persistence) ou si l'agrégation est en cause.

**Repéré le** : 2026-04-23.
**Statut** : en veille, non reproductible, filet en place.
**Priorité** : haute si ça revient (fausse les stats).

---

## UX

### Auto-refresh des filtres Performance (pas de bouton "Appliquer")

**Contexte** : sur `PerformanceView` (et `DashboardFilters`), changer un filtre nécessite de cliquer "Appliquer" pour voir le résultat. Sur les écrans Trades / Orders / Positions, les filtres se rafraîchissent à la volée (chaque toggle de badge → fetch immédiat). L'inconsistance UX vaut alignement.

**À faire** :
- Sur chaque modification d'un input dans `DashboardFilters`, déclencher `applyFilters` directement (avec petit debounce ~250ms pour les inputs rapides)
- Retirer ou cacher le bouton "Appliquer" (ou le garder en "Reset" only)
- Vérifier l'effet sur le Dashboard et la Performance (les deux consomment ce composant)

**Repéré le** : 2026-04-29.
**Statut** : noté pour plus tard (après validation de la pass UI badge + date range).
**Priorité** : moyenne — confort utilisateur, alignement avec le reste des filtres de l'app.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
