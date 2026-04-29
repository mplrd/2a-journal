# Étape 46 — Import : template multi-format CSV / XLSX / ODS

## Résumé

Le bouton "Télécharger le template" servait jusqu'ici un fichier `.csv` uniquement, alors que le frontend acceptait déjà `.csv,.xlsx,.xls,.xlsm,.xml` à l'upload. L'asymétrie était gênante : on accepte des Excel mais on n'en livre pas. Cette étape étend l'export du template aux 3 formats les plus courants pour les utilisateurs tableur :

- **CSV** — universel, déjà existant
- **XLSX** — Excel
- **ODS** — LibreOffice Calc / OpenDocument

À l'import, l'extension `.ods` est désormais reconnue (PhpSpreadsheet la lit nativement, il manquait juste le whitelist côté `FileParserService`).

## Fonctionnalités

### Backend

`GET /imports/template-file?format=csv|xlsx|ods` :

- `format=csv` (défaut, backward compat) → sert le fichier statique `api/public/templates/import-template.csv`
- `format=xlsx` → génère à la volée via `PhpOffice\PhpSpreadsheet\Writer\Xlsx`, content-type `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- `format=ods` → génère via `PhpOffice\PhpSpreadsheet\Writer\Ods`, content-type `application/vnd.oasis.opendocument.spreadsheet`
- Format inconnu → fallback sur `csv`

Les valeurs (en-têtes + 2 lignes d'exemple) sont définies inline dans le controller pour garantir que les 3 formats sont strictement synchronisés (pas de drift entre une source CSV et une source XLSX).

`FileParserService::ALLOWED_EXTENSIONS` étendu à `.ods` (le parseur `parseSpreadsheet` utilise déjà `IOFactory::load` qui détecte ODS automatiquement, juste le whitelist manquait).

### Frontend

`importsService.downloadTemplate(format = 'csv')` :
- Param optionnel ; envoie `?format=...` à l'API
- Nom de fichier téléchargé adapté (`import-template.csv|xlsx|ods`)

`ImportDialog.vue` :
- Le bouton unique "Télécharger le template" devient un groupe : un label "Template :" + 3 boutons icon + label `CSV`, `XLSX`, `ODS` côte-à-côte
- Tooltip identique sur les 3 (`t('import.download_template')`)
- `acceptedFileTypes` (mode custom) inclut désormais `.ods`

## Choix d'implémentation

### Pourquoi générer XLSX/ODS à la volée et pas servir des fichiers statiques ?

Servir 3 fichiers statiques imposerait de les régénérer à la main à chaque évolution du modèle (ajout d'une colonne, ex: notes, custom_fields…). PhpSpreadsheet génère en quelques ms à partir d'un array PHP commun → la structure des 3 formats reste forcément identique, garantie par le code.

### Pourquoi 3 boutons distincts plutôt qu'un Select de format ?

Un Select demande 2 clics (ouvrir + choisir) là où 3 boutons en demandent 1. Pour une action "download template" qui sera faite une fois par utilisateur, l'économie de friction vaut le poil de surface visuelle en plus.

### Pourquoi le défaut `csv` plutôt que `xlsx` ?

CSV est universellement lisible (tous les tableurs, vim, grep), ne traîne pas de meta-données (formules, formatages…), et c'est le format historique du template. On garde ce défaut pour ne pas changer l'expérience des utilisateurs avancés ou de scripts qui POSTent vers `/imports/template-file` sans param.

### Pourquoi pas de test backend dédié ?

Le controller utilise `: never` + `exit;` (pattern de download stream). Le tester proprement demande de wrapper `exit` ou d'utiliser un Response object — refactor hors scope. Les 71 tests d'import existants passent ✓ (rien cassé), validation manuelle des 3 formats côté front confirme le comportement.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Backend — suite Import | 71/71 ✓ |
| Frontend — suite globale | 191/191 ✓ |
| Build | OK |

## i18n

- `import.template_format_label` ("Template :" / "Template:")
- `import.template_hint` reformulé pour mentionner les 3 formats

## Suite

Reste dans `evolutions.md` :
- **Filtres en badges** (Trades / Orders / Positions)
- **Date range picker à la Airbnb** (Performance / Dashboard)

Le user a indiqué qu'on les ferait sur **une même branche** (sujet UI/filtres commun) après cette étape.
