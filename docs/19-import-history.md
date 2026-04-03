# 19 - Import d'historique

## Objectif

Permettre l'import de l'historique de trading depuis un fichier d'export broker (XLSX/CSV). Le système détecte automatiquement les colonnes via des templates broker, regroupe les exécutions en positions avec sorties partielles, et détecte les doublons pour éviter les re-imports.

## Formats supportés

### Templates broker disponibles

| Template | Formats | Particularités |
|----------|---------|----------------|
| cTrader | XLSX, CSV | Colonnes FR/EN, sorties partielles |
| FTMO | CSV, XLSX | Colonnes dupliquées "Prix", date d'ouverture explicite |
| FXCM | XML (SpreadsheetML) | 2 lignes/trade, séparateur de milliers, direction par colonnes Vendu/Achete |

Voir `docs/20-import-ftmo.md` et `docs/21-import-fxcm.md` pour les détails spécifiques.

### cTrader (template `ctrader`)

Export XLSX depuis cTrader (Spotware). Colonnes détectées en FR et EN :

| Champ | Colonnes FR | Colonnes EN |
|-------|-------------|-------------|
| symbol | Symbole | Symbol |
| direction | Sens d'ouverture | Direction |
| closed_at | Heure de clôture | Closing Time |
| entry_price | Cours d'entrée | Entry Price |
| exit_price | Price de clôture | Closing Price |
| size | Quantité de clôture | Closing Quantity |
| pnl | € nets / $ nets | EUR nets / USD nets |
| pips | Pips | Pips |
| comment | Commentaire | Comment |

**Données absentes** : heure d'ouverture (→ estimée par la première sortie partielle), SL/TP (→ NULL), setup (→ NULL).

**Devise** : détectée depuis le nom de la colonne P&L (€ → EUR, $ → USD).

### Architecture des templates

Les templates sont des fichiers PHP dans `api/config/import_templates/`. Chaque template décrit :
- Les noms de colonnes possibles (multi-langue)
- Le mapping des valeurs (Acheter → BUY, Vendre → SELL)
- Le format de date
- La clé de regroupement (symbol + direction + entry_price)
- Options avancées : `multi_row` (fusion multi-lignes), `thousands_separator`, `row_filter` (filtrage lignes parasites)

Extensible sans code : ajouter un fichier PHP pour un nouveau broker.

## Regroupement des positions

Les lignes du fichier sont regroupées par clé composite `(symbol, direction, entry_price)`. Chaque groupe devient une position avec N sorties partielles :

- **total_size** : somme des tailles
- **total_pnl** : somme des P&L
- **avg_exit_price** : moyenne pondérée par taille
- **opened_at** : timestamp de la première sortie (approximation)
- **closed_at** : timestamp de la dernière sortie

Exemple réel : 626 lignes cTrader → 414 positions (134 avec sorties partielles multiples).

## Détection des doublons

Chaque position reçoit un `external_id` = SHA-256 de `(symbol, direction, entry_price, opened_at, closed_at, total_size)`. Stocké en base sur `positions.external_id` (UNIQUE par user). Un re-import du même fichier détecte 100% de doublons.

## Endpoints API

### GET /imports/templates

Liste des templates broker disponibles : `[{broker, label, file_types}]`.

### POST /imports/preview

Upload multipart (`file` + `broker`). Parse le fichier, retourne un aperçu sans persister :

```json
{
  "total_rows": 626,
  "total_positions": 414,
  "positions": [...],
  "unknown_symbols": ["GER40.cash", "EURUSD"],
  "currency": "EUR"
}
```

### POST /imports/confirm

Upload multipart (`file` + `broker` + `account_id` + `symbol_mapping`). Persiste en transaction :
- Crée `import_batches` (audit trail)
- Pour chaque position non-doublon : crée `positions` + `trades` (CLOSED) + `partial_exits`
- Enregistre les alias symboles pour les futurs imports

Retourne le bilan : `{batch_id, imported_positions, imported_trades, skipped_duplicates, skipped_errors}`.

### GET /imports/batches

Historique des imports pour l'utilisateur.

### POST /imports/batches/{id}/rollback

Annule un import : supprime toutes les positions du batch (CASCADE sur trades + partial_exits). Le batch passe en status ROLLED_BACK.

## Architecture backend

| Couche | Fichier | Rôle |
|--------|---------|------|
| Enum | `ImportStatus.php` | PENDING, PROCESSING, COMPLETED, FAILED, ROLLED_BACK |
| Service | `FileParserService.php` | Lecture XLSX/CSV/XML, déduplication headers |
| Service | `ColumnMapperService.php` | Mapping colonnes, fusion multi-lignes, détection devise |
| Service | `RowGroupingService.php` | Regroupement par position, calcul agrégés |
| Service | `ImportService.php` | Orchestrateur : preview, confirm, rollback |
| Repository | `ImportBatchRepository.php` | CRUD table `import_batches` |
| Repository | `SymbolAliasRepository.php` | CRUD table `symbol_aliases` |
| Controller | `ImportController.php` | 5 actions (templates, preview, confirm, batches, rollback) |
| Config | `config/import_templates/*.php` | Templates broker (cTrader, FTMO, FXCM) |

**Validation** : type/taille fichier, colonnes requises, ownership du compte, doublons external_id.

## Schema

### ALTER positions

- `sl_points`, `sl_price`, `setup` → nullable (positions importées sans ces données)
- `import_batch_id` INT UNSIGNED NULL → FK vers import_batches
- `external_id` VARCHAR(128) NULL → hash pour détection doublons

### Table import_batches

Audit trail : user_id, account_id, broker_template, original_filename, file_hash, compteurs (total_rows, imported_positions, imported_trades, skipped_duplicates, skipped_errors), status, error_log JSON.

### Table symbol_aliases

Mapping broker symbol → journal symbol par user et broker : `(user_id, broker_symbol, journal_symbol, broker_template)`. UNIQUE constraint. Utilisé pour résoudre automatiquement les symboles lors des imports suivants.

## Mode custom (mapping manuel)

Si l'utilisateur n'a pas de template broker, il choisit "Autre (mapping manuel)" :

1. Upload du fichier → `POST /imports/headers` retourne les en-têtes
2. L'UI affiche des dropdowns pour associer chaque en-tête à un champ (symbol, direction, etc.)
3. L'utilisateur configure le format de date et les valeurs Buy/Sell
4. Le backend construit un template à la volée via `ImportService::buildCustomTemplate()`

Champs requis : symbol, direction, closed_at, entry_price, exit_price, size, pnl.
Champs optionnels : pips, comment.

### Endpoint supplémentaire

**POST /imports/headers** — upload multipart, retourne `{ headers: ["col1", "col2", ...] }` sans parser les données.

Les endpoints `preview` et `confirm` acceptent un `column_mapping` JSON en alternative au `broker` pour le mode custom.

## Architecture frontend

### ImportDialog.vue

Dialog PrimeVue ouvert depuis la page Comptes (bouton import par compte). Le compte est pré-sélectionné. Stepper 3 étapes :

1. **Fichier** : sélection broker (dropdown avec templates + "Autre"), upload fichier. Si "Autre" : détection colonnes puis mapping manuel (dropdowns + format date + valeurs direction)
2. **Aperçu** : résumé (lignes, positions, devise), mapping symboles broker → journal, DataTable des positions groupées
3. **Résultat** : bilan de l'import (positions importés, doublons, erreurs)

**Accès** : bouton `pi-upload` sur chaque compte dans AccountsView. Pas de page dédiée.

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Unit | `FileParserServiceTest.php` | 6 tests (XLSX, CSV, validation) |
| Unit | `ColumnMapperServiceTest.php` | 8 tests (mapping FR/EN, devise, dates) |
| Unit | `RowGroupingServiceTest.php` | 8 tests (groupement, agrégation, hash) |
| Unit | `ImportServiceTest.php` | 4 tests (preview, grouping, symboles, templates) |
| Integration | `ImportBatchRepositoryTest.php` | 3 tests (CRUD) |
| Integration | `SymbolAliasRepositoryTest.php` | 4 tests (upsert, find) |

## Clés i18n

Namespace `import.*` : 62 clés (en.json et fr.json), incluant les clés custom mapping (`field_*`, `detect_columns`, `map_columns`, `date_format`, `direction_*_label`).
Ajout `common.close`.

## Dépendance ajoutée

`phpoffice/phpspreadsheet` ^5.5 — lecture des fichiers XLSX. Nécessite `ext-zip` (activé dans php.ini).

## Auto-création des symboles

Lors de la confirmation d'import, si un symbole résolu n'existe pas dans "Mes actifs" de l'utilisateur, il est automatiquement créé avec :
- **type** : `OTHER` (modifiable ensuite dans la page Symboles)
- **devise** : celle du compte cible de l'import
- **point_value** : 1.0 (valeur par défaut)

## Présélection du broker

Si le compte sélectionné a un champ `broker` renseigné et qu'il correspond à un template d'import disponible (comparaison case-insensitive), le broker est présélectionné automatiquement dans le dropdown. Fonctionne dans l'ImportView et l'ImportDialog.

## Fonctionnalités génériques ajoutées

| Fonctionnalité | Description | Utilisé par |
|----------------|-------------|-------------|
| Déduplication headers | Suffixe `_2`, `_3` aux colonnes dupliquées | FTMO (2 colonnes "Prix") |
| `opened_at` optionnel | Date d'ouverture explicite au lieu de l'approximation | FTMO, FXCM |
| Parser SpreadsheetML | Parsing XML natif préservant la précision | FXCM |
| Fusion multi-lignes | `multi_row: N` fusionne N lignes en 1 trade | FXCM (2 lignes/trade) |
| Séparateur de milliers | `thousands_separator` retiré avant cast numérique | FXCM (format `19,226.05`) |
| Filtre de lignes | `row_filter` exclut les lignes parasites | FXCM (résumés, totaux) |
| Accept dynamique | File input adapté aux formats du template sélectionné | Tous |

## Phase 2 (futur)

- Connecteurs API broker (`BrokerConnectorInterface`) : OAuth2, sync automatique (cTrader Open API en premier)
- Table `broker_connections` pour stocker les tokens chiffrés
- Frontend : page de configuration des connexions broker
- Import "transaction log" pour plateformes sans export trade complet (SwissBorg, Fortuneo, Ouinex) — voir évolution #20
