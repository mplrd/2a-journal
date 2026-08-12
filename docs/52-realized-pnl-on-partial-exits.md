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
- En complément, un helper défensif **`recalcRealizedMetrics(tradeId)`** est appelé depuis `markBeReached()` et `update()`. Il re-dérive en partant des entrées actuelles du trade : si `entry_price`, `direction`, `size` ou `sl_points` changent via `update()`, **chaque `partial_exits.pnl` est recalculé** (`(exit_price - entry_price) * size * direction_multiplier`) avant d'agréger en `trades.pnl`. Sinon les formules diveregent (la somme des partials reste sur l'ancien `entry_price` alors que `pnl_percent`/`risk_reward` utilisent le nouveau).
- La transition `OPEN → SECURED` est restreinte au cas **`exit_type === BE`** : seul un allégement au BE (= SL ramené au cours d'entrée) sécurise réellement le trade. Un partial TP laisse le SL en place sur le restant — le trade reste OPEN, même s'il a réalisé du P&L. Cohérent avec le métier : « secured » signifie « plus de risque sur le restant ». Le flow `markBeReached()` (BE atteint sans partial) reste inchangé et continue de promouvoir OPEN → SECURED.

Les formules sont inchangées, seulement leur fréquence d'exécution :

```
pnl         = SUM(partial_exits.pnl)
pnl_percent = pnl / (entry_price * size) * 100
risk_reward = pnl / (size * sl_points)
```

### `StatsRepository` filtre sur P&L réalisé + date effective

Deux changements dans `buildWhereClause()` :

1. Le filtre statut est remplacé par **`t.pnl IS NOT NULL`**. Le critère devient *« le trade a-t-il du P&L réalisé »*, indépendant du statut. C'est délibéré : un trade peut avoir pris un partial TP et rester en `OPEN` (par exemple si le SL n'a pas été remonté à BE — le trader ne considère pas le trade sécurisé). Filtrer sur `status IN (CLOSED, SECURED)` raterait ce cas. Puisque `TradeService::close()` alimente `trades.pnl` à chaque sortie, `pnl IS NULL` veut exactement dire « aucune sortie jamais prise ».
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

### La date effective ne convient PAS pour additionner de l'argent par jour (revu le 2026-08-11)

La « date effective » réduit un trade à **une** date. C'est correct pour
**filtrer** une fenêtre, et pour les agrégats **par trade** (taux de réussite,
comptages, R:R moyen) — on ne coupe pas un gagnant en deux.

C'est faux dès qu'on additionne **de l'argent par jour**. Un trade dont les
paliers tombent des jours différents voit tout son réalisé attribué au dernier :
banquer 400 au TP1 lundi, prendre le TP2 mercredi, et **lundi perd
silencieusement ses 400** au profit de mercredi. Le montant se déplace à chaque
nouveau palier. Constaté sur l'historique réel : plusieurs trades s'étalaient
déjà sur deux à trois jours.

`getDailyPnl()` n'utilise donc plus `effectiveDate()`. Il additionne deux
contributions :

1. **chaque sortie partielle**, sur `DATE(pe.exited_at)` ;
2. **ce que la clôture réalise au-delà de ces paliers**, sur `DATE(t.closed_at)` —
   soit `t.pnl` moins les paliers déjà comptés.

Le reliquat couvre les deux cas que les paliers ne décrivent pas : un trade
clôturé sans jamais avoir enregistré de partielle (tout son P&L tombe à la
clôture), et un total annoncé par le broker au-dessus de la somme des jambes,
swap et commissions inclus. Sur 547 trades réels : 542 ont `t.pnl` égal à la
somme exacte de leurs paliers, 4 n'ont aucune partielle, 1 présente un écart.

**Un palier à zéro compte, un reliquat à zéro non.** Une sortie à l'équilibre est
un événement réel et son jour est un jour d'activité ; un reliquat nul n'est
qu'un artefact du modèle, l'enregistrer inventerait une journée.

`trade_count` vaut « trades ayant réalisé quelque chose ce jour-là », compté une
seule fois même si plusieurs de leurs paliers sont tombés le même jour.

**Deux requêtes plutôt qu'une UNION** : le filtre doit porter sur une colonne de
date **différente** de chaque côté — une jambe se filtre sur sa propre date de
sortie, pas sur celle de son trade — et un paramètre nommé ne peut pas être
répété quand les requêtes préparées émulées sont désactivées. Fusionner en PHP
évite au passage le `GROUP BY` sur l'expression à sous-requête que
`ONLY_FULL_GROUP_BY` refuse en production.

### Découper par jour ne sert à rien si le trade est filtré en amont (2026-08-12)

`buildWhereClause()` impose `t.pnl IS NOT NULL` à **toutes** les agrégations, y
compris à la requête des jambes. Une jambe n'est donc datée correctement que si
son trade porte déjà un réalisé.

C'est ce qui a fait croire le découpage inopérant après sa livraison. Le trade
NAS 10059 avait bien sa sortie partielle de 406.13 horodatée au 11/08 dans
`partial_exits`, mais `trades.pnl` était resté `NULL` : la remontée côté synchro
ne se déclenchait qu'à l'insertion d'une jambe, et cette jambe lui était
antérieure (voir `docs/22-broker-connectors.md`). Le trade entier était donc
invisible pour toutes les statistiques — l'argent n'allait pas au mauvais jour,
il n'allait **nulle part**. Le calendrier affichait 498.00 au 11/08 au lieu de
904.13.

Les deux mécanismes sont indissociables : le découpage décide *quel jour*, la
remontée décide *si le trade existe* aux yeux des agrégats.

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
| Backend — `TradeServiceTest` | 49/49 ✓ (4 nouveaux : `testCloseTpPartialUpdatesPnlButKeepsTradeOpen`, `testCloseBePartialPromotesOpenToSecured`, `testCloseSecondPartialAccumulatesRealizedPnl`, `testCloseSlAfterPartialFinalizesWithCumulativePnl`) |
| Backend — `StatsRepositoryTest` | 59/59 ✓ (3 nouveaux : `testGetOverviewIncludesSecuredTradesWithRealizedPnl`, `testGetOverviewIncludesOpenTradesWithPartialExits`, `testGetOverviewFiltersDateRangeUsesPartialExitDateForSecured`, plus le rename de `testGetOverviewExcludesNonClosedTrades` → `testGetOverviewExcludesOpenTradesWithoutExits`) |
| Backend — `TradeFlowTest` (intégration) | 31/31 ✓ (les scénarios « partial → SECURED » utilisent maintenant `exit_type=BE`, plus un nouveau `testUpdateEntryPriceRecomputesPartialAndTradePnl`) |
| Backend — suite globale | 1058/1058 ✓ |
| Migration 015 | exécutée localement OK, idempotente |

Couverture spécifique du cas user (partial puis SL sur le reste, "financé" ou non) : `testCloseSlAfterPartialFinalizesWithCumulativePnl`.

## Évolutions retirées de `docs/evolutions.md`

- (rien — feature née d'un retour utilisateur direct)
