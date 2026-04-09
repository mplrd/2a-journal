# 24 - Import avec champs personnalisés

## Objectif

Étendre le système d'import pour permettre le mapping de colonnes du fichier source sur les champs personnalisés de l'utilisateur, et améliorer l'expérience d'import custom.

## Fonctionnalités

### 1. Mapping des champs custom à l'import (mode custom)

Lors d'un import en mode **custom** (mapping manuel), l'UI affiche une section "Champs personnalisés" après les champs standards. Chaque champ custom actif de l'utilisateur est proposé comme cible de mapping via un dropdown listant les colonnes du fichier.

**Données échangées** :

```json
// FormData (preview + confirm) — en plus du column_mapping standard
"custom_fields_mapping": {
  "42": "Trend",       // field_id → nom colonne fichier
  "43": "Timeframe"
}
```

**Preview enrichi** — chaque position retournée inclut les valeurs custom :

```json
{
  "symbol": "EURUSD",
  "pnl": 150,
  "custom_fields": [
    { "field_id": 42, "field_name": "Tendance", "value": "Bullish" }
  ]
}
```

#### Backend

- `ImportService::preview()` et `confirm()` acceptent un paramètre `customFieldsMapping`
- Méthode `attachCustomFieldValues()` extrait les valeurs des lignes brutes et les attache aux positions groupées (première valeur non-vide par groupe)
- `confirm()` appelle `CustomFieldService::validateAndSaveValues()` après la création de chaque trade
- Erreurs custom fields non bloquantes (comptées dans `skipped_errors`)
- `CustomFieldService` injecté dans `ImportService` comme dépendance optionnelle

#### Frontend

- `ImportDialog.vue` : section "Champs personnalisés" dans un fieldset dédié (si l'utilisateur a des champs actifs)
- Chaque champ custom = dropdown avec colonnes du fichier + option vide (non mappé)
- `customFieldsMapping` envoyé dans le FormData en JSON

### 2. Template d'import générique

Template `generic.php` avec des noms de colonnes standards couvrant les exports CSV les plus courants :

| Champ | Noms reconnus (FR + EN) |
|-------|------------------------|
| symbol | Symbol, Symbole, Instrument, Asset, Ticker, Pair... |
| direction | Direction, Side, Type, Action, Sens... + mapping Buy/Sell/Long/Short/Acheter/Vendre |
| closed_at | Close Date, Closing Time, Date de clôture, Exit Date... |
| opened_at | Open Date, Opening Time, Date d'ouverture, Entry Date... |
| entry_price | Entry Price, Open Price, Prix d'entrée... |
| exit_price | Exit Price, Close Price, Prix de sortie... |
| size | Size, Quantity, Volume, Lots, Taille... |
| pnl | PnL, P&L, Profit, Résultat... + pattern regex |
| pips | Pips, Points |
| comment | Comment, Notes, Commentaire... |

Affiché en premier dans le dropdown broker sous le label "Standard (CSV)".

### 3. Amélioration UX du mapping custom

- **Auto-détection** : les en-têtes du fichier sont détectés automatiquement dès la sélection du fichier (plus besoin de cliquer "Détecter")
- **Auto-mapping** : tentative de mapping automatique basée sur la correspondance de noms (exact puis partiel, insensible à la casse)
- **Indicateur** : message info affichant le nombre de champs auto-détectés
- **Organisation visuelle** : 4 fieldsets distincts :
  - Champs requis (symbol, direction, etc.)
  - Options d'import (format date, valeurs Buy/Sell)
  - Champs optionnels (pips, comment)
  - Champs personnalisés (si définis)

### 4. Seeder démo

Le compte démo (`demo@2a.journal` / `Demo*123`) est enrichi :

| Champ | Type | Options |
|-------|------|---------|
| Tendance | SELECT | Bullish, Bearish |

Valeurs assignées à ~70% des trades fermés (basé sur la direction : BUY→Bullish, SELL→Bearish).

## Architecture

### Fichiers modifiés/créés

| Fichier | Rôle |
|---------|------|
| `api/src/Services/Import/ImportService.php` | `preview()`, `confirm()`, `attachCustomFieldValues()` |
| `api/src/Controllers/ImportController.php` | Extraction `custom_fields_mapping` du FormData |
| `api/config/routes.php` | Injection `CustomFieldService` dans `ImportService` |
| `api/config/import_templates/generic.php` | Template générique |
| `api/database/seed-demo.php` | Champ Tendance + valeurs |
| `frontend/src/services/imports.js` | Paramètre `customFieldsMapping` |
| `frontend/src/components/import/ImportDialog.vue` | UI enrichie |
| `frontend/src/locales/{en,fr}.json` | 7 clés i18n ajoutées |

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Unit | `ImportCustomFieldsTest.php` | 6 tests (mapping, grouping, empty, missing columns) |
| Unit | `ImportServiceTest.php` | 3 tests ajoutés (generic template, direction mapping) |
| Fixture | `custom_fields_sample.csv` | CSV avec colonnes Trend/Score |
| Fixture | `generic_sample.csv` | CSV avec noms de colonnes standards |

## Clés i18n ajoutées

Namespace `import.*` : `custom_fields_section`, `custom_fields_hint`, `detecting_columns`, `auto_mapped`, `required_fields`, `optional_fields`, `options_section`.
