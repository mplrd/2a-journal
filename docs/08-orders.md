# Étape 8 — Module Ordres

## Résumé

Implémentation complète du module **Ordres** (backend + frontend). Les ordres sont des positions en attente qui étendent la table `positions` via une relation 1:1. Le cycle de vie d'un ordre suit le schéma : `PENDING → EXECUTED | CANCELLED | EXPIRED`.

## Architecture

### Relation Position ↔ Ordre

- Créer un ordre = créer une `position` (`position_type = 'ORDER'`) + un enregistrement dans `orders`
- La table `orders` contient : `position_id` (FK unique), `expires_at`, `status`
- La suppression d'un ordre supprime aussi la position (CASCADE)
- Les lectures (findById, findAll) utilisent un JOIN pour retourner les données position + ordre en une seule requête

### Cycle de vie

```
PENDING → EXECUTED   (exécution manuelle, création trade prévue en étape suivante)
PENDING → CANCELLED  (annulation manuelle)
PENDING → EXPIRED    (expiration automatique, prévu ultérieurement)
```

Chaque transition de statut est enregistrée dans `status_history` (entity_type = 'ORDER').

## Backend

### Fichiers créés

| Fichier | Rôle |
|---------|------|
| `api/src/Repositories/OrderRepository.php` | CRUD orders avec JOIN positions |
| `api/src/Services/OrderService.php` | Logique métier (create, list, get, cancel, execute, delete) |
| `api/src/Controllers/OrderController.php` | Controller thin (6 actions) |
| `api/tests/Integration/Repositories/OrderRepositoryTest.php` | 6 tests repository |
| `api/tests/Unit/Services/OrderServiceTest.php` | 29 tests unitaires service |
| `api/tests/Integration/Orders/OrderFlowTest.php` | 15 tests intégration |

### Fichiers modifiés

| Fichier | Modification |
|---------|-------------|
| `api/src/Repositories/PositionRepository.php` | Ajout méthode `create()` |
| `api/tests/Integration/Repositories/PositionRepositoryTest.php` | +1 test pour create |
| `api/config/routes.php` | 6 nouvelles routes + imports |

### Routes API

| Méthode | URI | Action | Auth |
|---------|-----|--------|------|
| `GET` | `/orders` | Liste des ordres (filtres: account_id, status, symbol, direction) | Oui |
| `POST` | `/orders` | Créer un ordre | Oui |
| `GET` | `/orders/{id}` | Détail d'un ordre | Oui |
| `DELETE` | `/orders/{id}` | Supprimer un ordre | Oui |
| `POST` | `/orders/{id}/cancel` | Annuler un ordre (PENDING → CANCELLED) | Oui |
| `POST` | `/orders/{id}/execute` | Exécuter un ordre (PENDING → EXECUTED) | Oui |

### Validation création

| Champ | Règle |
|-------|-------|
| `account_id` | Requis, > 0, appartient au user |
| `direction` | Requis, enum Direction (BUY/SELL) |
| `symbol` | Requis, non vide, max 50 |
| `entry_price` | Requis, > 0 |
| `size` | Requis, > 0 |
| `setup` | Requis, non vide, max 255 |
| `sl_points` | Requis, > 0 |
| `be_points` | Optionnel, > 0 |
| `be_size` | Optionnel, > 0 |
| `targets` | Optionnel, JSON valide |
| `notes` | Optionnel, max 10000 |
| `expires_at` | Optionnel, datetime dans le futur |

### Calcul automatique des prix

- `sl_price` : BUY → entry - sl_points, SELL → entry + sl_points
- `be_price` : BUY → entry + be_points, SELL → entry - be_points
- `target.price` : BUY → entry + points, SELL → entry - points

### OrderRepository

- `create(array $data)` : INSERT dans orders, retourne findById
- `findById(int $id)` : JOIN positions pour données complètes
- `findAllByUserId(int $userId, array $filters)` : JOIN positions, filtres account_id/status/symbol/direction
- `updateStatus(int $id, string $newStatus)` : UPDATE status
- `delete(int $id)` : DELETE order

### OrderService

- `create(int $userId, array $data)` : Validation complète → position → order → status_history
- `list(int $userId, array $filters)` : Filtrage avec validation des enums
- `get(int $userId, int $orderId)` : Ownership check via JOIN user_id
- `cancel(int $userId, int $orderId)` : Vérifie PENDING, met à jour, log historique
- `execute(int $userId, int $orderId)` : Vérifie PENDING, met à jour, log historique
- `delete(int $userId, int $orderId)` : Ownership check, supprime position (CASCADE)

## Frontend

### Fichiers créés

| Fichier | Rôle |
|---------|------|
| `frontend/src/services/orders.js` | Client API (list, create, get, remove, cancel, execute) |
| `frontend/src/stores/orders.js` | Store Pinia (state, actions, filters) |
| `frontend/src/views/OrdersView.vue` | Vue liste + filtres + création |
| `frontend/src/components/order/OrderForm.vue` | Dialog de création d'ordre |
| `frontend/src/__tests__/orders-store.spec.js` | 10 tests store |

### Fichiers modifiés

| Fichier | Modification |
|---------|-------------|
| `frontend/src/constants/enums.js` | Ajout `OrderStatus` |
| `frontend/src/router/index.js` | Route `/orders` |
| `frontend/src/components/layout/AppLayout.vue` | Lien nav "Ordres" |
| `frontend/src/locales/fr.json` | Clés i18n bloc `orders` |
| `frontend/src/locales/en.json` | Clés i18n bloc `orders` |

### Pinia Store

- **State** : `orders`, `loading`, `error`, `filters`
- **Actions** : `fetchOrders()`, `createOrder()`, `cancelOrder()`, `executeOrder()`, `deleteOrder()`, `setFilters()`

### Vue OrdersView

- DataTable PrimeVue : symbol, direction (Tag), entry_price, size, setup, sl_price, status (Tag coloré), expires_at, created_at
- Bouton "Nouvel ordre" → ouvre OrderForm
- Filtres : compte, statut
- Actions par ligne : exécuter (si PENDING), annuler (si PENDING), supprimer

### Composant OrderForm

- Dialog PrimeVue pour création uniquement
- Champs : account (Select), symbol, direction, entry_price, size, setup, sl_points, be_points, be_size, targets, expires_at (DatePicker), notes
- Calcul en temps réel : sl_price, be_price, target prices
- Reset du formulaire à chaque ouverture

## Tests

### Backend : 51 nouveaux tests

| Fichier | Tests | Assertions |
|---------|-------|------------|
| PositionRepositoryTest (create) | 1 | 7 |
| OrderRepositoryTest | 6 | 20 |
| OrderServiceTest | 29 | 53 |
| OrderFlowTest | 15 | 41 |

### Frontend : 10 nouveaux tests

| Fichier | Tests |
|---------|-------|
| orders-store.spec.js | 10 |

### Total projet

- **Backend** : 291 tests, 681 assertions (all green)
- **Frontend** : 47 tests (all green)

## Clés i18n ajoutées

Bloc `orders` dans fr.json et en.json :
- `title`, `create`, `empty`, `account`, `select_account`
- `status`, `expires_at`, `execute`, `cancel_action`
- `filter_account`, `filter_status`, `all_accounts`, `all_statuses`
- `confirm_cancel`, `confirm_execute`, `confirm_delete`
- `statuses` : PENDING, EXECUTED, CANCELLED, EXPIRED
- `error` : not_found, forbidden, account_forbidden, field_required, invalid_direction, invalid_symbol, invalid_price, invalid_size, invalid_setup, invalid_sl_points, invalid_be_points, invalid_be_size, notes_too_long, invalid_targets, invalid_target_points, invalid_target_size, invalid_expires_at, not_pending
- `success` : created, cancelled, executed, deleted

## Prochaine étape

**Étape 8 : Trades** — Gestion des trades actifs/clôturés, sorties partielles, calcul P&L.
