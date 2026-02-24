# 14 — Refactoring positions agrégées

## Contexte

La vue Positions affichait jusqu'ici les enregistrements individuels 1:1 avec les orders/trades, avec des actions edit/transfer/delete. Or une **position** est un agrégat : l'exposition globale au marché résultant de trades. Ce refactoring transforme la vue en lecture seule agrégée et déporte les actions vers OrdersView et TradesView.

## Objectifs

- Vue Positions : affichage agrégé par (compte, symbole, direction) avec PRU pondéré et taille totale
- Suppression des actions edit/transfer/delete de PositionsView
- Ajout des actions edit position + transfer sur OrdersView (orders PENDING) et TradesView (trades non CLOSED)
- Nettoyage des clés i18n orphelines et de l'enum `PositionType` frontend

## Backend

### Nouvel endpoint

| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/positions/aggregated` | Positions agrégées de l'utilisateur |

**Query params** : `account_id` (optionnel, filtre par compte)

**Réponse** : tableau d'objets agrégés :
```json
{
  "success": true,
  "data": [
    {
      "account_id": 1,
      "symbol": "NASDAQ",
      "direction": "BUY",
      "total_size": "5.0000",
      "pru": "18800.00000",
      "first_opened_at": "2025-01-15 10:00:00"
    }
  ]
}
```

### Logique d'agrégation

- Jointure `trades` + `positions` sur `position_id`
- Filtre : trades avec statut `OPEN` ou `SECURED` et `remaining_size > 0`
- Groupement : `account_id`, `symbol`, `direction`
- PRU = `SUM(entry_price * remaining_size) / SUM(remaining_size)` (pondéré par taille restante)
- `first_opened_at` = `MIN(opened_at)`
- Tri : par date d'ouverture la plus ancienne en premier (DESC)

### Fichiers modifiés

| Fichier | Modification |
|---------|-------------|
| `api/src/Repositories/PositionRepository.php` | Ajout `findAggregatedByUserId()` |
| `api/src/Services/PositionService.php` | Ajout `listAggregated()` |
| `api/src/Controllers/PositionController.php` | Ajout `aggregated()` |
| `api/config/routes.php` | Ajout route `GET /positions/aggregated` (avant `{id}`) |

### Routes existantes conservées

Toutes les routes REST positions (GET, PUT, DELETE, transfer, history, share) restent en place. Elles sont utilisées par OrdersView et TradesView pour les actions edit/transfer.

## Frontend

### Service positions

Ajout de `listAggregated(filters)` dans `frontend/src/services/positions.js`.

### Store positions

| Méthode | Description |
|---------|-------------|
| `fetchAggregated()` | Charge les positions agrégées (remplace `fetchPositions` dans PositionsView) |
| `fetchPosition(id)` | Charge une position individuelle (pour pré-remplir le formulaire d'édition) |

### PositionsView — lecture seule

Colonnes affichées :
- Compte (`accountName`)
- Symbole (`symbolName`)
- Direction (Tag BUY/SELL)
- Taille totale (`total_size`)
- PRU (`pru`, formaté)
- Ouvert le (`first_opened_at`, formaté en date)

Supprimé :
- Actions edit/transfer/delete
- Filtre par type (ORDER/TRADE)
- Composants `PositionForm` et `TransferDialog`

### OrdersView — actions ajoutées

Pour les orders au statut `PENDING` :
- Bouton edit (ouvre `PositionForm` pré-rempli via `fetchPosition`)
- Bouton transfer (ouvre `TransferDialog`)

### TradesView — actions ajoutées

Pour les trades au statut `OPEN` ou `SECURED` (non CLOSED) :
- Bouton edit (ouvre `PositionForm` pré-rempli via `fetchPosition`)
- Bouton transfer (ouvre `TransferDialog`)

## Nettoyage

### Enum PositionType (frontend)

Supprimé de `frontend/src/constants/enums.js` — plus utilisé côté frontend.

### Clés i18n supprimées

- `positions.filter_type`
- `positions.all_types`
- `positions.types.ORDER`
- `positions.types.TRADE`
- `positions.type`
- `positions.confirm_delete`

### Clés i18n ajoutées

| Clé | FR | EN |
|-----|----|----|
| `positions.total_size` | Taille totale | Total Size |
| `positions.pru` | PRU | Avg. Price |
| `positions.first_opened_at` | Ouvert le | Opened |

## Tests

### Backend (PHPUnit)

| Fichier | Tests |
|---------|-------|
| `api/tests/Integration/Repositories/PositionRepositoryTest.php` | 6 tests ajoutés : empty, single, grouped, closed excluded, account filter, secured included |
| `api/tests/Unit/Services/PositionServiceTest.php` | 3 tests ajoutés : delegation, account filter, empty filter |
| `api/tests/Integration/Positions/PositionAggregatedFlowTest.php` | 5 tests E2E : auth, empty, grouped, account filter, closed excluded |

### Frontend (Vitest)

| Fichier | Tests |
|---------|-------|
| `frontend/src/__tests__/positions-store.spec.js` | 4 tests ajoutés : fetchAggregated, filters, error, fetchPosition. Import PositionType supprimé. |

### Résultats

- PHPUnit : 491 tests, 1258 assertions — tous verts
- Vitest : 95 tests — tous verts
- Build Vite : OK
