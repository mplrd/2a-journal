# 68 - Saisie prix ⇄ points des objectifs (SL / BE / TP)

## Objectif

Permettre de **renseigner les objectifs d'un trade (Stop Loss, Breakeven, Take Profits) en prix OU en points**, indifféremment, les deux champs synchronisés. Avant cette évolution, seul le **nombre de points** était saisissable sur le formulaire de trade ; le prix correspondant était affiché en **lecture seule**. L'utilisateur devait donc calculer mentalement le nombre de points depuis le prix où il voulait poser son SL/TP.

Cette saisie bidirectionnelle existait déjà dans la **modale de clôture** (`CloseTradeDialog`, cf. doc 65) mais sous forme de logique *inline*. Elle est ici **extraite en composant partagé** `PricePointsInput` et **réutilisée** sur le formulaire de trade, et la modale est migrée dessus.

## Pourquoi

Retour bêta (Robin, 30/05/2026 — cf. `docs/retours-beta-tests.md#e-11`) :

> « Pouvoir indiquer le prix où poser son TP / SL sans avoir à calculer le nombre de points. Que les champs "nombre de points" et "prix" soient tous deux renseignables. »

Le composant de saisie de la sortie (modale de clôture) faisait déjà exactement ça. Plutôt que de dupliquer la logique, on l'a **extraite** pour la rendre réutilisable — d'où un seul endroit qui porte la conversion prix ⇄ points, partagé par 4 usages (sortie + SL + BE + chaque TP).

## Fonctionnalités

- Sur le `TradeForm`, les champs **SL**, **BE** et **chaque Take Profit** exposent désormais **deux champs éditables liés** : « Points » et « Prix ». Éditer l'un recalcule l'autre instantanément.
- Le **prix d'entrée** peut encore bouger pendant la saisie du formulaire : dans ce cas, **les points restent figés** (c'est la distance de risque, l'invariant métier) et le **prix est recalculé**. Idem si on bascule la direction BUY/SELL.
- Le **backend est inchangé** : le formulaire continue d'envoyer les **points** (`sl_points`, `be_points`, `targets[].points`). Le prix est une commodité de saisie, convertie localement. Les champs `sl_price` / `be_price` ne sont pas transmis au backend.
- La modale de clôture (`CloseTradeDialog`) garde un comportement **strictement identique** (couverte par ses tests existants, repris tels quels), mais s'appuie maintenant sur le composant partagé.

## Choix d'implémentation

### Composant `frontend/src/components/common/PricePointsInput.vue`

Deux `InputNumber` PrimeVue côte à côte, reliés. API :

| Prop | Rôle |
|------|------|
| `v-model:points` / `v-model:price` | Les deux valeurs liées (two-way). Un consommateur peut binder l'une, l'autre, ou les deux. |
| `entryPrice` | Prix d'entrée de référence pour la conversion. |
| `direction` | `BUY` / `SELL` — détermine le sens de l'offset. |
| `mode` | Mode d'offset : `SL`, `TP` ou `BE` (cf. ci-dessous). |
| `pointsLabel` / `priceLabel` | Libellés optionnels (sinon pas de `<label>`). |
| `pointsPlaceholder` / `pricePlaceholder` | Placeholders optionnels (mode compact, ex. lignes de TP). |
| `pointsName` / `priceName` | `data-name` des inputs (sémantique + tests). |
| `priceFirst` | Inverse l'ordre d'affichage (prix avant points) — utilisé par la modale. |
| `pointsFractionDigits` / `priceFractionDigits` | Précision (défaut 2 / 5). |
| `locale` | Locale `InputNumber` (défaut `en-US`). Exposée en prop pour préparer l'évolution E-10 (format local des nombres). |

### Les trois modes d'offset

Le signe et la borne diffèrent selon l'objectif. C'est le point subtil de la réutilisation : la **BE de la modale** et la **BE du formulaire** n'ont *pas* la même sémantique.

| Mode | Sens (BUY) | Sens (SELL) | Borne points | Utilisé par |
|------|-----------|-------------|--------------|-------------|
| `SL` | `entry − pts` | `entry + pts` | `≥ 0` | modale SL, TradeForm SL |
| `TP` | `entry + pts` | `entry − pts` | `≥ 0` | modale TP + Stop Win, TradeForm **BE planifiée** + TP |
| `BE` | `entry + pts` (signé) | `entry − pts` (signé) | `−entryPrice` | modale BE (slippage de part et d'autre de l'entrée) |

- La **BE planifiée du formulaire** (« je remonterai mon stop à BE + X points pour couvrir les frais ») est un offset **positif** dans le sens du profit → mode `TP`.
- La **BE réalisée de la modale** (« je me suis fait sortir autour du point mort, avec du slippage des deux côtés ») est **signée** → mode `BE`, avec une borne `min` dynamique à `−entryPrice` (sinon PrimeVue `InputNumber` filtre la frappe du `-` quand `min ≥ 0` — quirk connu, cf. doc 65).

### Synchronisation

- Éditer **points** → `price = pointsToPrice(points)`.
- Éditer **prix** → `points = priceToPoints(price)` (magnitude `abs` en mode non signé ; signe préservé en mode `BE`).
- Changement de **entryPrice / direction / mode** → le prix est recalculé depuis les points (points figés). Implémenté via un `watch` immédiat qui sert aussi à **amorcer** le prix à l'ouverture.
- `entryPrice` nul → pas de conversion (pas d'affichage de prix, pas de crash).

### Intégration `TradeForm`

- Les blocs SL / BE / TP en lecture seule (affichage `<div>` / `<span>` grisé) sont remplacés par le composant.
- `populateFromTrade` amorce `sl_price` / `be_price` / `targets[].price` depuis les points stockés, pour un affichage cohérent dès l'ouverture en édition.
- `handleSave` retire `sl_price` / `be_price` du payload (commodités UX, le backend lit les points). Les `targets` sont envoyés via `calculatedTargets` (prix dérivé des points), comportement inchangé.

### Migration `CloseTradeDialog`

La logique inline (`setExitPrice`, `setExitPoints`, `pointsToPrice`, `priceToPoints`, `signedDelta`, `pointsMin`, `isSignedMode`) est supprimée et remplacée par le composant + une simple computed `priceMode` qui mappe `exit_type` → mode (`SL→SL`, `BE→BE`, `TP/MANUAL→TP`). Aucun changement de contrat : la modale émet toujours `exit_price`, `exit_points` reste interne.

## Couverture des tests

### `PricePointsInput.spec.js` (22 tests)

| Scénario | Vérifie |
|----------|---------|
| Mode SL BUY/SELL | points → prix (`entry ∓ pts`), borne `0` |
| Mode SL, saisie prix | prix → points (magnitude) |
| Mode TP BUY/SELL | points → prix (`entry ± pts`), prix → points, borne `0` |
| Mode BE BUY/SELL | points signés (slippage + et −), prix → points signé, borne `−entry` |
| Amorçage | prix amorcé depuis les points entrants ; vide si points `null` |
| Changement d'entrée | points figés, prix recalculé |
| Propagation `null` | vider l'un vide l'autre |
| Two-way models | éditer points émet `update:points` **et** `update:price` (et inversement) |
| Sans prix d'entrée | pas de conversion, pas de crash |

### `TradeForm.spec.js` (7 tests, nouveau)

| Scénario | Vérifie |
|----------|---------|
| Amorçage à l'ouverture (BUY) | prix SL = `entry − sl_points`, prix BE = `entry + be_points`, prix TP = `entry + points` |
| Édition d'un prix | saisir un prix SL / TP recalcule les points |
| Contrat de sauvegarde | le payload porte les **points** ; `sl_price` / `be_price` **absents** ; `targets` portent points + prix dérivé |

### `CloseTradeDialog.spec.js` (24 tests)

Repris **sans modification** — la migration vers le composant partagé est un *drop-in* transparent (régression verrouillée).

**Total** : 53 tests verts sur le périmètre, suite frontend complète au vert (291 tests).

## Suites possibles

- **E-10** (format local des nombres) : la prop `locale` du composant est le point d'extension. Aujourd'hui `en-US` partout ; à brancher sur la locale utilisateur quand E-10 sera traité.
- Restructuration mineure de la ligne de TP (le prix est désormais accolé aux points plutôt que séparé par la taille) — déjà appliquée pour loger le composant.
