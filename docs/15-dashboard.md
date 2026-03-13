# 15 - Dashboard

## Objectif

Fournir une vue d'ensemble simple du journal de trading avec les KPIs clés, des graphiques de performance et les trades récents. Filtre par compte uniquement.

## Endpoints API

### GET /stats/overview

Retourne les KPIs et les trades récents.

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

## Architecture frontend

### Composants

| Composant | Rôle |
|-----------|------|
| `KpiCards.vue` | Grille de 6 cartes avec les métriques clés |
| `CumulativePnlChart.vue` | Graphique en courbe (Chart.js via PrimeVue) |
| `WinLossChart.vue` | Graphique doughnut gains/pertes/BE |
| `PnlBySymbolChart.vue` | Graphique en barres P&L par symbole (vert/rouge) |
| `RecentTrades.vue` | DataTable compact des 5 derniers trades fermés |
| `DashboardView.vue` | Vue principale — filtre par compte (Select inline), pas de filtres avancés |

### Layout

- Header avec titre + Select de compte
- KPIs en grille 6 colonnes
- 3 charts en grille responsive (lg:3 cols, mobile stack)
- DataTable des trades récents

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Integration | `StatsRepositoryTest.php` | 16 tests |
| Unit | `StatsServiceTest.php` | 13 tests |
| Integration | `StatsFlowTest.php` | 15 tests |
| Frontend | `PnlBySymbolChart.spec.js` | 3 tests |
| Unit | `stats-store.spec.js` | 6 tests |
