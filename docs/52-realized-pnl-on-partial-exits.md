# Étape 52 — P&L réalisé mis à jour à chaque sortie partielle

## Résumé

Les TP partiels et les sorties intermédiaires alimentent désormais immédiatement `trades.pnl` / `pnl_percent` / `risk_reward`, et les trades **SECURED** (partials pris mais pas fully closed) sont inclus dans toutes les statistiques. Avant cette étape, un swing trader voyait son P&L figé tant que le trade n'était pas fermé en totalité, alors que les partial_exits stockaient déjà la donnée correctement.

Cas concret couvert : trade size 2 BUY @18500, partial 1@18600 (+100), puis SL sur le reste 1@18450 (-50) → pendant la position SECURED le user voit `pnl=100`, après le SL final `pnl=50`. Idem pour le « SL financé » : partial +1R puis SL sur le reste à -1R sur la même taille restante → final 0 (BE), avec un running +1R visible entre les deux sorties.

## Fonctionnalités

### `TradeService::close()` met à jour `trades.pnl` à chaque sortie

Avant, l'update était conditionné à `remaining_size ≈ 0`. Maintenant, à chaque appel à `close()`, qu'il s'agisse d'un partial ou de la dernière sortie :

- `pnl`, `pnl_percent`, `risk_reward` sont recalculés via `calculateRealizedMetrics()` (renommée depuis `calculateFinalMetrics()` — la sémantique a glissé de « final » à « réalisé à ce jour »).
- L'agrégation se fait sur la liste à jour des `partial_exits` (récupérée *après* `partialExitRepo->create()`, donc le partial courant est inclus).
- Seuls les champs terminaux (`status=CLOSED`, `exit_type`, `closed_at`, `duration_minutes`) restent gated derrière `remaining_size ≈ 0`.
- La transition `OPEN → SECURED` au premier partial est conservée comme avant.

Les formules sont inchangées, seulement leur fréquence d'exécution :

```
pnl         = SUM(partial_exits.pnl)
pnl_percent = pnl / (entry_price * size) * 100
risk_reward = pnl / (size * sl_points)
```

### `StatsRepository` inclut SECURED + filtre date sur la dernière sortie

Deux changements dans `buildWhereClause()` :

1. Le filtre statut passe de `t.status = CLOSED` à `t.status IN (CLOSED, SECURED)`. Les OPEN sans partials restent exclus (P&L NULL = pas de réalisé).
2. Le filtre date utilise désormais une **« date effective »** par trade :
   ```sql
   COALESCE(t.closed_at, (SELECT MAX(pe.exited_at) FROM partial_exits pe WHERE pe.trade_id = t.id))
   ```
   Pour les CLOSED ça reste `closed_at` ; pour les SECURED ça devient le timestamp du dernier partial. Cela permet de filtrer un trade SECURED par sa fenêtre d'activité réelle.

L'expression est extraite dans `effectiveDate()` pour réutilisation. Elle est appliquée aussi dans :
- `getStatsByPeriod()` — pour le `DATE_FORMAT(...)` qui groupe par jour/semaine/mois/année.
- `localClosedAt()` (utilisé par `getHeatmap()`) — pour le `DAYOFWEEK()` / `HOUR()`.
- `getTradesForSessionStats()` — pour le SELECT et l'ORDER BY.

`getCumulativePnl()` n'a pas besoin du COALESCE : il itère déjà sur `partial_exits.exited_at` directement, donc les partials des SECURED apparaissent maintenant dans la courbe cumulée (puisque le WHERE englobant accepte SECURED).

### Migration 015 — Backfill SECURED existants

Sur les bases déployées avant ce release, des trades SECURED peuvent exister avec `pnl IS NULL` mais des `partial_exits.pnl` valorisés. La migration 015 reconstitue les agrégats avec les mêmes formules que `calculateRealizedMetrics()` :

```sql
UPDATE trades t
INNER JOIN positions p ON p.id = t.position_id
INNER JOIN (SELECT trade_id, SUM(pnl) AS realized_pnl FROM partial_exits GROUP BY trade_id) agg
        ON agg.trade_id = t.id
SET t.pnl = ROUND(agg.realized_pnl, 2),
    t.pnl_percent = ...,
    t.risk_reward = ...
WHERE t.status = 'SECURED'
  AND t.pnl IS NULL;
```

Idempotente : ne touche que les SECURED avec `pnl IS NULL`. Une seconde exécution est un no-op.

## Choix d'implémentation

### Pourquoi mettre à jour `trades.pnl` plutôt que dériver via une vue ou un computed column

Les agrégats stats (Overview, dimensions, période, heatmap) lisent tous `t.pnl` directement. Calculer dynamiquement à chaque requête (via JOIN sur partial_exits avec SUM) coûterait sur les filtres et les GROUP BY. Persister le P&L réalisé sur la ligne trade garde les requêtes simples, et la valeur est toujours synchrone puisque `TradeService::close()` est le seul point d'entrée pour modifier les partial_exits (et il met à jour `trades.pnl` dans la foulée, dans la même transaction logique).

### Pourquoi un seul helper `calculateRealizedMetrics` plutôt qu'une distinction partial/final

Les formules sont rigoureusement identiques : `SUM(partial_exits.pnl)` pour le P&L, ratio sur entry value pour le percent, ratio sur risk pour le RR. La seule différence partial vs final est la persistance des champs terminaux (`status`, `closed_at`, `exit_type`, `duration_minutes`). Garder une seule fonction empêche la dérive entre les deux chemins de calcul si une formule évolue plus tard.

### Pourquoi `COALESCE(t.closed_at, MAX(pe.exited_at))` plutôt qu'une colonne `realized_at` dénormalisée

Une colonne dédiée serait plus performante (pas de subquery) mais ajoute un état à maintenir dans `TradeService::close()` *et* dans la migration de backfill, avec le risque qu'elle se désynchronise. La subquery corrélée par `trade_id` est rapide en pratique : un trade a O(1..10) partial_exits, et un index existe déjà sur `partial_exits(trade_id)` (FK MariaDB par défaut). On évite une schéma migration + un nouveau champ à backfiller.

### Trade-offs sémantiques sur les KPI

- **Win rate** : un SECURED avec partial à +1R compte désormais comme « win » (pnl_percent > BE). C'est juste sémantiquement (le user a réalisé du gain), mais ça peut changer la définition que le user avait en tête.
- **Best/Worst trade** : un SECURED en cours peut prendre la place de « best » ou « worst » en se basant sur son réalisé partiel. Sera réajusté quand le reste se clôture.
- **Profit factor** : agrège SECURED + CLOSED. Pour un swing trader avec plusieurs positions ouvertes ayant pris des partials, le profit factor reflète maintenant la photo globale, pas seulement les trades définitivement fermés.

Ces glissements sont assumés : ils donnent une vision « à ce jour » plutôt qu'une vision rétroactive figée — précisément ce que demandait l'utilisateur swing.

### Pourquoi pas de breakdown realized vs unrealized

Pour aller plus loin (P&L flottant sur la portion encore ouverte d'un SECURED), il faudrait un prix marché temps réel par symbole. Hors scope ici — on s'en tient au P&L *réalisé* uniquement, qui est déjà persisté.

## Couverture des tests

| Surface | Tests |
|---|---|
| Backend — `TradeServiceTest` | 48/48 ✓ (3 nouveaux : `testClosePartialExitUpdatesRunningPnl`, `testCloseSecondPartialAccumulatesRealizedPnl`, `testCloseSlAfterPartialFinalizesWithCumulativePnl`) |
| Backend — `StatsRepositoryTest` | 58/58 ✓ (2 nouveaux : `testGetOverviewIncludesSecuredTradesWithRealizedPnl`, `testGetOverviewFiltersDateRangeUsesPartialExitDateForSecured`, plus le rename de l'ancien `testGetOverviewExcludesNonClosedTrades` → `testGetOverviewExcludesOpenTradesWithoutExits`) |
| Backend — suite globale | 1055/1055 ✓ |
| Migration 015 | exécutée localement OK, idempotente |

Couverture spécifique du cas user (partial puis SL sur le reste, "financé" ou non) : `testCloseSlAfterPartialFinalizesWithCumulativePnl`.

## Évolutions retirées de `docs/evolutions.md`

- (rien — feature née d'un retour utilisateur direct)
