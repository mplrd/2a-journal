# Champs personnalisés (Custom Fields)

## Vue d'ensemble

Les champs personnalisés permettent aux utilisateurs de définir des informations supplémentaires à associer à chaque trade. Chaque utilisateur gère ses propres définitions de champs et renseigne les valeurs lors de la création d'un trade.

## Types de champs

| Type | Description | Composant UI | Validation |
|------|-------------|-------------|------------|
| `BOOLEAN` | Oui/Non | ToggleSwitch | `"true"` ou `"false"` |
| `TEXT` | Texte libre | InputText | Max 5000 caractères |
| `NUMBER` | Valeur numérique | InputNumber | `is_numeric()` |
| `SELECT` | Liste de choix | Select (dropdown) | Valeur parmi les options définies |

## Architecture

### Base de données (EAV)

Deux tables distinctes :

**`custom_field_definitions`** — Définitions des champs par utilisateur :
- `id`, `user_id`, `name`, `field_type` (ENUM), `options` (JSON pour SELECT)
- `sort_order` (ordre d'affichage), `is_active` (activation/désactivation)
- Soft delete via `deleted_at` (préserve l'historique)
- Contrainte d'unicité : `(user_id, name)`

**`custom_field_values`** — Valeurs par trade :
- `custom_field_id`, `trade_id`, `value` (TEXT — casté selon le type)
- Contrainte d'unicité : `(custom_field_id, trade_id)`
- CASCADE DELETE sur la définition et le trade

### Backend

**Enum** : `App\Enums\CustomFieldType` (BOOLEAN, TEXT, NUMBER, SELECT)

**Couches** :
- `CustomFieldDefinitionRepository` — CRUD PDO pour les définitions
- `CustomFieldValueRepository` — Sauvegarde/lecture batch des valeurs
- `CustomFieldService` — Validation, CRUD définitions, validation et sauvegarde des valeurs
- `CustomFieldController` — Endpoints REST
- `TradeService` — Intègre les custom fields dans create/get/list

### Frontend

- `services/customFields.js` — Wrapper API
- `stores/customFields.js` — Store Pinia (definitions, activeDefinitions)
- `constants/enums.js` — `CustomFieldType`
- `components/custom-field/` — Formulaire et liste de gestion
- `views/CustomFieldsView.vue` — Page de gestion (accessible via sidebar)
- Intégration dans `TradeForm.vue` (section dynamique) et `TradesView.vue` (colonnes dynamiques)

## API

### Gestion des définitions

| Méthode | Route | Description |
|---------|-------|-------------|
| `GET` | `/api/custom-fields` | Liste des définitions de l'utilisateur |
| `POST` | `/api/custom-fields` | Créer une définition |
| `GET` | `/api/custom-fields/{id}` | Détail d'une définition |
| `PUT` | `/api/custom-fields/{id}` | Modifier une définition |
| `DELETE` | `/api/custom-fields/{id}` | Supprimer une définition (soft delete) |

#### Créer une définition

```json
POST /api/custom-fields
{
  "name": "Confiance",
  "field_type": "BOOLEAN"
}
```

```json
POST /api/custom-fields
{
  "name": "Humeur",
  "field_type": "SELECT",
  "options": ["Bonne", "Neutre", "Mauvaise"]
}
```

#### Réponse

```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 42,
    "name": "Confiance",
    "field_type": "BOOLEAN",
    "options": null,
    "sort_order": 0,
    "is_active": 1,
    "created_at": "2025-04-08 10:00:00",
    "updated_at": "2025-04-08 10:00:00"
  }
}
```

### Valeurs dans les trades

Les valeurs des champs personnalisés sont passées **inline** lors de la création d'un trade et retournées avec les données du trade.

#### Création d'un trade avec custom fields

```json
POST /api/trades
{
  "account_id": 1,
  "direction": "BUY",
  "symbol": "NAS100",
  "entry_price": 18000,
  "size": 1,
  "setup": ["Breakout"],
  "sl_points": 50,
  "opened_at": "2025-04-08 09:30:00",
  "custom_fields": [
    { "field_id": 1, "value": "true" },
    { "field_id": 2, "value": "Bonne" }
  ]
}
```

#### Réponse trade avec custom fields

```json
{
  "success": true,
  "data": {
    "id": 123,
    "symbol": "NAS100",
    "...": "...",
    "custom_fields": [
      { "field_id": 1, "name": "Confiance", "field_type": "BOOLEAN", "value": "true" },
      { "field_id": 2, "name": "Humeur", "field_type": "SELECT", "value": "Bonne" }
    ]
  }
}
```

### Filtrage par champ personnalisé

```
GET /api/trades?custom_filter[field_id]=1&custom_filter[value]=true
```

Le filtrage se fait côté serveur par un `JOIN` conditionnel sur `custom_field_values`.

## Interface utilisateur

### Page de gestion (`/custom-fields`)

Accessible depuis la sidebar (icône sliders). Permet de :
- Voir la liste des champs définis avec leur type
- Créer un nouveau champ (nom, type, options pour SELECT)
- Modifier un champ existant (nom, options)
- Supprimer un champ (avec confirmation — les valeurs associées sont supprimées)

### Formulaire de création de trade

Les champs personnalisés actifs apparaissent dans une section dédiée après le champ "Notes". Le rendu est adapté au type :
- **BOOLEAN** : ToggleSwitch
- **TEXT** : InputText
- **NUMBER** : InputNumber
- **SELECT** : Select avec les options de la définition

### Grille des trades

Les champs personnalisés actifs sont affichés comme colonnes dynamiques dans le DataTable, entre les colonnes standard et la colonne d'actions :
- **BOOLEAN** : Icône check/cross avec couleur
- **TEXT/SELECT** : Texte brut
- **NUMBER** : Valeur numérique

## Validation

| Règle | Message i18n |
|-------|-------------|
| Nom requis | `custom_fields.error.field_required` |
| Nom > 100 caractères | `custom_fields.error.name_too_long` |
| Nom en doublon | `custom_fields.error.duplicate_name` |
| Type invalide | `custom_fields.error.invalid_field_type` |
| SELECT sans options | `custom_fields.error.select_options_required` |
| Valeur BOOLEAN invalide | `custom_fields.error.invalid_boolean` |
| Texte > 5000 caractères | `custom_fields.error.text_too_long` |
| Valeur NUMBER invalide | `custom_fields.error.invalid_number` |
| Option SELECT invalide | `custom_fields.error.invalid_option` |

## Tests

### Backend
- **Unit** : `tests/Unit/Services/CustomFieldServiceTest.php` (29 tests)
- **Unit** : `tests/Unit/Services/CustomFieldValueTest.php` (15 tests)
- **Integration** : `tests/Integration/CustomFields/CustomFieldDefinitionFlowTest.php` (11 tests)
- **Integration** : `tests/Integration/CustomFields/CustomFieldValueFlowTest.php` (6 tests)

### Frontend
- Tests existants non cassés (94 tests passants)

## Migration

Fichier : `api/database/migrations/002_custom_fields.sql`

Exécution : `php api/database/migrate.php`
