# 65 - Clôture de trade : prix OU points

## Objectif

Permettre à l'utilisateur de saisir le prix de sortie d'un trade soit en **prix absolu** soit en **points depuis le prix d'entrée** lors de la fermeture (totale ou partielle) depuis la grille des trades. Les deux champs sont synchronisés bidirectionnellement : éditer l'un recalcule l'autre.

## Pourquoi

- En live, l'utilisateur lit souvent le prix exact sur sa plateforme broker → saisie directe en prix.
- En post-mortem ou pour des trades non bookés (analyse hypothétique), il raisonne en **distance en points** depuis son entrée → saisie en points.
- Forcer un seul mode obligeait à un calcul mental à chaque fois.

## UX

Dans la modale `CloseTradeDialog` (déclenchée depuis la grille trades, partielle ou totale), deux champs côte à côte :

| Champ | Mode | Unité |
|-------|------|-------|
| Prix de sortie * | éditable | prix absolu (ex. `18550`) |
| Points (depuis entrée) | éditable | distance signée (ex. `+50`) |

Modifier l'un recalcule l'autre instantanément. La synchro respecte la **direction du trade** :

- **BUY** : `exit_price = entry_price + exit_points` ; `exit_points = exit_price - entry_price`
- **SELL** : `exit_price = entry_price - exit_points` ; `exit_points = entry_price - exit_price`

**Convention de signe** des points = P&L en points :
- Positif → trade profitable (peu importe la direction)
- Négatif → trade perdant

Exemple : sur un SELL avec entrée à 18500, saisir `points = +50` met `exit_price = 18450` (sortie en gain). Saisir `points = -30` met `exit_price = 18530` (sortie en perte).

## Architecture

### Frontend uniquement

Aucun changement backend : le contrat de l'endpoint `POST /trades/{id}/close` reste inchangé, il continue d'attendre `exit_price`. Le champ `exit_points` est **purement interne** à la modale (UX convenience), il est strippé du payload à la soumission.

| Fichier | Rôle |
|---------|------|
| `frontend/src/components/trade/CloseTradeDialog.vue` | Ajout du champ `exit_points`, conversions `priceToPoints`/`pointsToPrice`, synchro via `@update:modelValue` |
| `frontend/src/locales/{fr,en}.json` | Clé `trades.exit_points` |

### Prefill

Quand le parent passe un `prefill` avec un `exit_price` (cas du clic sur un TP suggéré), `exit_points` est hydraté automatiquement à partir du prix.

### Watcher d'ouverture

Le watcher sur `props.visible` utilise désormais `{ immediate: true }` pour gérer le cas où la modale est mountée déjà visible (ouvre proprement avec hydratation du form). Pas de side effect quand `trade` est encore `null` (guard `if (val && props.trade)`).

## Tests

`frontend/src/components/trade/__tests__/CloseTradeDialog.spec.js` (11 tests) :

- **BUY** : profit/perte sens prix → points, sens points → prix (4 tests)
- **SELL** : profit/perte sens prix → points, sens points → prix (4 tests)
- **Rendering** : présence des deux inputs (1 test)
- **Submission** : `exit_points` ne sort PAS dans le payload émis (1 test)
- **Prefill** : un `exit_price` prefill hydrate `exit_points` cohéremment (1 test)

## Clés i18n ajoutées

- `trades.exit_points` — FR : "Points (depuis entrée)" / EN : "Points (from entry)"
