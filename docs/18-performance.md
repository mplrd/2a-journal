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

Statistiques groupées par session de trading. La session est dérivée de l'heure de clôture (`closed_at`) en UTC :
- **ASIA** : 0h–6h UTC (Tokyo 8h–14h JST)
- **EUROPE** : 7h–13h UTC (Paris 8h–14h CET)
- **US** : 14h–21h UTC (New York 9h30–16h EST)
- **OFF** : 22h–23h UTC (hors session)

Enum backend : `TradingSession` (ASIA, EUROPE, US, OFF). La heatmap affiche des bandes colorées au-dessus des heures pour visualiser les plages de session.

### GET /stats/by-account

Statistiques groupées par compte. Retourne en plus `account_id`, `account_name` et `account_type`.

### GET /stats/by-account-type

Statistiques groupées par type de compte (`BROKER_DEMO`, `BROKER_LIVE`, `PROP_FIRM`).

### GET /stats/rr-distribution

Distribution des trades par buckets de R:R : `[{bucket, count}]`. Buckets : <-2, -2/-1, -1/0, 0/1, 1/2, 2/3, >3.

### GET /stats/heatmap

Performances par jour de semaine × heure : `[{day, hour, trade_count, total_pnl, avg_pnl}]`. `day` = 0 (dimanche) à 6 (samedi), `hour` = 0 à 23.

### GET /stats/charts

Réutilisé depuis le dashboard : P&L cumulé, distribution W/L, P&L par symbole.

## Architecture backend

| Couche | Fichier | Rôle |
|--------|---------|------|
| Enum | `TradingSession.php` | ASIA, EUROPE, US |
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

**Heatmap** (pleine largeur) : grille CSS jour de semaine × heure, couleur verte (P&L positif) ou rouge (P&L négatif), intensité proportionnelle au nombre de trades.

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
| Integration | `StatsRepositoryTest.php` | getStatsBySession (3), getStatsByAccount (2), getStatsByAccountType (3) |
| Unit | `StatsServiceTest.php` | getStatsBySession (2), getStatsByAccount (1), getStatsByAccountType (1) |
| Integration | `StatsFlowTest.php` | 6 tests HTTP dimension endpoints |
| Frontend | `DashboardFilters.spec.js` | 4 tests |

## Clés i18n

Namespace `performance.*` : 42 clés (en.json et fr.json).
Ajouts : `perf_by_symbol`, `perf_by_setup`, `perf_by_session`, `perf_by_account_type`, `by_session`, `by_account_type`, `sessions.ASIA`, `sessions.EUROPE`, `sessions.US`.
Clé `nav.performance` existante.
