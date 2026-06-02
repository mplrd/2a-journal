# 65 - Clôture de trade : actions dédiées et saisie points/prix

## Objectif

Cloture rapide d'un trade depuis la grille avec une **intention sémantique** explicite (Stop Loss, Sortie BE, Stop Win), au lieu d'une action "fermer" générique qui force l'utilisateur à choisir le type de sortie dans la modale. La saisie de la magnitude de la sortie est en **prix OU points**, les deux champs synchronisés, **toujours en valeur positive** (le signe est déduit de l'intention via le bouton).

## Pourquoi

Avant cette refonte :

1. La grille trades exposait un seul bouton « Fermer le trade » (`pi-sign-out`). L'utilisateur ouvrait la modale, puis devait choisir le type de sortie (TP/SL/BE/Manuel) dans un Select.
2. Une première itération a tenté d'ajouter un champ « points » signé à la modale (positif = profit, négatif = perte). Bug terrain : PrimeVue InputNumber filtre le `-` au clavier quand `:min` est `null` ou >= 0, donc impossible de saisir une perte en points. Et pour un short, la borne min pour autoriser les négatifs aurait dû être `-∞` théoriquement (perte illimitée).
3. L'utilisateur a aussi remarqué que **mentalement on raisonne en magnitude positive** : « SL 50 points », « Stop Win 100 points » — pas « -50 », « +100 ».

→ Refonte : l'**intention** (SL/BE/Stop Win) est exprimée à l'amont (bouton dédié dans la grille), la **magnitude** est saisie positive dans la modale, le **signe** est appliqué par le système selon `direction × exit_type`.

## UX

### Grille trades — boutons d'action

Le bouton « Fermer » générique est remplacé par **3 boutons dédiés**, dans l'ordre **BE → SL → Stop Win** (chronologie naturelle d'un trade : sécuriser, puis sortir en perte ou en gain). Ils sont côte à côte avec le bouton existant `↑↑ Next objective` :

| Bouton | Icône | Couleur | Tooltip | exit_type | État |
|--------|-------|---------|---------|-----------|------|
| Breakeven | `pi-stop-circle` | info (bleu) | « Breakeven » | `BE` | **disabled si `status === OPEN`** |
| Stop Loss | `pi-times-circle` | danger (rouge) | « Stop Loss » | `SL` | actif |
| Stop Win | `pi-check-circle` | success (vert) | « Stop Win » | `MANUAL` | actif |

**Règle métier BE** : un trade ne peut sortir au BE que s'il est déjà sécurisé (au moins un partial exit pris). Le bouton est donc désactivé tant que `status === OPEN`, avec un tooltip explicatif (« Disponible quand le trade est sécurisé »). On garde le bouton visible (au lieu de le cacher) pour la cohérence d'affichage.

Le bouton `Next objective` n'est pas touché : il continue de proposer en 1-clic le prochain TP planifié ou le passage à BE planifié, en lisant `trade.targets` et `trade.be_price`.

Pareil sur mobile (TileList) : les 3 boutons en rounded, même ordre, même règle BE/OPEN.

### Modale `CloseTradeDialog`

Plus de Select `Type de sortie`. Le titre de la modale change selon `exit_type` :

| exit_type | Header |
|-----------|--------|
| SL | « Stop Loss » |
| BE | « Breakeven » |
| TP | « Take Profit » (cas `next objective`) |
| MANUAL | « Stop Win » |

Champs (Prix de sortie / Points (depuis entrée)) :

| exit_type | Pré-remplissage | Mode | `min` points |
|-----------|-----------------|------|--------------|
| BE | `exit_price = entry`, `points = 0` | **signed** (slippage ±) | `-entry_price` |
| SL | depuis `trade.sl_points` si défini, sinon vide | magnitude positive | `0` |
| TP (via `next objective`) | depuis le target spec (`exit_price`, `exit_size`) | magnitude positive | `0` |
| MANUAL (Stop Win) | **vide** — le système ne sait pas la valeur réalisée | magnitude positive | `0` |

L'utilisateur peut toujours ajuster les valeurs pré-remplies (BE pour le slippage réel, SL pour une exécution différente du SL planifié, TP si le fill est légèrement différent).

Conversion automatique selon `(direction × exit_type)` :

| exit_type | BUY (long) | SELL (short) | Mode |
|-----------|------------|--------------|------|
| TP / MANUAL (profit) | `exit = entry + points` | `exit = entry - points` | magnitude positive |
| SL (loss) | `exit = entry - points` | `exit = entry + points` | magnitude positive |
| BE (slippage/spread) | `exit = entry + points` | `exit = entry - points` | **signed** (points peut être ±) |

Sens inverse :
- Modes magnitude positive : `points = |exit - entry|` (la magnitude est toujours positive ; si l'utilisateur saisit un prix dans le mauvais sens par rapport à l'intention du bouton, c'est qu'il a cliqué sur le mauvais bouton — la sémantique reste celle du bouton).
- Mode BE (signed) : `points = (direction === BUY) ? exit - entry : entry - exit` (préserve le signe pour refléter slippage favorable/défavorable).

### Pourquoi BE est signed et pas les autres

BE n'a **pas de sens** profit/loss intrinsèque — c'est juste une intention « sortir autour de l'entry ». Selon les conditions de marché, le slippage ou le spread peut pousser l'exécution réelle des deux côtés. On a donc besoin de pouvoir saisir des magnitudes signées.

Pour SL/TP/MANUAL, l'intention dicte le signe : SL → toujours dans le sens perdant, TP/MANUAL → toujours dans le sens gagnant. L'utilisateur saisit la magnitude, le système applique le signe. Cela évite l'écueil du `:min` PrimeVue (cf. [[feedback-primevue-inputnumber-negative]]).

**BE sans allègement** : sur un BE, ramener la taille de sortie à `0` (« je protège mais je n'allège pas ») route vers `markBeHit()` au lieu de la clôture partielle — une sortie de taille 0 n'est pas un fill et serait rejetée (`invalid_exit_size`). Concrètement la modale émet `mark-be` au lieu de `close`. Spécialisé pour BE uniquement.

## Architecture

### Frontend uniquement

Aucun changement backend — `POST /trades/{id}/close` continue d'attendre `exit_price` + `exit_type`. `exit_points` est purement UX et strippé du payload à la soumission.

| Fichier | Changement |
|---------|-----------|
| `frontend/src/components/trade/CloseTradeDialog.vue` | Refonte logique : `signedDelta()`, `priceToPoints` = `Math.abs`, mode BE disabled, header dynamique, plus de Select exit_type. Émet `mark-be` quand `exit_type === BE && exit_size === 0` (BE sans allègement) |
| `frontend/src/views/TradesView.vue` | `openCloseDialog(trade, exitType)` signature étendue. Bouton « Fermer » remplacé par 3 boutons SL/BE/Stop Win (desktop + mobile). `@mark-be="handleMarkBe"` → `store.markBeHit()` |
| `frontend/src/locales/{fr,en}.json` | 4 clés titres (`close_sl`, `close_be`, `close_tp`, `close_stop_win`) + 3 clés actions (`action_sl`, `action_be`, `action_stop_win`) |

### Édition a posteriori

Si l'utilisateur s'est trompé de bouton (a cliqué SL au lieu de Stop Win), il peut corriger en éditant le trade fermé via le menu `…` → la modale d'édition (`TradeForm`) garde le Select `exit_type` historique pour modifier après coup.

## Tests

`frontend/src/components/trade/__tests__/CloseTradeDialog.spec.js` (28 tests) :

- **Header dynamique** : SL/BE/TP/MANUAL → header correct (4 tests)
- **SL** : BUY et SELL, sens points → prix, sens prix → magnitude (3 tests)
- **Stop Win** : BUY et SELL, sens points → prix (2 tests)
- **BE éditable signed** : prefill à entry/0, inputs NOT disabled, slippage favorable/défavorable BUY et SELL via points et via prix (7 tests)
- **TP prefill** : `next objective` hydrate exit_price + magnitude (1 test)
- **Submission** : payload contient `exit_type` + `exit_price`, jamais `exit_points` ; BE émet `exit_price = entry` (2 tests)
- **BE sans allègement** : `exit_size = 0` sur BE → émet `mark-be` (prefill ou saisie manuelle) ; taille > 0 → `close` normal ; non-BE taille 0 → `close` (pas de spécialisation) (4 tests)

## Clés i18n ajoutées

| Clé | FR | EN |
|-----|----|----|
| `trades.close_sl` | Stop Loss | Stop Loss |
| `trades.close_be` | Breakeven | Breakeven |
| `trades.close_tp` | Take Profit | Take Profit |
| `trades.close_stop_win` | Stop Win | Stop Win |
| `trades.be_requires_secured` | Disponible quand le trade est sécurisé | Available once the trade is secured |
| `trades.action_sl` | SL | SL |
| `trades.action_be` | BE | BE |
| `trades.action_stop_win` | Stop Win | Stop Win |
| `trades.exit_points` | Points (depuis entrée) | Points (from entry) |
