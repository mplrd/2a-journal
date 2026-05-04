# Étape 55 — R:R stocké à `NULL` (au lieu de 0) quand le SL n'est pas renseigné

## Résumé

Avant ce fix, le ratio Risk:Reward (`trades.risk_reward`) était stocké à **0** sur les trades qui n'avaient pas de SL renseigné (typiquement : trades importés via le format custom, où `sl_points` reste `NULL`). Mathématiquement, R:R nécessite une distance entrée→SL non nulle ; sans elle, la valeur n'est pas calculable. Le placeholder 0 polluait toutes les agrégations en aval :

- `AVG(t.risk_reward)` côté SQL ignore nativement les `NULL` mais inclut les `0` → la moyenne R:R affichée à l'utilisateur était systématiquement tirée vers le bas par chaque trade sans SL.
- `getRrDistribution` rangeait ces 0 dans la tranche « 0-1 », gonflant artificiellement ce bucket.
- `aggregateSessionStats` (stats par session) divisait la somme des R:R par le nombre **total** de trades, sans exclure les nulls/zeros placeholder.
- L'export texte d'un trade (partage) affichait « R/R: 0 » même quand le R:R n'était pas calculable, ce qui était trompeur.

Désormais, `risk_reward` est `NULL` quand il n'est pas calculable. Les agrégations excluent ces trades de leur dénominateur R:R, et l'affichage indique « — » (ou omet la ligne, selon le contexte).

Source du besoin : repéré en répondant à la question Q-01 du retour bêta de Robin (cf. `docs/retours-beta-tests.md`), initialement consigné en backlog dans `docs/evolutions.md`.

## Comportement

### Persistance d'un trade sans SL

- À la création d'une partielle (`TradeService::close`) ou à la finalisation (`TradeService::calculateRealizedMetrics`), si `sl_points` est `NULL` ou nul → `risk_reward` est stocké à `NULL`.
- Si `sl_points` est renseigné → comportement inchangé : `risk_reward = totalPnl / (size × slPoints)`.
- `pnl` et `pnl_percent` ne changent pas de logique (ils dépendent de `entry_price`, qui reste obligatoire).

### Stats agrégées

- **Moyenne R:R** (`getOverview`, `getStatsByXxx`) : utilise `AVG(t.risk_reward)`, qui ignore nativement les `NULL`. Donc la moyenne reflète uniquement les trades avec SL.
- **Stats par session** : la somme des R:R et le compteur `rrCount` n'incluent que les trades non-null. `avg_rr = sumRr / rrCount`, ou `NULL` si aucun trade n'a de R:R calculable.
- **Distribution R:R** (`getRrDistribution`) : ajoute `AND t.risk_reward IS NOT NULL` à la requête → les trades sans SL n'apparaissent dans aucun bucket (pas même dans le bucket par défaut « >3 » que la condition `ELSE` aurait pu attraper).

### Affichage

- **Cartes KPI dashboard** (`KpiCards.vue`) et **Stats détail dialog** (`StatsDetailDialog.vue`) : `formatRatio(value)` retournait déjà `'-'` quand `value == null`. Aucun changement nécessaire.
- **Graphes performance** (`PerformanceView.vue`, `dualMetricData`) : remplacement de `Number(d.avg_rr || 0)` par un mapping null-safe (`d.avg_rr == null ? null : Number(d.avg_rr)`) → Chart.js rend une discontinuité au lieu d'une barre à zéro pour une session sans R:R agrégé.
- **Export texte d'un trade** (`ShareService::generateText`) : la ligne « R/R: … » est omise quand `risk_reward` est `null` (cohérent avec la façon dont `exit_type` est conditionnellement rendu).

## Backend

### `TradeService::recalculateMetrics` (l. 776)

```php
'risk_reward' => $riskAmount > 0 ? round($totalPnl / $riskAmount, 4) : null,
```

### `TradeService::calculateRealizedMetrics` (l. 799)

```php
$riskReward = $riskAmount > 0 ? round($totalPnl / $riskAmount, 4) : null;
```

### `StatsService::aggregateSessionStats` (l. 130-165)

Tracking séparé de `$rrCount` (nombre de trades avec R:R non-null) :

```php
if ($t['risk_reward'] !== null) {
    $sumRr += (float) $t['risk_reward'];
    $rrCount++;
}
// ...
'avg_rr' => $rrCount > 0 ? round($sumRr / $rrCount, 2) : null,
```

### `StatsRepository::getRrDistribution` (l. 352)

```sql
{$where} AND t.risk_reward IS NOT NULL
```

### `ShareService::generateText` (l. 152-158)

```php
if ($trade['risk_reward'] !== null) {
    $rr = $this->num((float) $trade['risk_reward']);
    $lines[] = $withEmojis ? "⚖️ R/R: {$rr}" : "R/R: {$rr}";
}
```

## Migration

`api/database/migrations/016_nullify_risk_reward_when_no_sl.sql` — backfill idempotent qui passe `risk_reward` à `NULL` sur tous les trades existants dont la position parent n'a pas de `sl_points` :

```sql
UPDATE trades t
INNER JOIN positions p ON p.id = t.position_id
SET t.risk_reward = NULL
WHERE t.risk_reward IS NOT NULL
  AND (p.sl_points IS NULL OR p.sl_points = 0);
```

Pas de changement de schéma — la colonne `trades.risk_reward` était déjà nullable.

## Couverture des tests

| Test | Fichier | Scénario | Statut |
|------|---------|----------|--------|
| `testCloseTpPartialWithoutSlStoresNullRiskReward` | `tests/Unit/Services/TradeServiceTest.php` | Trade sans SL → partielle TP → `risk_reward` persisté à `NULL` | ✅ |
| `testGetStatsBySessionExcludesNullRiskRewardFromAvg` | `tests/Unit/Services/StatsServiceTest.php` | Session avec 3 trades dont 1 sans R:R → `avg_rr` = (sum 2 non-null) / 2, pas / 3 | ✅ |
| `testGenerateTextForClosedTradeWithoutRiskReward` | `tests/Unit/Services/ShareServiceTest.php` | Trade fermé sans R:R → ligne « R/R » absente du texte exporté | ✅ |

Tests existants couvrant le chemin nominal (R:R calculé avec SL) inchangés et toujours verts.

**Suite complète** : 1071/1071 PHPUnit + 212/212 Vitest.
