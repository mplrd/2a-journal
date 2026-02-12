# Étape 9 — Trades

## Résumé

Module complet de gestion des trades (pièce centrale du journal de trading). Un trade est une position de type `TRADE` qui étend la table `positions` via la table `trades` avec des champs spécifiques : sorties partielles, PnL, durée, statuts OPEN/SECURED/CLOSED.

## Architecture

### Backend

#### Repositories
- **TradeRepository** (`api/src/Repositories/TradeRepository.php`)
  - `create()` — INSERT avec JOIN position pour le retour
  - `findById()` — JOIN positions, tous les champs trade + position
  - `findAllByUserId()` — filtres (account_id, status, symbol, direction), pagination
  - `update()` — champs trade uniquement (remaining_size, status, pnl, etc.)
  - `delete()` — suppression du trade

- **PartialExitRepository** (`api/src/Repositories/PartialExitRepository.php`)
  - `create()` — INSERT partial_exit avec PnL calculé
  - `findByTradeId()` — toutes les sorties, ordonnées par date

#### Service
- **TradeService** (`api/src/Services/TradeService.php`)
  - `create()` — validation, calcul prix dérivés, création position + trade, log historique
  - `list()` — filtres + pagination
  - `get()` — ownership check, inclut partial_exits
  - `close()` — sortie partielle ou totale, calcul PnL, transitions de statut
  - `delete()` — suppression via position (CASCADE)

#### Controller
- **TradeController** (`api/src/Controllers/TradeController.php`) — thin controller, 5 actions

#### Routes
| Méthode | URI | Action | Middleware |
|---------|-----|--------|------------|
| GET | `/trades` | index | AuthMiddleware |
| POST | `/trades` | store | AuthMiddleware |
| GET | `/trades/{id}` | show | AuthMiddleware |
| POST | `/trades/{id}/close` | close | AuthMiddleware |
| DELETE | `/trades/{id}` | destroy | AuthMiddleware |

### Frontend

#### Service & Store
- **tradesService** (`frontend/src/services/trades.js`) — list, get, create, close, remove
- **useTradesStore** (`frontend/src/stores/trades.js`) — state Pinia avec trades, loading, error, filters

#### Composants
- **TradesView** (`frontend/src/views/TradesView.vue`) — DataTable avec filtres, création, fermeture, suppression
- **TradeForm** (`frontend/src/components/trade/TradeForm.vue`) — Dialog création (similaire OrderForm + opened_at)
- **CloseTradeDialog** (`frontend/src/components/trade/CloseTradeDialog.vue`) — Dialog fermeture avec aperçu PnL

#### Enums
- `TradeStatus` (OPEN, SECURED, CLOSED) ajouté dans `constants/enums.js`
- `ExitType` (BE, TP, SL, MANUAL) ajouté dans `constants/enums.js`

## Calculs PnL

```
direction_multiplier = BUY → +1, SELL → -1
partial_pnl = (exit_price - entry_price) * exit_size * direction_multiplier
avg_exit_price = SUM(exit_price * size) / SUM(size)
total_pnl = SUM(partial_exits.pnl)
risk_reward = total_pnl / (entry_size * sl_points)
pnl_percent = (total_pnl / (entry_price * entry_size)) * 100
duration_minutes = (closed_at - opened_at) en minutes
```

**Note** : les calculs sont en points bruts. L'intégration de `point_value` (table symbols) est prévue pour une évolution ultérieure (endpoint GET /symbols).

## Cycle de vie d'un trade

```
OPEN → (première sortie partielle) → SECURED → (dernière sortie) → CLOSED
OPEN → (sortie totale directe) → CLOSED
```

- **OPEN** : trade actif, remaining_size = size initial
- **SECURED** : au moins une sortie partielle effectuée
- **CLOSED** : remaining_size = 0, toutes les métriques finales calculées

## Transitions de statut

| De | Vers | Déclencheur |
|----|------|-------------|
| — | OPEN | Création du trade |
| OPEN | SECURED | Première sortie partielle |
| OPEN | CLOSED | Sortie totale directe |
| SECURED | CLOSED | Dernière sortie (remaining = 0) |

Chaque transition est enregistrée dans `status_history`.

## i18n

Bloc `trades` ajouté dans `fr.json` et `en.json` avec :
- Labels de base (title, create, empty, opened_at, etc.)
- Statuts (OPEN, SECURED, CLOSED)
- Types de sortie (BE, TP, SL, MANUAL)
- Messages d'erreur (not_found, forbidden, already_closed, invalid_exit_size, etc.)
- Messages de succès (created, closed, deleted)

## Tests

### Backend (356 tests, 879 assertions)
- **TradeRepositoryTest** (6 tests) — CRUD + filtres
- **PartialExitRepositoryTest** (3 tests) — create + findByTradeId
- **TradeServiceTest** (30 tests) — create, list, get, close, delete avec validations
- **TradeFlowTest** (15 tests) — lifecycle complet, PnL, status transitions

### Frontend (61 tests)
- **trades-store.spec.js** (10 tests) — fetchTrades, createTrade, closeTrade, deleteTrade, setFilters, loading, errors

## Scope exclu (évolutions ultérieures)

- Lien `order.execute()` → création auto d'un trade
- Calculs avec `point_value` du symbole (nécessite endpoint GET /symbols)
- Mise à jour `current_capital` du compte à la clôture
