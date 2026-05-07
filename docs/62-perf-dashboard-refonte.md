# Étape 62 — Refonte du dashboard Performance

## Résumé

Trois ajustements coordonnés sur la page Performance, identifiés en cours de revue UX :

1. **P&L par période** repositionné à droite du P&L par symbole (lecture "par dimension" sur une même ligne) et étendu d'un mode **cyclique** qui agrège les trades par jour de semaine, semaine ISO ou mois sans tenir compte de l'année. Beaucoup plus actionnable pour spotter une saisonnalité ou un biais de jour qu'une suite de dates absolues.
2. **WR/RR par timeframe** sorti dans un widget dédié qui filtre les setups de catégorie `timeframe`. L'ancien widget "WR/RR par setup" continue d'exister mais exclut désormais les setups timeframe pour ne pas doublonner.
3. **WR/RR par type de compte** retiré du layout. Le code (action store, endpoint, controller, service, repo, i18n) reste en repo pour de futurs widgets configurables ; seul l'appel à `fetchByAccountType()` est désactivé dans le `fetchAll` de la page.

## Surface code

### Backend

| Fichier | Méthode | Changement |
|---|---|---|
| `api/src/Repositories/StatsRepository.php` | `getStatsByPeriod` | +3 cas dans le `match` : `day_of_week → WEEKDAY(eff)`, `iso_week → WEEK(eff, 3)`, `month_of_year → MONTH(eff)`. Les linéaires (`day/week/month/year`) restent inchangés. |
| `api/src/Repositories/StatsRepository.php` | `getStatsBySetup` | LEFT JOIN sur `setups` (cloisonné `user_id` + `deleted_at IS NULL`) avec `MAX(setups.category) AS category` dans le SELECT. La jointure utilise `JSON_TABLE COLUMNS (... COLLATE utf8mb4_unicode_ci)` pour aligner la collation avec celle de `setups.label`. |
| `api/src/Services/StatsService.php` | `getStatsByPeriod` | Whitelist étendue à 7 valeurs (`day, week, month, year, day_of_week, iso_week, month_of_year`). Mode invalide → `ValidationException 'stats.error.invalid_period_group'`. |

### Frontend

| Fichier | Changement |
|---|---|
| `frontend/src/views/PerformanceView.vue` | Refonte du layout (P&L par symbole + P&L par période sur la même ligne, ajout d'un widget WR/RR par timeframe en row 5, suppression du widget account-type). Toggle `<Select>` linéaire/cyclique dans le header du widget P&L par période. Les options de granularité sont une `computed` qui change selon l'axe choisi. Le `formatPeriodLabel` traduit les valeurs numériques renvoyées par le backend (`0..6` jours, `1..12` mois, `1..53` semaines ISO). Filtre côté client sur `category === 'timeframe'` pour les deux widgets setup/timeframe. `fetchByAccountType()` retiré de `fetchAll`. |
| `frontend/src/components/performance/StatsDetailDialog.vue` | Nouvelle dimension `setup_timeframe` : titre `performance.by_timeframe`, colonne identique à `setup`. Ouvre un dialog filtré sur les setups timeframe quand on clique sur le détail du nouveau widget. |
| `frontend/src/locales/fr.json` + `en.json` | 28 nouvelles clés en parité : `period_axis_linear/cyclic`, `period_day_of_week/iso_week/month_of_year`, `iso_week_short` (placeholder `{n}`), `weekdays.0..6`, `months.1..12`, `perf_by_timeframe`, `by_timeframe`. |

## Décisions de design

### Modes cycliques dans le même endpoint

Plutôt que de créer `/stats/by-period-cyclic`, on étend la valeur acceptée par `?group=` à 7 modes au lieu de 4. Pourquoi :
- Le contrat de réponse reste identique (`{period: string, total_pnl, win_rate, ...}`). Le frontend traduit la valeur de `period` côté UI selon le mode demandé.
- Une seule entrée whitelist à maintenir.
- Pas de duplication de la logique d'agrégation (le `dimensionStatsSelect()` est partagé).

### Filtrage timeframe côté frontend, pas côté backend

L'enrichissement de `getStatsBySetup` avec `category` permet aux deux widgets (setup hors-timeframe / timeframe seul) de partager **la même requête backend** avec filtrage en mémoire côté Vue. Avantages :
- Pas de duplication d'endpoint
- Une seule donnée à fetcher dans `fetchAll`
- Latence dashboard inchangée

Si un jour on veut splitter par chaque catégorie (timeframe + pattern + context séparés), c'est à coût zéro côté API.

### `WEEKDAY` plutôt que `DAYOFWEEK`

`WEEKDAY(eff)` retourne 0=Lundi … 6=Dimanche, ce qui matche directement l'ordre de lecture FR/EU naturel (lundi en premier). `DAYOFWEEK` aurait imposé un mapping de réordonnancement côté frontend ou un `CASE WHEN` dans le SQL.

### Pas d'endpoint dédié pour le timeframe

Pour l'instant, le widget WR/RR par timeframe consomme `bySetup` filtré côté front. Si on veut plus tard une UX différente (ex. heatmap timeframe × symbole), un endpoint dédié pourra être ajouté sans casser la consommation actuelle.

## Tests

5 tests d'intégration backend ajoutés (TDD strict, RED → GREEN) :

| Fichier | Test | Couvre |
|---|---|---|
| `StatsRepositoryTest.php` | `testGetStatsByPeriodGroupsByDayOfWeek` | Aggregation par WEEKDAY, validation des buckets numériques 0-6 |
| `StatsRepositoryTest.php` | `testGetStatsByPeriodGroupsByIsoWeekIgnoresYear` | Sémantique cyclique : trades de la semaine 19 sur 2025 et 2026 dans le même bucket |
| `StatsRepositoryTest.php` | `testGetStatsByPeriodGroupsByMonthOfYearIgnoresYear` | Idem pour les mois |
| `StatsRepositoryTest.php` | `testGetStatsBySetupReturnsCategoryWhenSetupExists` | Champ `category` retourné pour un setup catégorisé, NULL pour un setup sans catégorie |
| `StatsServiceTest.php` | `testGetStatsByPeriodAcceptsCyclicGroups` + `testGetStatsByPeriodRejectsInvalidGroup` | Whitelist du paramètre `group` étendue ET stricte (rejette `fortnight`) |

Pas de test Vitest dédié sur `PerformanceView.vue` : le changement est principalement du layout + computed declarations, la logique métier est concentrée dans le backend (déjà couvert TDD). Les tests Vitest existants du store (234 tests) passent.

Suite complète : 1132 tests backend OK (1126 → 1132, +6), 234 Vitest OK, zéro régression.

## Migration et compatibilité

- **Pas de migration SQL** : ni schéma ni data.
- **Compat ascendante API** : un client qui envoie `?group=month` continue de fonctionner exactement comme avant. Les nouveaux modes sont additifs.
- **Compat ascendante frontend** : aucun lien externe ne pointe vers une URL contenant un mode obsolète. Le toggle linéaire est sélectionné par défaut, mode `month` au montage — comportement identique à avant.

## Backlog associé

L'entrée "Refonte Performance" de `docs/evolutions.md` est retirée du backlog (cette doc en marque la livraison).

## Tweaks post-revue test env

Après revue sur l'env de test, trois ajustements ciblés :

1. **Toggle linéaire/cyclique masqué** dans l'UI. Le mode linéaire (dates absolues) s'est avéré peu utile à l'usage : l'angle cyclique (saisonnalité) couvre 95 % des besoins de lecture. Le `<Select>` axis est retiré du template, `periodAxisMode` est forcé à `'cyclic'` au montage. Le code des deux modes (computed `periodGroupOptions`, fonction `onPeriodAxisModeChange`, options linéaires en JSON) est conservé pour ré-exposer le toggle facilement si on revient en arrière.
2. **Labels simplifiés** dans le sélecteur cyclique. Plus besoin de désambiguïser "Jour de semaine / Semaine ISO / Mois de l'année" puisque le mode linéaire est invisible : on réutilise les labels existants `period_day` / `period_week` / `period_month` (jour / semaine / mois). Le sélecteur lui-même est passé d'un `<Select>` (dropdown) à un `<SelectButton>` PrimeVue (groupe de boutons horizontaux), qui se voit d'un coup d'œil et coûte un seul clic. Valeur par défaut : `day_of_week` (Jour) — la lecture day-of-week est l'angle le plus actionnable au quotidien.
3. **Layout : 4 widgets WR/RR au-dessus des 2 widgets P&L**. Nouvel ordre des rows :
   - Row 3 : WR/RR par symbole + WR/RR par setup
   - Row 4 : WR/RR par timeframe + WR/RR par session
   - Row 5 : P&L par symbole + P&L par période (cyclique)
4. **Seeder démo cohérent** : les 4 timeframes réels (M5 / M15 / H1 / H4) remplacent l'ancien tag "timeframe" sur "Trend Follow" (recatégorisé en `context`). Une fonction helper `pickTimeframe($symbol, $durationMinutes)` choisit le timeframe en fonction de la durée du trade (< 1h → M5, < 3h → M15, < 6h → H1, sinon H4) ou du symbole pour les open trades / orders. Chaque trade reçoit donc une combinaison `[timeframe, setup(s) existants]`. Distribution obtenue après reseed : M5×13, M15×23, H1×19, H4×3, Breakout×14, Pullback×13, Range×16, Trend Follow×20, Reversal×9.

