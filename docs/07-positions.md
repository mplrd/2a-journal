# Étape 7 — Positions (Backend + Frontend)

## Résumé

Implémentation du module **Positions** — entité de base partagée par les ordres et les trades. Les positions ne sont pas créées directement (pas de `POST /positions`), elles seront créées via `POST /orders` (étape 7) et `POST /trades` (étape 8). Cette étape construit le socle complet : repository, service avec calculs automatiques de prix, controller REST, et le frontend (vue liste, formulaire d'édition, dialog de transfert).

## Architecture

```
positions (base) ──→ orders  (extension, 1:1 via position_id)
                 ──→ trades  (extension, 1:1 via position_id)
```

La table `positions` n'a **pas** de `deleted_at` — la suppression est un `DELETE FROM` réel. Les cascades FK s'occupent des entités liées.

## Backend

### Enum

- **`PositionType`** (`api/src/Enums/PositionType.php`) — `ORDER` / `TRADE`

### Repositories

- **`PositionRepository`** (`api/src/Repositories/PositionRepository.php`)
  - Colonnes explicites (pas de `SELECT *`)
  - `findAllByUserId(userId, filters)` — filtres : `account_id`, `position_type`, `symbol`, `direction`
  - `findById(id)` — retourne `null` si inexistant
  - `update(id, data)` — champs dynamiques autorisés
  - `delete(id)` — suppression réelle (`DELETE FROM`)
  - `transfer(id, newAccountId)` — change le `account_id`

- **`StatusHistoryRepository`** (`api/src/Repositories/StatusHistoryRepository.php`)
  - `create(data)` — insère une entrée d'audit
  - `findByEntity(entityType, entityId)` — ordonné par `changed_at DESC, id DESC`

### Service

- **`PositionService`** (`api/src/Services/PositionService.php`)
  - Injection : `PositionRepository`, `AccountRepository`, `StatusHistoryRepository`
  - **Méthodes publiques** :
    - `list(userId, filters)` — liste avec filtres optionnels
    - `get(userId, positionId)` — ownership check via `user_id`
    - `update(userId, positionId, data)` — validation, recalcul des prix, update
    - `delete(userId, positionId)` — ownership check, suppression réelle
    - `transfer(userId, positionId, data)` — validation compte cible, transfert, log historique
    - `getHistory(userId, positionId)` — ownership check, retourne les entrées `status_history`

### Calculs automatiques de prix

Les prix sont recalculés automatiquement selon la direction lors de chaque `update` :

| Champ | BUY | SELL |
|-------|-----|------|
| `sl_price` | `entry_price - sl_points` | `entry_price + sl_points` |
| `be_price` | `entry_price + be_points` | `entry_price - be_points` |
| `target.price` | `entry_price + target.points` | `entry_price - target.points` |

### Validations (update)

| Champ | Règle |
|-------|-------|
| `entry_price` | > 0 |
| `size` | > 0 |
| `sl_points` | > 0 |
| `be_points` | > 0 (si présent) |
| `be_size` | > 0 (si présent) |
| `direction` | Enum `Direction` valide |
| `symbol` | Non vide, max 50 chars |
| `setup` | Non vide, max 255 chars |
| `notes` | Optionnel, max 10000 chars |
| `targets` | Tableau JSON valide (chaque target : `points` > 0, `size` > 0) |

### Controller

- **`PositionController`** (`api/src/Controllers/PositionController.php`)
  - Thin controller, même pattern que `AccountController`
  - Actions : `index`, `show`, `update`, `destroy`, `transfer`, `history`
  - Supporte les filtres via query params : `account_id`, `position_type`, `symbol`, `direction`

### Routes

```
GET    /positions                  → index  (liste avec filtres)
GET    /positions/{id}             → show
PUT    /positions/{id}             → update
DELETE /positions/{id}             → destroy
POST   /positions/{id}/transfer    → transfer
GET    /positions/{id}/history     → history
```

Toutes les routes requièrent `AuthMiddleware`.

## Frontend

### Service API

- **`positionsService`** (`frontend/src/services/positions.js`)
  - `list(filters)`, `get(id)`, `update(id, data)`, `remove(id)`, `transfer(id, data)`, `getHistory(id)`

### Store Pinia

- **`usePositionsStore`** (`frontend/src/stores/positions.js`)
  - State : `positions`, `loading`, `error`, `filters`
  - Actions : `fetchPositions`, `updatePosition`, `deletePosition`, `transferPosition`, `setFilters`

### Enums frontend

- **`Direction`** : `BUY`, `SELL`
- **`PositionType`** : `ORDER`, `TRADE`

### Vue PositionsView

- DataTable PrimeVue : symbol, direction (Tag coloré), entry_price, size, setup, sl_price, position_type (Tag), created_at
- Filtres : compte (Select), type ORDER/TRADE (Select)
- Actions par ligne : modifier, transférer, supprimer
- Pas de bouton "Créer" (positions créées via orders/trades)

### Composant PositionForm

- Dialog PrimeVue pour édition
- Champs : symbol, direction, entry_price, size, setup, sl_points, be_points, be_size, notes
- Gestion des targets (ajout/suppression dynamique)
- **Calcul en temps réel** des prix (sl_price, be_price, target prices) via `computed`

### Composant TransferDialog

- Select du compte cible (exclut le compte actuel de la position)
- Bouton transférer désactivé tant qu'aucun compte n'est sélectionné

### Navigation

- Route `/positions` ajoutée au router
- Lien "Positions" ajouté dans la barre de navigation (`AppLayout`)

## i18n

Bloc `positions` complet ajouté en `fr.json` et `en.json` :
- Titre, champs, directions (Achat/Vente), types (Ordre/Trade)
- Messages d'erreur (validation, ownership, transfert)
- Messages de succès (modifié, supprimé, transféré)
- Labels des filtres et du dialog de transfert

## Tests

### Backend (60 nouveaux tests)

| Fichier | Tests | Type |
|---------|-------|------|
| `PositionRepositoryTest.php` | 8 | Intégration |
| `StatusHistoryRepositoryTest.php` | 3 | Intégration |
| `PositionServiceTest.php` | 36 | Unitaire |
| `PositionFlowTest.php` | 13 | Intégration |

### Frontend (9 nouveaux tests)

| Fichier | Tests |
|---------|-------|
| `positions-store.spec.js` | 9 |

### Totaux

- **Backend** : 240 tests, 560 assertions — tous verts
- **Frontend** : 37 tests — tous verts
- **Total** : 277 tests

## Fichiers créés (14)

| Fichier | Rôle |
|---------|------|
| `api/src/Enums/PositionType.php` | Enum ORDER/TRADE |
| `api/src/Repositories/PositionRepository.php` | CRUD positions |
| `api/src/Repositories/StatusHistoryRepository.php` | Audit trail |
| `api/src/Services/PositionService.php` | Logique métier + calculs prix |
| `api/src/Controllers/PositionController.php` | Controller thin |
| `api/tests/Integration/Repositories/PositionRepositoryTest.php` | Tests repo |
| `api/tests/Integration/Repositories/StatusHistoryRepositoryTest.php` | Tests audit |
| `api/tests/Unit/Services/PositionServiceTest.php` | Tests unitaires service |
| `api/tests/Integration/Positions/PositionFlowTest.php` | Tests intégration |
| `frontend/src/services/positions.js` | API client |
| `frontend/src/stores/positions.js` | Pinia store |
| `frontend/src/views/PositionsView.vue` | Vue liste |
| `frontend/src/components/position/PositionForm.vue` | Formulaire édition |
| `frontend/src/components/position/TransferDialog.vue` | Dialog transfert |
| `frontend/src/__tests__/positions-store.spec.js` | Tests store |

## Fichiers modifiés (5)

| Fichier | Modification |
|---------|-------------|
| `api/config/routes.php` | 6 nouvelles routes positions + imports |
| `frontend/src/constants/enums.js` | `Direction`, `PositionType` |
| `frontend/src/router/index.js` | Route `/positions` |
| `frontend/src/components/layout/AppLayout.vue` | Lien nav "Positions" |
| `frontend/src/locales/fr.json` + `en.json` | Clés i18n positions |
