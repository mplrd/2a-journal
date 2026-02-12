# 09 - Symboles per-user ("Mes actifs")

## Contexte

Le champ symbole dans les formulaires (orders, trades, positions) utilisait initialement un texte libre, puis un endpoint global `GET /symbols`. Cette approche ne convient pas car la `point_value` dépend du user/broker, et les futures statistiques nécessitent des symboles correctement configurés par utilisateur.

Cette évolution convertit la table `symbols` en données per-user avec CRUD complet.

## Modifications de la base de données

### Table `symbols` (modifiée)

Ajout de :
- `user_id` (FK NOT NULL vers `users`)
- `created_at`, `updated_at`, `deleted_at` (soft delete)
- Clé unique `(user_id, code)` remplaçant l'ancienne `(code)`
- Suppression des données seed globales (déplacées en templates PHP)

### Migration

```sql
DELETE FROM symbols;
ALTER TABLE symbols
  ADD COLUMN user_id INT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active,
  ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at,
  DROP INDEX uk_symbols_code,
  ADD INDEX idx_symbols_user_id (user_id),
  ADD UNIQUE KEY uk_symbols_user_code (user_id, code),
  ADD CONSTRAINT fk_symbols_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
```

## Backend

### Enum

- **`SymbolType`** : `INDEX`, `FOREX`, `CRYPTO`, `STOCK`, `COMMODITY`

### Repository (`SymbolRepository`)

Réécrit intégralement pour le modèle per-user :
- `create(array $data)` : insertion avec `user_id`
- `findById(int $id)` : recherche par ID (exclut soft-deleted)
- `findAllByUserId(int $userId, int $limit, int $offset)` : liste paginée per-user
- `findByUserAndCode(int $userId, string $code)` : recherche par code pour un user
- `update(int $id, array $data)` : mise à jour des champs autorisés
- `softDelete(int $id)` : suppression logique
- `seedForUser(int $userId)` : création des 6 symboles par défaut (NASDAQ, DAX, SP500, CAC40, EURUSD, BTCUSD)

### Service (`SymbolService`)

Réécrit avec le pattern CRUD complet (comme `AccountService`) :
- `list(int $userId, array $params)` : liste paginée avec méta
- `create(int $userId, array $data)` : validation + vérification doublon par code
- `get(int $userId, int $symbolId)` : lecture avec vérification ownership
- `update(int $userId, int $symbolId, array $data)` : mise à jour avec validation
- `delete(int $userId, int $symbolId)` : suppression logique

#### Validation
- `code` : non vide, max 20 caractères
- `name` : non vide, max 100 caractères
- `type` : enum `SymbolType` valide
- `point_value` : > 0
- `currency` : exactement 3 caractères
- Doublon code per-user → `symbols.error.duplicate_code`

### Controller (`SymbolController`)

5 actions RESTful (pattern `AccountController`) :
- `index` : `GET /symbols` (paginé)
- `store` : `POST /symbols`
- `show` : `GET /symbols/{id}`
- `update` : `PUT /symbols/{id}`
- `destroy` : `DELETE /symbols/{id}`

### Routes

5 routes ajoutées (toutes protégées par `AuthMiddleware`) :
```
GET    /symbols
POST   /symbols
GET    /symbols/{id}
PUT    /symbols/{id}
DELETE /symbols/{id}
```

### Seeding à l'inscription

`AuthService` reçoit désormais `SymbolRepository` en dépendance (nullable pour rétrocompatibilité des tests unitaires). À l'inscription, `seedForUser()` crée automatiquement les 6 symboles par défaut.

### Tests backend

| Fichier | Tests |
|---------|-------|
| `EnumsTest.php` | 2 tests SymbolType (cases + tryFrom) |
| `SymbolRepositoryTest.php` | 12 tests (CRUD + seed + soft delete + pagination) |
| `SymbolServiceTest.php` | 22 tests (validation + ownership + duplicate + CRUD) |
| `SymbolFlowTest.php` | 11 tests (HTTP flow + registration seeding + ownership) |

**Total : 403 tests backend, 1027 assertions, tous verts.**

## Frontend

### Constants

Ajout de `SymbolType` dans `enums.js` (INDEX, FOREX, CRYPTO, STOCK, COMMODITY).

### Service (`symbols.js`)

Réécrit avec CRUD complet :
- `list(params)` : liste paginée
- `get(id)` : détail
- `create(data)` : création
- `update(id, data)` : mise à jour
- `remove(id)` : suppression

### Store (`symbols.js`)

Réécrit avec :
- `fetchSymbols(force)` : chargement avec cache
- `createSymbol(data)` : ajout à la liste locale
- `updateSymbol(id, data)` : remplacement dans la liste locale
- `deleteSymbol(id)` : retrait de la liste locale
- `symbolOptions` : computed pour les Select (label/value)

### Composants

- **`SymbolForm.vue`** : Dialog de création/édition (code, nom, type, point_value, devise)
- **`SymbolsView.vue`** : Page CRUD avec DataTable (pattern `AccountsView`)

### Bouton '+' inline

Ajout d'un bouton '+' à côté du Select symbole dans :
- `OrderForm.vue`
- `TradeForm.vue`
- `PositionForm.vue`

Ouvre un `SymbolForm` imbriqué. À la création, le symbole est ajouté au store et auto-sélectionné dans le formulaire parent.

### Router

Route `/symbols` ajoutée dans les enfants de AppLayout.

### Navigation

Lien "Mes actifs" / "My Assets" ajouté dans `AppLayout.vue`.

### i18n

Clés ajoutées dans `fr.json` et `en.json` :
- `nav.symbols`
- `symbols.*` (title, create, edit, empty, code, name, type, point_value, currency, types.*, error.*, success.*, add_symbol, confirm_delete)

### Tests frontend

| Fichier | Tests |
|---------|-------|
| `symbols-store.spec.js` | 12 tests (CRUD + cache + error + loading) |

**Total : 78 tests frontend, tous verts.**

## Corrections post-audit

### Quality check
- Validation : clé d'erreur `error.field_too_long` (globale) pour les champs code/name trop longs, au lieu de réutiliser `symbols.error.field_required`
- `mb_strlen` pour la validation currency (support multi-byte)
- `defaultSymbols()` utilise `SymbolType::INDEX->value` au lieu de chaînes hardcodées
- `SymbolsView.vue` importe et utilise `SymbolType` dans `typeSeverity()`

### Soft delete vs unique constraint
- Ajout `findSoftDeletedByUserAndCode()` et `restore()` dans `SymbolRepository`
- À la création, si un symbole soft-deleted existe avec le même code → restauration au lieu d'INSERT (évite erreur de contrainte unique)
- À l'update, si le code change vers un code soft-deleted → hard delete du fantôme avant UPDATE
- Ajout `hardDelete()` dans `SymbolRepository`

### Privacy audit
- Ajout `$reset()` à tous les data stores (symbols, accounts, positions, orders, trades)
- `auth.logout()` appelle `$reset()` sur les 5 stores → nettoyage complet au déconnexion

### i18n audit
- Ajout clé globale `error.field_too_long` dans `fr.json` et `en.json`

## Points d'attention

- Les positions/orders/trades stockent le symbole en `VARCHAR(50)` → pas de FK, pas de migration de données nécessaire
- L'inscription crée désormais 6 symboles par défaut pour chaque nouvel utilisateur
- `AuthService` accepte `?SymbolRepository` (nullable) pour ne pas casser les tests unitaires existants qui ne passent pas ce paramètre
- Les dialogs imbriqués (SymbolForm dans OrderForm/TradeForm/PositionForm) fonctionnent grâce au z-index automatique de PrimeVue
- La contrainte unique `(user_id, code)` est gérée avec le soft delete : restauration automatique si le code existait déjà en supprimé
