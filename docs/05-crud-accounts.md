# Étape 5 — CRUD Accounts (Full Stack)

## Résumé

Premier CRUD complet du projet. Implémente la gestion des comptes de trading (création, lecture, modification, suppression soft). Pose le pattern Controller → Service → Repository réutilisable pour les entités suivantes.

## Endpoints

| Méthode | Route | Description | HTTP |
|---------|-------|-------------|------|
| GET | /accounts | Liste des comptes du user | 200 |
| POST | /accounts | Créer un compte | 201 |
| GET | /accounts/{id} | Détail d'un compte | 200 |
| PUT | /accounts/{id} | Modifier un compte | 200 |
| DELETE | /accounts/{id} | Soft delete | 200 |

Tous les endpoints requièrent l'authentification JWT (AuthMiddleware) et sont scopés par `user_id`.

## Architecture Backend

### Repository (`api/src/Repositories/AccountRepository.php`)

Couche d'accès aux données avec PDO. Méthodes :
- `create(array $data): array` — Insertion + retour de l'enregistrement créé
- `findById(int $id): ?array` — Recherche par ID (exclut soft-deleted)
- `findAllByUserId(int $userId): array` — Liste des comptes d'un utilisateur
- `update(int $id, array $data): ?array` — Mise à jour partielle des champs autorisés
- `softDelete(int $id): bool` — Marque `deleted_at` au lieu de supprimer

### Service (`api/src/Services/AccountService.php`)

Logique métier et validation. Méthodes :
- `list(int $userId): array`
- `create(int $userId, array $data): array`
- `get(int $userId, int $accountId): array` — Vérifie ownership
- `update(int $userId, int $accountId, array $data): array`
- `delete(int $userId, int $accountId): void`

### Validation

| Champ | Règle |
|-------|-------|
| name | Requis, max 100 caractères |
| account_type | Requis, enum BROKER/PROPFIRM |
| mode | Requis, enum DEMO/LIVE/CHALLENGE/VERIFICATION/FUNDED |
| currency | Optionnel, exactement 3 caractères, défaut EUR |
| initial_capital | Optionnel, >= 0, défaut 0 |
| broker | Optionnel, max 100 caractères |
| max_drawdown | Optionnel, >= 0 |
| daily_drawdown | Optionnel, >= 0 |
| profit_target | Optionnel, >= 0 |
| profit_split | Optionnel, 0-100 |

### Codes d'erreur

| Scénario | HTTP | Code | message_key |
|----------|------|------|-------------|
| Champ manquant | 422 | VALIDATION_ERROR | accounts.error.field_required |
| Type invalide | 422 | VALIDATION_ERROR | accounts.error.invalid_type |
| Mode invalide | 422 | VALIDATION_ERROR | accounts.error.invalid_mode |
| Capital négatif | 422 | VALIDATION_ERROR | accounts.error.invalid_capital |
| Compte non trouvé | 404 | NOT_FOUND | accounts.error.not_found |
| Pas propriétaire | 403 | FORBIDDEN | accounts.error.forbidden |

### Controller (`api/src/Controllers/AccountController.php`)

Controller mince, délègue au service. Actions : `index`, `store`, `show`, `update`, `destroy`.

### Exception ajoutée

- `api/src/Exceptions/ForbiddenException.php` — Étend HttpException (code HTTP 403, errorCode FORBIDDEN)

### Routes (`api/config/routes.php`)

5 nouvelles routes avec AuthMiddleware, pattern DI identique à Auth.

## Architecture Frontend

### Service (`frontend/src/services/accounts.js`)

Wrapper API pour les 5 opérations CRUD. Utilise `api.js` (fetch + JWT auto-refresh).

### Store Pinia (`frontend/src/stores/accounts.js`)

State reactif :
- `accounts` — Liste des comptes
- `loading` — État de chargement
- `error` — Dernière erreur

Actions : `fetchAccounts`, `createAccount`, `updateAccount`, `deleteAccount`.

### Vue (`frontend/src/views/AccountsView.vue`)

- DataTable PrimeVue avec colonnes : nom, type, mode (Tag coloré), devise, capital, courtier, actions
- Bouton "Nouveau compte" en header
- Message vide quand aucun compte
- Actions par ligne : modifier (crayon) et supprimer (corbeille avec confirmation)

### Composant (`frontend/src/components/account/AccountForm.vue`)

Dialog PrimeVue modale (500px) pour création et édition :
- Champs : nom, type (Select), mode (Select), devise, capital initial (InputNumber), courtier, drawdown max/journalier, objectif profit, partage profits
- Pré-rempli en mode édition
- Boutons Annuler / Enregistrer

### i18n

Clés ajoutées dans `fr.json` et `en.json` sous la section `accounts` :
- Labels de formulaire, types, modes, messages d'erreur et de succès

### Router

Route `/accounts` ajoutée comme enfant de la route authentifiée (AppLayout).

### Navigation

Lien "Comptes" ajouté dans AppLayout (header nav).

## Tests

### Backend — 38 nouveaux tests

| Fichier | Tests | Type |
|---------|-------|------|
| `tests/Integration/Repositories/AccountRepositoryTest.php` | 8 | Intégration |
| `tests/Unit/Services/AccountServiceTest.php` | 18 | Unitaire |
| `tests/Integration/Accounts/AccountFlowTest.php` | 12 | Intégration |

**Total backend : 150 tests, 347 assertions** (tous verts)

### Frontend — 9 nouveaux tests

| Fichier | Tests |
|---------|-------|
| `src/__tests__/accounts-store.spec.js` | 9 |

**Total frontend : 27 tests** (tous verts)

## Fichiers créés/modifiés

### Créés
- `api/src/Repositories/AccountRepository.php`
- `api/src/Services/AccountService.php`
- `api/src/Controllers/AccountController.php`
- `api/src/Exceptions/ForbiddenException.php`
- `api/tests/Integration/Repositories/AccountRepositoryTest.php`
- `api/tests/Unit/Services/AccountServiceTest.php`
- `api/tests/Integration/Accounts/AccountFlowTest.php`
- `frontend/src/services/accounts.js`
- `frontend/src/stores/accounts.js`
- `frontend/src/views/AccountsView.vue`
- `frontend/src/components/account/AccountForm.vue`
- `frontend/src/__tests__/accounts-store.spec.js`

### Modifiés
- `api/config/routes.php` — Ajout des 5 routes accounts
- `frontend/src/router/index.js` — Ajout route /accounts
- `frontend/src/components/layout/AppLayout.vue` — Ajout lien nav
- `frontend/src/locales/fr.json` — Clés i18n accounts
- `frontend/src/locales/en.json` — Clés i18n accounts
