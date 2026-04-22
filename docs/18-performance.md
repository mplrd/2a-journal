# 18 - Page Performance

## Objectif

Page dédiée d'analyse détaillée des performances par dimensions (symbole, direction, setup, période, session, type de compte) avec graphiques et DataTables. Filtres avancés (période, direction, symboles, setups, compte).

## Endpoints API

Tous les endpoints supportent les filtres avancés : `account_id`, `date_from`, `date_to`, `direction`, `symbols`, `setups`.

### GET /stats/by-symbol

Statistiques groupées par symbole : total_trades, wins, losses, win_rate, total_pnl, avg_rr, profit_factor.

### GET /stats/by-direction

Statistiques groupées par direction (BUY/SELL). Même structure.

### GET /stats/by-setup

Statistiques groupées par setup. Utilise `JSON_TABLE` pour dénormaliser le champ JSON.

### GET /stats/by-period

Statistiques groupées par période. Query param `group` = day | week | month (défaut) | year.

### GET /stats/by-session

Statistiques groupées par session de trading. La classification utilise les **vraies heures de marché dans leur fuseau horaire local**, avec gestion automatique de l'heure d'été (DST) :

- **ASIA** : Tokyo 9h00–15h00 (`Asia/Tokyo`, pas de DST)
- **EUROPE** : Paris 8h00–16h30 (`Europe/Paris`, DST CET↔CEST)
- **EUROPE_US** : chevauchement Europe + US (ouverture US → fermeture Europe)
- **US** : New York 9h30–16h00 (`America/New_York`, DST EST↔EDT)
- **OFF** : heures hors des 3 sessions

**Chevauchement Europe/US** : quand les deux marchés sont ouverts simultanément, le trade est classifié `EUROPE_US`. Cette période est la plus volatile et mérite une analyse distincte. Sur la heatmap, la zone de chevauchement est affichée avec un dégradé bleu↔rouge et une bordure pointillée violette.

**Architecture** : la classification est faite en PHP (pas en SQL) car les `CASE WHEN HOUR()` SQL ne gèrent pas le DST. Le repository retourne les trades bruts (`closed_at`, `pnl`, `risk_reward`), le service classifie chaque trade via `TradingSession::classify(DateTime)` puis agrège les statistiques en PHP.

Enum backend : `TradingSession` (ASIA, EUROPE, EUROPE_US, US, OFF) avec méthodes `classify()` et `getSessionDefinitions()`.

### GET /stats/by-account

Statistiques groupées par compte. Retourne en plus `account_id`, `account_name` et `account_type`.

### GET /stats/by-account-type

Statistiques groupées par type de compte (`BROKER_DEMO`, `BROKER_LIVE`, `PROP_FIRM`).

### GET /stats/rr-distribution

Distribution des trades par buckets de R:R : `[{bucket, count}]`. Buckets : <-2, -2/-1, -1/0, 0/1, 1/2, 2/3, >3.

### GET /stats/heatmap

Performances par jour de semaine × heure : `[{day, hour, trade_count, total_pnl, avg_pnl}]`. `day` = 0 (dimanche) à 6 (samedi), `hour` = 0 à 23. Les heures sont converties dans le fuseau horaire du user (via `CONVERT_TZ` et le timezone du profil).

### GET /stats/charts

Réutilisé depuis le dashboard : P&L cumulé, distribution W/L, P&L par symbole.

## Architecture backend

| Couche | Fichier | Rôle |
|--------|---------|------|
| Enum | `TradingSession.php` | ASIA, EUROPE, US, OFF |
| Repository | `StatsRepository.php` | `buildWhereClause()` partagée, `dimensionStatsSelect()` pour les métriques, 7 méthodes `getStatsBy*()` |
| Service | `StatsService.php` | Validation filtres (dates, direction, arrays), 7 méthodes déléguant au repo |
| Controller | `StatsController.php` | 7 actions : `bySymbol()`, `byDirection()`, `bySetup()`, `byPeriod()`, `bySession()`, `byAccount()`, `byAccountType()` |
| Routes | `routes.php` | 7 routes GET authentifiées `/stats/by-*` |

## Architecture frontend

### Vue : `PerformanceView.vue`

La page affiche uniquement les graphiques. Chaque chart dispose d'un bouton "Voir le détail" qui ouvre une modale avec le DataTable correspondant.

**Graphiques** (10 charts) :
1. P&L cumulé (ligne) — réutilise les données de `/stats/charts`
2. Courbe d'equity (ligne) — capital initial + P&L cumulé, couleur violette
3. Distribution W/L (doughnut) — bouton détail → DataTable par direction
4. Distribution des R:R (barres) — histogramme par buckets (<-2, -2/-1, -1/0, 0/1, 1/2, 2/3, >3)
5. P&L par symbole (barres vert/rouge) — bouton détail → DataTable par symbole
6. Win Rate & R:R par symbole (double axe, barres groupées bleu/violet) — bouton détail → DataTable par symbole
7. Win Rate & R:R par setup (double axe) — bouton détail → DataTable par setup
8. P&L par période (barres vert/rouge) — sélecteur jour/semaine/mois/année + bouton détail → DataTable par période
9. Win Rate & R:R par session (double axe) — bouton détail → DataTable par session
10. Win Rate & R:R par type de compte (double axe) — bouton détail → DataTable par type de compte

**Choix de visualisation** : les dimensions d'efficacité (symbole, setup, session, type de compte) utilisent un chart double axe (win rate % à gauche, R:R moyen à droite) plutôt que du P&L brut. Le P&L reste affiché pour le symbole (utile en absolu) et la période (évolution temporelle). Le composable `useChartOptions` expose `dualAxisChartOptions` pour ces charts.

**Heatmap** (pleine largeur) : grille CSS jour de semaine × heure, couleur verte (P&L positif) ou rouge (P&L négatif), intensité proportionnelle au nombre de trades. Les heures sont converties dans le fuseau du user via `CONVERT_TZ` côté backend. Les bandes de session sont calculées côté frontend à partir des vraies timezones (`Asia/Tokyo`, `Europe/Paris`, `America/New_York`) et converties dans le fuseau du user via `Intl.DateTimeFormat`, avec prise en charge automatique du DST.

**Dialog** (modale PrimeVue) : DataTable dynamique selon la dimension sélectionnée, colonnes adaptées (avg_rr et profit_factor masqués pour la dimension période).

### Filtres

Composant `DashboardFilters.vue` réutilisé : compte, dates, direction, symboles, setups.

### Store

`stats.js` étendu : `bySymbol`, `byDirection`, `bySetup`, `byPeriod`, `bySession`, `byAccountType`, `dimensionsLoading`, `fetchBy*()`.

### Route

`/performance` (name: `performance`), lien "Performance" dans la sidebar avec icône `pi-chart-bar`.

## Seeder demo

Script `api/database/seed-demo.php` crée un compte demo pré-rempli :
- **Login** : demo@2a.journal / Demo*123
- 3 comptes : FTMO Challenge (PROP_FIRM, rentable), Compte perso (BROKER_LIVE), MFF Évaluation (PROP_FIRM, dans le rouge)
- 5 symboles (NASDAQ, DAX, EURUSD, GBPUSD, BTCUSD)
- 5 setups (Breakout, Pullback, Range, Trend Follow, Reversal)
- 48 trades fermés sur 3 mois (wins, losses, BE, directions/sessions/comptes variés) + 3 trades ouverts
- Idempotent : relancer le script recrée les données proprement

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Unit | `TradingSessionTest.php` | classify() : 25 tests (Asia/Europe/US/OFF, été/hiver, DST transition, overlaps) |
| Integration | `StatsRepositoryTest.php` | getTradesForSessionStats (3), getStatsByAccount (2), getStatsByAccountType (3) |
| Unit | `StatsServiceTest.php` | getStatsBySession (3), getStatsByAccount (1), getStatsByAccountType (1) |
| Integration | `StatsFlowTest.php` | 6 tests HTTP dimension endpoints |
| Frontend | `DashboardFilters.spec.js` | 4 tests |

## Clés i18n

Namespace `performance.*` : 42 clés (en.json et fr.json).
Ajouts : `perf_by_symbol`, `perf_by_setup`, `perf_by_session`, `perf_by_account_type`, `by_session`, `by_account_type`, `sessions.ASIA`, `sessions.EUROPE`, `sessions.EUROPE_US`, `sessions.US`, `sessions.OFF`.
Clé `nav.performance` existante.

## Classification Win / Loss / BE (seuil configurable)

Depuis la mise en place du **seuil BE** (préférence utilisateur `be_threshold_percent`, en % du prix d'entrée — cf. [25-stats-be-threshold.md](25-stats-be-threshold.md)), toutes les statistiques qui classent un trade en **win / loss / break-even** s'appuient sur la colonne `trades.pnl_percent` et non plus directement sur `trades.pnl`.

Soit `tol = users.be_threshold_percent` :

- **BE** : `-tol ≤ pnl_percent ≤ tol` (inclusif)
- **Win** : `pnl_percent > tol`
- **Loss** : `pnl_percent < -tol`

Quand `tol = 0` (valeur par défaut), la classification est rigoureusement identique à l'ancienne (sign de `pnl`). Plus `tol` est grand, plus les trades proches de zéro basculent en BE au lieu d'être comptés comme micro-wins ou micro-losses — ce qui assainit les stats quand le spread broker ou un import auto génère des sorties "à peine" gagnantes/perdantes.

### Propagation

- `StatsService::validateFilters()` lit `be_threshold_percent` depuis le profil utilisateur (via `UserRepository`) et l'injecte dans les filtres.
- `StatsRepository::buildWhereClause()` l'expose au repo, qui l'ajoute via `withBeThreshold()` uniquement aux requêtes qui en ont besoin (paramètres nommés PDO, émulation off).
- Méthodes concernées : `getOverview`, `getWinLossDistribution`, et via `dimensionStatsSelect()` : `getStatsBySymbol`, `getStatsByDirection`, `getStatsBySetup`, `getStatsByPeriod`, `getStatsByAccount`, `getStatsByAccountType`.
- Méthodes **non concernées** (pas de classification binaire) : `getCumulativePnl`, `getPnlBySymbol`, `getRrDistribution`, `getHeatmap`, `getDailyPnl`, `getRecentTrades`, `getOpenTrades`, `getTradesForSessionStats`.

La donnée brute (`pnl`, `exit_type`) n'est jamais réécrite : le seuil agit uniquement à la lecture, et modifier la préférence met à jour les stats à la requête suivante sans recalcul en base.
