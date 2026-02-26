# 15 - Dashboard

## Objectif

Fournir une vue d'ensemble du journal de trading avec les KPIs clés, des graphiques de performance et les trades récents.

## Endpoints API

### GET /stats/overview

Retourne les KPIs et les trades récents.

**Query params** : `account_id` (optionnel, filtre par compte)

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
    "recent_trades": [
      {
        "id": 1,
        "symbol": "NASDAQ",
        "direction": "BUY",
        "pnl": 100.00,
        "exit_type": "TP",
        "closed_at": "2026-01-15 11:00:00",
        "risk_reward": 2.00
      }
    ]
  }
}
```

### GET /stats/charts

Retourne les données pour les graphiques.

**Query params** : `account_id` (optionnel, filtre par compte)

**Réponse** :
```json
{
  "success": true,
  "data": {
    "cumulative_pnl": [
      {
        "closed_at": "2026-01-10 10:00:00",
        "pnl": 100.00,
        "cumulative_pnl": 100.00,
        "symbol": "NASDAQ"
      }
    ],
    "win_loss": {
      "win": 5,
      "loss": 2,
      "be": 1
    },
    "pnl_by_symbol": [
      {
        "symbol": "NASDAQ",
        "trade_count": 3,
        "total_pnl": 150.00
      }
    ]
  }
}
```

## Architecture backend

| Couche | Fichier | Rôle |
|--------|---------|------|
| Repository | `StatsRepository.php` | Requêtes SQL d'agrégation (COUNT, SUM, AVG) |
| Service | `StatsService.php` | Validation des filtres, ownership du compte, assemblage |
| Controller | `StatsController.php` | 2 actions thin : `dashboard()` et `charts()` |

### Calculs

- **Win Rate** : `winning_trades / total_trades * 100`
- **Profit Factor** : `sum(pnl positifs) / sum(abs(pnl négatifs))` (null si pas de pertes)
- **Avg R:R** : `AVG(risk_reward)` sur tous les trades CLOSED
- **P&L cumulé** : somme progressive calculée en PHP après tri chronologique

### Filtres

- Seuls les trades au statut `CLOSED` sont comptabilisés
- Filtre optionnel par `account_id` avec validation d'ownership

## Architecture frontend

### Composants

| Composant | Rôle |
|-----------|------|
| `KpiCards.vue` | Grille de 6 cartes avec les métriques clés |
| `CumulativePnlChart.vue` | Graphique en courbe (Chart.js via PrimeVue) |
| `WinLossChart.vue` | Graphique doughnut gains/pertes/BE |
| `RecentTrades.vue` | DataTable compact des 5 derniers trades fermés |
| `DashboardView.vue` | Vue principale assemblant tous les composants |

### Store Pinia (`stats.js`)

- `fetchDashboard()` : charge overview + trades récents
- `fetchCharts()` : charge les données graphiques (appel séparé pour affichage progressif)
- `setFilters()` / `$reset()` : gestion des filtres et reset

### Chargement progressif

1. `fetchDashboard()` (await) — affiche les KPIs et trades récents immédiatement
2. `fetchCharts()` (sans await) — charge les graphiques en arrière-plan

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Integration | `StatsRepositoryTest.php` | 9 tests (counts, PnL, filtres, empty state, recent) |
| Unit | `StatsServiceTest.php` | 6 tests (combined data, ownership, filtres, charts, null safety) |
| Integration | `StatsFlowTest.php` | 5 tests (structure 200, auth 401, filtre compte, charts, empty) |
| Unit | `stats-store.spec.js` | 6 tests (initial state, fetch, error, charts, filters, reset) |

## Clés i18n

Namespace `dashboard.*` : 20 clés ajoutées dans `en.json` et `fr.json`.
