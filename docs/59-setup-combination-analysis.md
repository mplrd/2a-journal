# Étape 59 — Analyse comparative par combinaisons de setups

## Résumé

Sur la vue **Performance**, la tuile « Win Rate & R:R par setup » affiche déjà des stats par setup individuel (chaque trade portant `[A, B, C]` est compté 3 fois, une fois par setup). Cette analyse répond à la question « quel setup performe ? » mais pas à « cette **combinaison** de setups (A + B + C ensemble) performe-t-elle ? », ni à « **laquelle** de mes 3 combinaisons est la meilleure ? ».

E-06 ajoute une analyse **ad-hoc multi-combinaisons** déclenchée par un bouton « Aller plus loin » sur cette même tuile. L'utilisateur configure N combinaisons à la volée (jusqu'à 10), clique sur **Analyser**, et obtient une lecture comparative en un coup d'œil :

- Pour chaque combinaison, les stats agrégées des trades qui portent **tous** ses setups (mode `ALL` / logique `AND`).
- Une **baseline partagée** : les mêmes stats calculées sur l'ensemble des trades de l'utilisateur (sur les mêmes filtres globaux compte/période), sans contrainte de combinaison.
- Trois bar charts horizontaux empilés (Win Rate, R:R moyen, P&L cumulé) avec une barre par série — la baseline en gris foncé, chaque combinaison dans une couleur distincte conservée d'un chart à l'autre.
- Un badge **nb de trades** à côté de chaque label (légende), pour signaler les combinaisons sous-échantillonnées sans pour autant les masquer.

Pas de nouvelle entité persistée : c'est une analyse **ad-hoc**, l'utilisateur compose, regarde, recompose. Si l'usage décolle plus tard, on pourra ajouter une persistance (entité `SetupCombination` nommée + CRUD).

Source du besoin : `docs/retours-beta-tests.md` ticket E-06.

## Comportement

### Déclenchement

Sur la card **Win Rate & R:R par setup** de `PerformanceView`, un bouton **« Aller plus loin »** (`pi-sliders-h`, severity secondary, text) est ajouté dans le slot `header-actions` à côté du bouton « Voir le détail » existant. Click → ouverture du `<SetupCombinationDialog>`.

Choix UX : modale plutôt que card dédiée parce que :
- La PerformanceView est déjà dense (5 rows de charts) ; une nouvelle card permanente prendrait beaucoup de place pour un usage non-quotidien.
- L'analyse est **exploratoire** (l'utilisateur teste 2-3 combinaisons d'affilée, en réajuste, recommence) ; une modale donne plus d'espace au composant complet (liste de combinaisons + 3 charts) sans polluer le dashboard.

### Modale (`SetupCombinationDialog.vue`)

Layout en **2 colonnes** (`grid grid-cols-1 lg:grid-cols-[400px_1fr] gap-6`, modale élargie à `1100px max`) ; sur mobile (`<lg`), repassage en pile verticale.

**Colonne gauche — configuration** :
- Liste de combinaisons configurables : par défaut une seule ligne avec un MultiSelect PrimeVue. L'utilisateur peut **+ Ajouter une combinaison** (jusqu'à 10) et 🗑 supprimer (uniquement si ≥ 2 lignes). Chaque ligne = un MultiSelect indépendant des autres ; les setups peuvent se répéter d'une ligne à l'autre. Lignes vides = ignorées dans le payload.
- **Pas de bouton « Analyser »** : auto-fetch sur changement (cf. ci-dessous).
- Zone d'erreur sous le bouton « Ajouter une combinaison » si l'API renvoie une erreur (silencieuse — le dernier résultat valide reste à l'écran).

**Colonne droite — lecture** :
- **3 mini-bar-charts horizontaux** empilés verticalement : Win Rate (%), R:R moyen, P&L cumulé. Une barre par série (baseline + une par combinaison configurée).
- **Le label de l'axe Y inclut le nombre de trades** entre parenthèses : `Tous mes trades (48)`, `Breakout + Trend Follow (9)`. Pas de légende standalone — la couleur de chaque barre se lit directement depuis le label adjacent.
- Couleur fixe par série (constance d'un chart à l'autre), baseline en gris (`#6b7280`) toujours en première position, combos dans la palette `CHART_PALETTE` + 5 couleurs étendues.
- Spinner overlay pendant un refetch en cours, au-dessus des charts (`absolute inset-0`, semi-transparent, pointer-events-none — le formulaire reste interactif).

### Auto-fetch + debounce

Plutôt qu'un bouton « Analyser » à cliquer manuellement, la modale **refetche automatiquement** :
- À l'ouverture (`watch visible` avec `{ immediate: true }`) → fetch baseline-seule (la requête backend accepte `combinations: []`).
- À chaque changement du contenu d'une ligne remplie → debounce **300ms** puis fetch.
- Watch sur une **signature stable** (`JSON.stringify(filledRows.map(r => [...r.setupIds].sort()))`) : ignore l'ajout/suppression de lignes vides (no-op côté payload).
- Pendant le debounce, le résultat précédent reste affiché ; le spinner ne se déclenche qu'au moment effectif de la requête.

Le bouton « Analyser » est volontairement supprimé : (1) il faisait double emploi avec le modèle d'exploration (l'utilisateur compose, regarde, recompose), (2) un modèle reactive donne une lecture immédiate à chaque tweak — pas besoin de penser « j'ai fini, je clique ».

### Choix : 3 charts groupés par métrique, pas un chart unique

L'alternative était un seul bar chart avec 1 barre par combinaison et plusieurs séries (winrate / R:R / P&L). Refusée parce que :
- Échelles incompatibles (winrate en %, R:R en ratio, P&L en €). Un dual-axis aurait fait passer P&L à la trappe.
- Un chart par métrique avec **couleur de combinaison conservée** est plus lisible : l'utilisateur voit en un coup d'œil « ma combo verte est meilleure partout sauf en R:R ».

Le **nombre de trades** vit dans le label de l'axe Y entre parenthèses (`Breakout + Trend Follow (9)`) plutôt qu'en barre dédiée : c'est une info de fiabilité, pas une perf comparable visuellement avec les 3 autres métriques. Cette intégration au label économise une légende standalone (qui n'apportait rien de plus que la pastille de couleur — déjà visible dans la barre adjacente).

### Filtres hérités

La modale **n'a pas ses propres filtres** : elle hérite des filtres globaux de `PerformanceView` (compte, plage de dates, etc.) via `useStatsStore().filters`. Le store action `analyzeSetupCombinations(combinations)` les fusionne automatiquement avec le payload :

```js
async function analyzeSetupCombinations(combinations, match = 'all') {
  const payload = { ...filters.value, combinations, match }
  const response = await statsService.analyzeSetupCombinations(payload)
  return response.data
}
```

Conséquence : la baseline est calculée **sur le même périmètre** que les combinaisons. Si l'utilisateur a filtré « compte X, mois en cours », la baseline sera « tous mes trades du mois en cours sur le compte X » — pas tous ses trades depuis le début, ce qui rendrait la comparaison trompeuse.

Reset : à chaque ouverture/fermeture (watch sur `props.visible`), la liste de combinaisons est ramenée à une seule ligne vide et le résultat est effacé. Pas d'état persistant entre deux ouvertures.

## Backend

### Repository

`StatsRepository::getStatsForSetupCombination(int $userId, array $setupNames, array $filters = []): array`

Inchangé depuis la version singulière : reçoit une liste de noms (pas d'IDs — `p.setup` est un JSON array de strings), retourne une ligne agrégée. Le service appelle ce repo `N+1` fois par requête (une fois par combinaison configurée + une fois pour la baseline avec `$setupNames = []`).

```sql
SELECT { dimensionStatsSelect() }
FROM trades t
INNER JOIN positions p ON p.id = t.position_id
WHERE p.user_id = :user_id AND t.pnl IS NOT NULL
  AND ... ( filtres globaux : account_id, date_from/to, direction, ... )
  AND JSON_CONTAINS(p.setup, :combo_setup_0)
  AND JSON_CONTAINS(p.setup, :combo_setup_1)
  AND ...
```

Une seule clause `JSON_CONTAINS` par setup, **chaînées en AND** (cf. critique du `JSON_TABLE` éclaté de `getStatsBySetup`). Les placeholders sont nommés `combo_setup_{i}` (compteur entier interne, pas user input) pour éviter toute collision avec le filtre `setups` existant qui utilise `setup_{i}`.

### Service

`StatsService::getSetupCombinationsAnalysis(int $userId, array $combinations, string $matchMode, array $filters = []): array`

Pipeline de validation, fail-fast (aucun SQL exécuté tant qu'une seule combinaison est invalide) :

1. `combinations` vide → **légal** : on saute la résolution per-combinaison et on calcule juste la baseline. C'est le cas « modale tout juste ouverte, rien encore configuré » — l'UX a besoin de pouvoir afficher la baseline seule.
2. Plus de `MAX_COMBINATIONS_PER_REQUEST = 10` combinaisons → `ValidationException('stats.error.too_many_combinations')`.
3. `match` → `SetupCombinationMatchMode::tryFrom($matchMode)` ; `null` → `ValidationException('stats.error.invalid_match_mode')`.
4. **Pour chaque combinaison** :
   - Extraction de `setup_ids` du tableau associatif (forme `['setup_ids' => int[]]`, choisie pour laisser place à des options par-combinaison plus tard sans casser le contrat — par ex. un `name` quand on ajoutera la persistance).
   - `array_unique(array_filter(array_map('intval', ...), > 0))` (dédup + cast int + virer ≤ 0).
   - Vide après nettoyage → `ValidationException('stats.error.setup_combination_required')`.
   - Plus de `MAX_COMBINATION_SETUPS = 20` IDs dans une combo → `ValidationException('stats.error.setup_combination_too_large')` (anti-DoS combinatoire ; la dédup est faite **avant** ce check).
   - Pour chaque ID, `setupRepo->findById($id)` ; absent ou `user_id !== $userId` → `ForbiddenException('setups.error.forbidden')`.
   - Récupération du `label` après ownership check.
5. `validateFilters` (existant) sur les filtres globaux (compte, dates, direction…).
6. Une fois TOUS les inputs validés : `N + 1` appels au repo (une fois par combo, plus la baseline).

Retour :
```php
[
  'baseline' => [ total_trades, wins, losses, win_rate, total_pnl, avg_rr, profit_factor ],
  'combinations' => [
    [ 'setup_ids' => [10, 11], 'setups' => ['Breakout', 'Pullback'], 'stats' => [ ... ] ],
    [ 'setup_ids' => [12],     'setups' => ['Range'],                'stats' => [ ... ] ],
  ],
  'match' => 'all',
]
```

### Enum `SetupCombinationMatchMode`

```php
enum SetupCombinationMatchMode: string
{
    case ALL = 'all';   // tous les setups demandés doivent être présents (AND)
}
```

V1 expose uniquement `ALL`. Réservé pour plus tard : `ANY` (OR), `EXACT` (la liste de setups du trade = exactement la liste demandée). L'API accepte déjà un champ `match` côté client pour ne pas casser la signature plus tard ; toute autre valeur que `'all'` retourne 422.

### Controller + route

```
POST /stats/setup-combinations
Body: {
  combinations: [
    { setup_ids: number[] },
    ...
  ],
  match?: 'all',           // defaults to 'all'
  account_id?: number,
  account_ids?: number[],
  date_from?: 'YYYY-MM-DD',
  date_to?: 'YYYY-MM-DD',
  direction?: 'BUY' | 'SELL',
  symbols?: string[]
}
Response 200: {
  success: true,
  data: { baseline: {...}, combinations: [{ setup_ids, setups, stats }, ...], match: 'all' }
}
Response 422: too_many_combinations | setup_combination_required (per-combo)
              | setup_combination_too_large (per-combo) | invalid_match_mode
              | invalid_date | invalid_direction
Response 403: setups.error.forbidden | accounts.error.forbidden
Response 401: TOKEN_*
```

Méthode HTTP : `POST` parce que le payload est un tableau de tableaux + filtres potentiellement complexes ; un GET avec query string deviendrait illisible.

`StatsController::extractCombinationsFilters($body)` mirroir de `extractFilters($request)` : même forme de filtres, lus depuis le body au lieu du query string. Délégation au service ensuite, pas de logique métier dans le contrôleur.

Route protégée par `$authMiddleware + $requireSubscription` (cohérent avec tous les `/stats/*`).

### i18n (clés ajoutées)

Backend (toutes en `message_key`) :
- `stats.error.too_many_combinations`
- `stats.error.setup_combination_required` (per-combination)
- `stats.error.setup_combination_too_large` (per-combination)
- `stats.error.invalid_match_mode`
- réutilise : `setups.error.forbidden`, `accounts.error.forbidden`, `stats.error.invalid_date`, `stats.error.invalid_direction`

Frontend :
- `performance.go_further` (label du bouton sur la card)
- `performance.setup_combination.title`
- `performance.setup_combination.intro`
- `performance.setup_combination.placeholder`
- `performance.setup_combination.add_combination`
- `performance.setup_combination.remove_combination`
- `performance.setup_combination.combination_label` (avec `{n}`)
- `performance.setup_combination.global`
- `performance.setup_combination.chart_win_rate`
- `performance.setup_combination.chart_avg_rr`
- `performance.setup_combination.chart_total_pnl`

Toutes alignées entre `fr.json` et `en.json`. Les `(N)` après chaque label de série dans les charts sont composés inline côté frontend (pas de clé i18n dédiée — `${label} (${count})` est suffisamment universel).

## Données de démonstration (seed)

Le seed `api/database/seed-demo.php` accepte désormais un setup unique (string) **ou** une liste de setups (array) pour chaque trade. Les combinaisons préchargées dans le compte demo permettent de tester l'analyse immédiatement :

| Combinaison | Trades | Winrate | Comportement attendu |
|---|---|---|---|
| **Breakout + Trend Follow** | 9 (8 wins, 1 BE) | 89% | Bar dépasse largement la baseline sur winrate ET R:R — combo gagnant |
| **Pullback + Range** | 4 (2 TP, 2 SL) | 50% | Bar inférieure à la baseline (~10pp en moins) |
| **Breakout + Trend Follow + Pullback** | 1 | 100% | Déclenche le warning « échantillon trop petit » |
| (Baseline) | 48 | 60% | Référence grise |

## Couverture des tests

| Test | Fichier | Scénario | Statut |
|------|---------|----------|--------|
| `testGetStatsForSetupCombinationMatchesAllSetupsPresent` | `tests/Integration/Repositories/StatsRepositoryTest.php` | Trade `[A,B]` matche la combo `[A,B]`, partiels (A seul, B seul) ne matchent pas | ✅ |
| `testGetStatsForSetupCombinationMatchesSupersetTrades` | idem | Trade `[A,B,C]` matche la combo `[A,B]` (sur-ensemble OK, AND ≠ EXACT) | ✅ |
| `testGetStatsForSetupCombinationReturnsZerosWhenNoMatch` | idem | Aucun match → `total_trades=0`, `total_pnl=0`, `avg_rr=null` | ✅ |
| `testGetStatsForSetupCombinationEmptyNamesReturnsBaseline` | idem | `[]` (baseline) → tous les trades matchant les filtres globaux | ✅ |
| `testGetStatsForSetupCombinationRespectsAccountFilter` | idem | Combo identique sur 2 comptes, filtre account_id → seul le compte filtré | ✅ |
| `testGetStatsForSetupCombinationRespectsDateRangeFilter` | idem | Combo identique sur 2 mois, filtre date_from/date_to → seule la période filtrée | ✅ |
| `testGetSetupCombinationsAnalysisAcceptsEmptyCombinationsAndReturnsBaselineOnly` | `tests/Unit/Services/StatsServiceTest.php` | `[]` → 200 avec `baseline` seul + `combinations: []` (cas modale fraîchement ouverte) | ✅ |
| `testGetSetupCombinationsAnalysisRejectsCombinationWithEmptyIds` | idem | `[{ setup_ids: [] }]` → ValidationException | ✅ |
| `testGetSetupCombinationsAnalysisRejectsCombinationWithNonPositiveIds` | idem | `[{ setup_ids: [0,-3] }]` → ValidationException | ✅ |
| `testGetSetupCombinationsAnalysisRejectsTooManyIdsInOneCombination` | idem | 21 IDs dans une combo → ValidationException `setup_combination_too_large` | ✅ |
| `testGetSetupCombinationsAnalysisRejectsTooManyCombinations` | idem | 11 combos → ValidationException `too_many_combinations` | ✅ |
| `testGetSetupCombinationsAnalysisRejectsInvalidMatchMode` | idem | `match='wat'` → ValidationException `invalid_match_mode` | ✅ |
| `testGetSetupCombinationsAnalysisRejectsForeignSetup` | idem | Setup `user_id=99` accédé par user 1 → ForbiddenException | ✅ |
| `testGetSetupCombinationsAnalysisRejectsMissingSetup` | idem | Setup ID inexistant → ForbiddenException | ✅ |
| `testGetSetupCombinationsAnalysisReturnsBaselinePlusOneEntryPerCombination` | idem | 2 combos → 3 appels repo (combo1, combo2, baseline), shape `{baseline, combinations[2], match}` | ✅ |
| `testGetSetupCombinationsAnalysisDeduplicatesIdsWithinACombination` | idem | `[10,10,10]` → repo appelé avec `['Breakout']` (dédup) | ✅ |
| `testGetSetupCombinationsAnalysisAppliesGlobalFiltersToBothComboAndBaseline` | idem | filters propagés à TOUS les appels repo (combos + baseline) | ✅ |
| `testGetSetupCombinationsAnalysisValidatesAccountOwnership` | idem | account_id appartenant à un autre user → ForbiddenException | ✅ |
| `testSetupCombinationsRequiresAuth` | `tests/Integration/Stats/StatsFlowTest.php` | POST sans Authorization → 401 | ✅ |
| `testSetupCombinationsAcceptsMissingCombinationsKeyAndReturnsBaselineOnly` | idem | body sans `combinations` → 200 baseline-seule | ✅ |
| `testSetupCombinationsAcceptsEmptyArrayAndReturnsBaselineOnly` | idem | `combinations: []` → 200 baseline-seule | ✅ |
| `testSetupCombinationsRejectsForeignSetup` | idem | setup ID d'un autre user → 403 | ✅ |
| `testSetupCombinationsReturnsBaselineAndOneEntryPerCombination` | idem | 2 combos `[A,B]` (2 trades) et `[A,C]` (1 trade) sur 4 trades → response `combinations.length=2`, `baseline.total_trades=4` | ✅ |
| `testSetupCombinationsAppliesAccountFilter` | idem | account_id dans le body → seul le compte filtré comptabilisé sur les 2 stats | ✅ |
| `analyzeSetupCombinations passes combinations and merges global filters` | `frontend/src/__tests__/stats-store.spec.js` | Store action fusionne `filters.value` + `combinations` | ✅ |
| `does not render dialog content when not visible` | `frontend/src/components/performance/__tests__/SetupCombinationDialog.spec.js` | `visible=false` → pas de DOM, pas de fetch | ✅ |
| `fetches the baseline alone as soon as the modal opens` | idem | Ouverture → fetch immédiat avec `combinations: []` | ✅ |
| `renders one combination row by default and an "Add combination" button` | idem | Initial : 1 row vide + bouton "+" | ✅ |
| `does NOT render an "Analyze" button (auto-fetch model)` | idem | Garantit l'absence du bouton manuel | ✅ |
| `lets the user add and remove combination rows` | idem | Click "+" → 2 rows ; 🗑 → retour à 1 ; bouton 🗑 caché si seule | ✅ |
| `auto-refetches when a row is filled, sending the active combinations` | idem | Sélection dans une row + debounce → fetch avec la combo configurée | ✅ |
| `debounces rapid changes to a single fetch` | idem | 3 changements consécutifs → 1 seul fetch après le timer (état final) | ✅ |
| `inherits global stats filters in the payload` | idem | Filtres du store fusionnés dans le payload (incluant le fetch d'ouverture) | ✅ |
| `renders 3 horizontal bar charts whose Y-axis labels include the trade counts` | idem | 3 `<Chart type="bar">` ; labels `Tous mes trades (48)` / `Breakout (9)` | ✅ |
| `does NOT render a stand-alone color legend or "too few trades" warning` | idem | Garantit l'absence de la légende et du warning supprimés | ✅ |
| `uses the 2-column layout container` | idem | Présence de `[data-test="dialog-grid"]` + `config-column` + `charts-column` | ✅ |

**Suite complète** : 1109/1109 PHPUnit + 234/234 Vitest.

## Limitations connues / Évolutions futures

- **Mode AND uniquement** (v1). Réservé pour plus tard si demande utilisateur : `OR` (au moins un setup présent), `EXACT` (correspondance stricte). L'API accepte déjà un champ `match` côté client.
- **Pas de persistance des combinaisons**. C'est volontairement ad-hoc : on attend de voir si les utilisateurs réutilisent les mêmes combinaisons souvent avant d'investir dans une entité `SetupCombination` nommée + CRUD. Le bouton « Sauvegarder cette combinaison » est l'évolution naturelle.
- **Plafonds anti-abus** : 10 combinaisons / requête, 20 setups / combinaison. Largement au-delà de l'usage réaliste (2-4 combos × 2-4 setups).
- **Charge SQL** : `N + 1` requêtes par analyse (N combos + 1 baseline). Pas de cache : chaque tweak refait les `N + 1` requêtes après le debounce. Le coût est borné par les plafonds (max ~11 requêtes par fetch) et par le debounce 300ms côté frontend (qui condense les saisies rapides). À mémoiser dans le store si l'usage devient intensif.
- **Charts horizontaux empilés** plutôt qu'un seul chart dual-axis : choix volontaire pour respecter les échelles incompatibles (winrate %, R:R ratio, P&L €) et permettre à l'œil de suivre la couleur d'une combinaison à travers les 3 métriques.
- **Pas de signal visuel pour les échantillons trop petits**. C'était présent en v0 (warning `< 3 trades`) puis retiré : le nb de trades visible directement dans le label de l'axe Y suffit à l'utilisateur pour calibrer sa confiance. Si on observe que des utilisateurs tirent des conclusions hâtives sur 2-3 trades, on pourra réintroduire un signal plus subtil (ex. opacité réduite de la barre).
