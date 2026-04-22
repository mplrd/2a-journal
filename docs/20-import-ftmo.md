# 20 — Template d'import FTMO

## Fonctionnalités

Le template FTMO permet d'importer l'historique de trades exporté depuis la plateforme FTMO (propfirm) au format **CSV** ou **XLSX**.

### Ce que fait le template

- **Détection automatique** des colonnes FTMO (français et anglais)
- **Mapping des directions** : `sell`/`Sell`/`SELL` → `SELL`, `buy`/`Buy`/`BUY` → `BUY`
- **Gestion des colonnes dupliquées** : FTMO exporte 2 colonnes « Prix » (entry et exit) — le parser les déduplique automatiquement en `Prix` et `Prix_2`
- **Date d'ouverture** : contrairement à cTrader, FTMO fournit la date d'ouverture (`Ouvrir`) — elle est utilisée comme `opened_at` au lieu d'être approximée
- **Regroupement intelligent** : les trades avec même symbole, direction, prix d'entrée et date d'ouverture sont groupés en une position avec sorties partielles
- **Support CSV et XLSX** : le même template fonctionne pour les deux formats

### Colonnes mappées

| Champ journal | Colonne FTMO (FR) | Colonne FTMO (EN) |
|---------------|--------------------|--------------------|
| symbol        | Symbole            | Symbol             |
| direction     | Type               | Type               |
| opened_at     | Ouvrir             | Open               |
| closed_at     | Fermeture          | Close              |
| entry_price   | Prix               | Price              |
| exit_price    | Prix_2             | Price_2            |
| size          | Volume             | Volume             |
| pnl           | Profit             | Profit             |
| pips          | Pips               | Pips               |

## Choix d'implémentation

### Colonnes dupliquées dans le CSV

Le fichier FTMO contient deux colonnes « Prix » (prix d'entrée et prix de sortie). Le `FileParserService` utilisait les noms de colonnes comme clés associatives, ce qui faisait que la 2ème « Prix » écrasait la 1ère.

**Solution** : ajout d'une méthode `deduplicateHeaders()` dans `FileParserService` qui suffixe automatiquement les doublons (`Prix` → `Prix`, `Prix_2`). Cette solution est **générique** et s'applique à tous les formats (CSV et XLSX), pas seulement FTMO.

### Support de `opened_at`

FTMO fournit la date d'ouverture du trade, ce que cTrader ne fait pas. Modifications :
- `ColumnMapperService` : `opened_at` ajouté comme champ optionnel (ne casse pas cTrader)
- `ColumnMapperService::mapRow()` : parsing de dates étendu à `opened_at`
- `RowGroupingService` : utilise `opened_at` explicite quand disponible, sinon fallback sur l'approximation (earliest close)

### Auto-découverte

Le template est auto-découvert par `ImportService::getAvailableTemplates()` via glob sur `config/import_templates/*.php`. Aucune modification du controller, du frontend ou des routes nécessaire.

## Couverture des tests

| Test | Scénario | Statut |
|------|----------|--------|
| `testTemplateHasBrokerKey` | Clé broker = 'ftmo' | ✅ |
| `testTemplateSupportsCsvAndXlsx` | file_types contient csv et xlsx | ✅ |
| `testTemplateHasAllRequiredColumns` | 7 colonnes requises présentes | ✅ |
| `testTemplateHasOptionalColumns` | pips et opened_at présents | ✅ |
| `testParserDeduplicatesDuplicateHeaders` | 2 colonnes « Prix » → Prix + Prix_2 | ✅ |
| `testParserKeepsAllDataWithDuplicateHeaders` | Données entry/exit préservées | ✅ |
| `testMapsColumnsWithFtmoHeaders` | Mapping headers FR | ✅ |
| `testMapsColumnsWithEnglishHeaders` | Mapping headers EN | ✅ |
| `testAppliesDirectionMapping` | sell→SELL, buy→BUY | ✅ |
| `testAppliesDirectionMappingCaseInsensitive` | Sell→SELL, Buy→BUY | ✅ |
| `testMapRowConvertsRawRowToNormalized` | Pipeline complète d'une ligne | ✅ |
| `testFullPipelineWithFixture` | Parse → Map → Group sur 5 lignes | ✅ |
| `testGroupingKeyIncludesOpenedAt` | Clé de groupement inclut opened_at | ✅ |

**Total : 13 tests, 55 assertions** — tous passent.

Suite complète : **697 tests, 1821 assertions**, aucune régression.
