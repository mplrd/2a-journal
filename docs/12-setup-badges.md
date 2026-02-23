# 12 — Setups en badges multi-valeurs

## Contexte

Le champ `setup` des positions était un simple `VARCHAR(255)` en texte libre. Un trade peut être pris sur plusieurs setups en convergence (ex: "Breakout" + "FVG"). Cette évolution transforme le champ en multi-valeurs avec autocomplétion depuis un dictionnaire personnel.

## Base de données

### Nouvelle table `setups`

```sql
CREATE TABLE IF NOT EXISTS setups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_setups_user_id (user_id),
    UNIQUE KEY uk_setups_user_label (user_id, label),
    CONSTRAINT fk_setups_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
);
```

### Modification de `positions.setup`

- Type : `VARCHAR(255)` → `TEXT`
- Format : JSON array de strings (`["Breakout","FVG"]`)
- Migration des données existantes : `"Breakout"` → `["Breakout"]`

### Setups par défaut (seed à l'inscription)

Breakout, FVG, OB, Liquidity Sweep, BOS, CHoCH, Supply/Demand, Trend Follow

## Backend

### API Setups (CRUD)

| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `/setups` | Liste des setups de l'utilisateur |
| POST | `/setups` | Créer un nouveau setup |
| DELETE | `/setups/{id}` | Supprimer un setup (soft delete) |

Toutes les routes nécessitent l'authentification JWT.

### Fichiers créés

| Fichier | Rôle |
|---------|------|
| `api/src/Repositories/SetupRepository.php` | Accès données (CRUD, seed, ensureExist) |
| `api/src/Services/SetupService.php` | Logique métier (validation, ownership) |
| `api/src/Controllers/SetupController.php` | Contrôleur HTTP (index, store, destroy) |
| `api/database/migration-setup-badges.sql` | Script de migration one-shot |

### Validation du setup

Le champ `setup` est validé comme un tableau de strings :
- Non vide, doit être un array avec au moins 1 élément
- Chaque label : string non vide, max 100 caractères
- Les setups inconnus sont auto-créés via `INSERT IGNORE`
- Encodé en JSON avant stockage : `json_encode($data['setup'])`

### Services modifiés

| Fichier | Modification |
|---------|-------------|
| `OrderService.php` | Validation setup array, ensureExist, json_encode |
| `TradeService.php` | Idem |
| `PositionService.php` | Idem (update) |
| `ShareService.php` | `json_decode` + `implode(' \| ')` pour le texte de partage |
| `AuthService.php` | Seed des 8 setups par défaut à l'inscription |

## Frontend

### Composant AutoComplete

Remplacement de `InputText` par PrimeVue `AutoComplete` avec `multiple` dans :
- `OrderForm.vue` — création d'ordre
- `TradeForm.vue` — création de trade
- `PositionForm.vue` — édition de position

Le champ `form.setup` passe de `string` à `array` (ex: `['Breakout', 'FVG']`).

L'autocomplétion filtre les suggestions depuis le store `setups` (GET /setups).

### Affichage en grids

Les colonnes "Setup" des DataTable (OrdersView, PositionsView) affichent des `Tag` PrimeVue au lieu de texte brut :

```html
<Tag v-for="s in parseSetup(data.setup)" :key="s" :value="s" severity="info" />
```

Avec fallback rétrocompatible : si la valeur n'est pas un JSON array, elle est wrappée dans un tableau.

### Share preview

Le composable `useSharePreview` joint les setups avec ` | ` :
```
💬 Breakout | FVG
```

### Store Pinia

`stores/setups.js` : cache la liste des setups (fetchSetups, createSetup, deleteSetup, setupOptions computed).

### i18n

Clés ajoutées dans `fr.json` et `en.json` sous la section `setups` (error, success).

## Tests

- **Backend** : 477 tests, 1213 assertions (PHPUnit)
  - `SetupRepositoryTest` — 14 tests (CRUD, seed, ensureExist)
  - `SetupServiceTest` — 11 tests (validation, ownership, doublon)
  - `SetupFlowTest` — 9 tests (routes GET/POST/DELETE + auth)
  - Tests existants adaptés pour setup en array/JSON
- **Frontend** : build OK (`npm run build`)
