# 24 - Import avec champs personnalisés

## Objectif

Étendre le système d'import pour permettre le mapping de colonnes du fichier source sur les champs personnalisés de l'utilisateur, en complément des champs standards (symbol, direction, pnl, etc.).

## Périmètre

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

- `ImportService::buildCustomTemplate()` accepte un paramètre `custom_fields_mapping`
- `ImportService::preview()` extrait les valeurs custom de chaque ligne et les attache aux positions groupées
- `ImportService::confirm()` appelle `CustomFieldService::validateAndSaveValues()` après la création de chaque trade
- Validation identique à la saisie manuelle (type, options SELECT, etc.)
- Valeurs custom invalides comptées dans `skipped_errors` sans bloquer l'import

#### Frontend

- `ImportDialog.vue` : section "Champs personnalisés" après le mapping standard (si l'utilisateur a des champs actifs)
- Chaque champ custom = dropdown avec colonnes du fichier + option vide (non mappé)
- Preview (étape 2) : valeurs custom affichées

### 2. Seeder démo

Le compte démo (`demo@2a.journal` / `Demo*123`) est enrichi :

| Champ | Type | Options |
|-------|------|---------|
| Tendance | SELECT | Bullish, Bearish |

Valeurs assignées à une partie des trades existants.

### 3. Amélioration UX de l'import custom + template par défaut

- Rendre l'UI de mapping custom plus claire et guidée
- Proposer un template d'import "par défaut" (colonnes standards avec noms génériques) pour les utilisateurs sans broker spécifique

## Plan d'implémentation (TDD)

### Phase 1 — Mapping custom fields à l'import

1. Tests backend : `ImportService` gère `custom_fields_mapping` dans preview + confirm
2. Code backend : étendre `ImportService`, `ImportController`, flow confirm
3. Tests frontend : `ImportDialog` affiche et envoie le mapping custom fields
4. Code frontend : enrichir `ImportDialog` + preview
5. Seeder : ajouter champ Tendance au compte démo
6. Qualité : `/check-quality`, `/check-i18n`, `/audit-security`

### Phase 2 — UX et template par défaut

7. Template d'import par défaut (colonnes génériques)
8. Refonte UX du mode custom (guidage, clarté)
9. Documentation finale

## Tests

À compléter au fil de l'implémentation.
