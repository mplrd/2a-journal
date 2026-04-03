# 21 — Template d'import FXCM (XML SpreadsheetML)

## Fonctionnalités

Le template FXCM permet d'importer l'historique de trades exporté depuis la plateforme FXCM au format **XML SpreadsheetML** (relevé de compte global).

### Ce que fait le template

- **Parsing XML SpreadsheetML custom** : parser dédié qui préserve la précision des nombres (PhpSpreadsheet perd les décimales à cause du séparateur de milliers)
- **Détection automatique des en-têtes** : saute les lignes de métadonnées (titre, période, nom d'utilisateur) pour trouver la ligne d'en-tête
- **Fusion multi-lignes** : chaque trade FXCM occupe 2 lignes (ouverture + fermeture) — le système les fusionne automatiquement en un seul enregistrement
- **Détection de direction** : détermine BUY/SELL selon quelle colonne de prix est remplie sur la ligne d'ouverture (Vendu = SELL, Achete = BUY)
- **Séparateur de milliers** : gère le format `19,226.05` en retirant la virgule avant le cast numérique
- **Filtrage des lignes de résumé** : exclut automatiquement les lignes "Total:" et "RESUME DE L'ACTIVITE"

### Structure du fichier FXCM

Chaque trade = 2 lignes :
| Ligne | Ticket | Monnaie | Volume | Date | Vendu | Achete | G/P Net |
|-------|--------|---------|--------|------|-------|--------|---------|
| Ouverture | 70054784 | GER30 | 1.00 | 22/11/2024 7:43 | 19,226.05 | | |
| Fermeture | | | | 22/11/2024 7:44 | | 19,222.56 | 0.35 |

- **SELL** : prix d'entrée dans `Vendu` (ouverture), prix de sortie dans `Achete` (fermeture)
- **BUY** : prix d'entrée dans `Achete` (ouverture), prix de sortie dans `Vendu` (fermeture)

### Colonnes mappées

| Champ journal | Source FXCM |
|---------------|-------------|
| symbol        | Monnaie |
| direction     | _déduit des colonnes Vendu/Achete_ |
| opened_at     | Date (ligne ouverture), format d/m/Y G:i |
| closed_at     | Date (ligne fermeture), format d/m/Y G:i |
| entry_price   | Vendu ou Achete (selon direction) |
| exit_price    | Achete ou Vendu (selon direction) |
| size          | Volume |
| pnl           | G/P Net (ligne fermeture) |
| pips          | Marges (pips) (ligne fermeture) |

## Choix d'implémentation

### Parser SpreadsheetML custom

PhpSpreadsheet peut lire le format SpreadsheetML, mais interprète mal les nombres avec séparateur de milliers (virgule) — `19,226.05` devient `19.00`. Un parser dédié basé sur `simplexml_load_file` a été implémenté dans `FileParserService::parseSpreadsheetMl()` :
- Lit les cellules via XPath avec gestion de l'attribut `ss:Index` (positionnement 1-based)
- Préserve les valeurs brutes comme chaînes de caractères
- Détecte automatiquement la ligne d'en-tête (première ligne avec ≥5 cellules non vides)
- Filtre les lignes vides et les lignes de résumé

### Fusion multi-lignes (`multi_row`)

Nouvelle fonctionnalité générique dans `ColumnMapperService::mergeMultiRows()` :
- Configurable via `multi_row: 2` et `multi_row_merge` dans le template
- Fusionne N lignes consécutives en un seul enregistrement
- Crée des champs synthétiques préfixés `_` (`_direction`, `_entry_price`, `_exit_price`, `_opened_at`, `_closed_at`)
- Intégré dans le pipeline `ImportService::preview()` avant le mapping de colonnes

### Séparateur de milliers (`thousands_separator`)

Nouvelle option générique dans `ColumnMapperService::mapRow()` :
- Configurable par template via `thousands_separator: ','`
- Retire le séparateur des valeurs numériques avant le cast en float
- Applicable à tous les champs numériques (prix, PnL, pips, volume)

### Accept dynamique côté frontend

Les inputs file dans `ImportView.vue` et `ImportDialog.vue` utilisent maintenant un `accept` dynamique basé sur les `file_types` du template sélectionné. FXCM affiche `.xml`, cTrader/FTMO affichent `.xlsx,.csv`.

## Couverture des tests

| Test | Scénario | Statut |
|------|----------|--------|
| `testTemplateHasBrokerKey` | Clé broker = 'fxcm' | ✅ |
| `testTemplateSupportsXml` | file_types contient xml | ✅ |
| `testTemplateHasAllRequiredColumns` | 7 colonnes requises présentes | ✅ |
| `testTemplateHasThousandsSeparator` | thousands_separator = ',' | ✅ |
| `testTemplateHasMultiRowConfig` | multi_row = 2 | ✅ |
| `testParserSupportsXmlExtension` | Parse un fichier .xml | ✅ |
| `testParserFindsHeaderRowInSpreadsheetMl` | Détecte les en-têtes après métadonnées | ✅ |
| `testParserPreservesNumberPrecisionWithThousandsSeparator` | Garde `19,226.05` comme chaîne | ✅ |
| `testParserReturns6DataRows` | 3 trades × 2 lignes = 6 rows | ✅ |
| `testParserSkipsSummaryRows` | Exclut "Total:" | ✅ |
| `testMultiRowMergingProducesMergedRows` | 6 rows → 3 trades fusionnés | ✅ |
| `testMultiRowMergingSellTrade` | SELL : direction, prix, dates corrects | ✅ |
| `testMultiRowMergingBuyTrade` | BUY : direction, prix, dates corrects | ✅ |
| `testMapsColumnsWithFxcmHeaders` | Mapping des en-têtes synthétiques | ✅ |
| `testMapRowStripsThousandsSeparator` | 19,226.05 → 19226.05 | ✅ |
| `testFullPipelineWithFixture` | Parse → Merge → Map → Group complet | ✅ |

**Total : 16 tests, 148 assertions** — tous passent.

Suite complète : **713 tests, 1969 assertions**, aucune régression.
