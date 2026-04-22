# 15 - Dashboard

## Objectif

Fournir une vue d'ensemble simple du journal de trading avec les KPIs clés, des graphiques de performance, les trades (en cours et récents) et un calendrier de P&L journalier. Filtre par compte uniquement.

## Endpoints API

### GET /stats/overview

Retourne les KPIs et les trades récents (5 derniers trades fermés).

**Query params** : `account_id` (optionnel, filtre par compte)

Les filtres avancés (date_from, date_to, direction, symbols, setups) sont également supportés mais utilisés uniquement depuis la page Performance.

**Réponse** :
```json
{
  "success": true,
  "data": {
    "overview": {
      "total_trades": 10,
      "total_pnl": 500.00,
      "winning_trades": 7,
      "losing_trades": 2,
      "be_trades": 1,
      "win_rate": 70.00,
      "profit_factor": 3.50,
      "best_trade": 200.00,
      "worst_trade": -80.00,
      "avg_rr": 1.50
    },
    "recent_trades": [...]
  }
}
```

### GET /stats/charts

Retourne les données pour les graphiques (P&L cumulé, distribution W/L, P&L par symbole).

**Query params** : mêmes filtres que `/stats/overview`

### GET /stats/open-trades

Retourne les 5 trades actuellement ouverts (status = OPEN), ordonnés par date d'ouverture décroissante.

**Query params** : `account_id` (optionnel)

**Réponse** : `[{id, opened_at, remaining_size, symbol, direction, entry_price, size, account_name}]`

### GET /stats/daily-pnl

Retourne le P&L agrégé par jour (trades fermés uniquement).

**Query params** : mêmes filtres que `/stats/overview`

**Réponse** : `[{date, trade_count, total_pnl}]`

## Architecture frontend

### Composants

| Composant | Rôle |
|-----------|------|
| `KpiCards.vue` | Grille de 6 cartes avec les métriques clés |
| `CumulativePnlChart.vue` | Graphique en courbe (Chart.js via PrimeVue) |
| `WinLossChart.vue` | Graphique doughnut gains/pertes/BE |
| `PnlBySymbolChart.vue` | Graphique en barres P&L par symbole (vert/rouge) |
| `RecentTrades.vue` | Tabs "En cours" / "Récents" — trades ouverts et 5 derniers trades fermés |
| `PnlCalendar.vue` | Calendrier mensuel avec P&L journalier, navigation mois, couleurs vert/rouge/jaune |
| `DashboardView.vue` | Vue principale — filtre par compte (Select inline), pas de filtres avancés |

### Layout

- Header avec titre + Select de compte
- KPIs en grille 6 colonnes
- 3 charts en grille responsive (lg:3 cols, mobile stack)
- Dernière ligne en grille lg:3 cols :
  - Trades avec tabs (2/3 — `lg:col-span-2`) : onglet "En cours" (trades ouverts) et "Récents" (trades fermés)
  - Calendrier P&L journalier (1/3) : grille mensuelle, jours colorés selon la perf (vert = gain, rouge = perte, jaune = BE), navigation mois précédent/suivant

### Comportement au chargement

- **Reset des filtres** : les filtres du store sont remis à zéro (`setFilters({})`) au montage de la page. Ainsi, naviguer vers une autre page puis revenir affiche toujours les données non filtrées.
- **Overlay de chargement** : pendant le fetch, un overlay semi-transparent (`bg-white/60` / `bg-gray-900/60`) s'affiche par-dessus le contenu existant avec un spinner centré, plutôt que de masquer le contenu. Cela évite le flicker (flash des anciennes données → spinner → nouvelles données).

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Integration | `StatsRepositoryTest.php` | getOpenTrades (3), getDailyPnl (3) |
| Unit | `StatsServiceTest.php` | 13 tests |
| Integration | `StatsFlowTest.php` | 15 tests |
| Frontend | `PnlBySymbolChart.spec.js` | 3 tests |
| Unit | `stats-store.spec.js` | 6 tests |
