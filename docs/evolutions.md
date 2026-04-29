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

## Code / conventions

### `Direction` enum non miroir côté frontend

**Contexte** : `DashboardFilters.vue:34-36` hardcode les valeurs `'BUY'` / `'SELL'` dans `directionOptions`. Côté backend, l'enum `Direction` existe (`api/src/Enums/Direction.php`) mais il n'a pas de miroir dans `frontend/src/constants/`. Soulevé par `/check-quality` lors de l'étape 47.

**À faire** :
- Créer `frontend/src/constants/direction.js` avec `export const DIRECTION = { BUY: 'BUY', SELL: 'SELL' }` (suivre la convention des autres constants déjà présents pour les autres enums).
- Remplacer les littéraux dans `DashboardFilters.vue` (et chercher d'autres occurrences avec `grep -rn "'BUY'\|'SELL'" frontend/src/`).

**Repéré le** : 2026-04-29.
**Statut** : warning préexistant, hors scope de l'étape 47.
**Priorité** : faible (purement cosmétique tant qu'aucune autre valeur ne s'ajoute à l'enum).

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
