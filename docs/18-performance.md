# 18 - Page Performance

## Objectif

Page dédiée d'analyse détaillée des performances par dimensions (symbole, direction, setup, période, session, compte, type de compte) avec graphiques et DataTables. Filtres avancés (période, direction, symboles, setups, compte).

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

Statistiques groupées par session de trading. La session est dérivée de l'heure de clôture (`closed_at`) :
- **ASIA** : 0h–8h UTC
- **EUROPE** : 8h–14h UTC
- **US** : 14h–22h UTC
- 22h–0h → ASIA (par défaut)

Enum backend : `TradingSession` (ASIA, EUROPE, US).

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

**Graphiques** (11 charts) :
1. P&L cumulé (ligne) — réutilise les données de `/stats/charts`
2. Courbe d'equity (ligne) — capital initial + P&L cumulé, couleur violette
3. Distribution W/L (doughnut) — bouton détail → DataTable par direction
4. Distribution des R:R (barres) — histogramme par buckets (<-2, -2/-1, -1/0, 0/1, 1/2, 2/3, >3)
5. P&L par symbole (barres vert/rouge) — bouton détail → DataTable par symbole
6. Taux de réussite par symbole (barres bleues) — bouton détail → DataTable par symbole
7. P&L par setup (barres vert/rouge) — bouton détail → DataTable par setup
8. P&L par période (barres vert/rouge) — sélecteur jour/semaine/mois/année + bouton détail → DataTable par période
9. P&L par session (barres vert/rouge) — bouton détail → DataTable par session
10. P&L par type de compte (barres vert/rouge) — bouton détail → DataTable par type de compte
11. P&L par compte (barres vert/rouge) — bouton détail → DataTable par compte

**Heatmap** (pleine largeur) : grille CSS jour de semaine × heure, couleur verte (P&L positif) ou rouge (P&L négatif), intensité proportionnelle au nombre de trades.

**Dialog** (modale PrimeVue) : DataTable dynamique selon la dimension sélectionnée, colonnes adaptées (avg_rr et profit_factor masqués pour la dimension période).

### Filtres

Composant `DashboardFilters.vue` réutilisé : compte, dates, direction, symboles, setups.

### Store

`stats.js` étendu : `bySymbol`, `byDirection`, `bySetup`, `byPeriod`, `bySession`, `byAccount`, `byAccountType`, `dimensionsLoading`, `fetchBy*()`.

### Route

`/performance` (name: `performance`), lien "Performance" dans la sidebar avec icône `pi-chart-bar`.

## Seeder demo

Script `api/database/seed-demo.php` crée un compte demo pré-rempli :
- **Login** : demo@2a.journal / Demo*123
- 2 comptes : FTMO Challenge (PROP_FIRM) + Compte perso (BROKER_LIVE)
- 5 symboles (NASDAQ, DAX, EURUSD, GBPUSD, BTCUSD)
- 5 setups (Breakout, Pullback, Range, Trend Follow, Reversal)
- 30 trades variés sur 2 mois (wins, losses, BE, directions mixtes, sessions mixtes, répartis sur les 2 comptes)
- Idempotent : relancer le script recrée les données proprement

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Integration | `StatsRepositoryTest.php` | getStatsBySession (3), getStatsByAccount (2), getStatsByAccountType (3) |
| Unit | `StatsServiceTest.php` | getStatsBySession (2), getStatsByAccount (1), getStatsByAccountType (1) |
| Integration | `StatsFlowTest.php` | 6 tests HTTP dimension endpoints |
| Frontend | `DashboardFilters.spec.js` | 4 tests |

## Clés i18n

Namespace `performance.*` : 40 clés (en.json et fr.json).
Ajouts : `pnl_by_session`, `pnl_by_account`, `pnl_by_account_type`, `by_session`, `by_account`, `by_account_type`, `sessions.ASIA`, `sessions.EUROPE`, `sessions.US`.
Clé `nav.performance` existante.
