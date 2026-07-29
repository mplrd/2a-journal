# 84 - Correctif : la sortie à BE se fait au cours d'entrée, pas au prix d'allègement

## Contexte

Ticket support #32. Scénario remonté :

> J'achète 10 lots à 24200, je mets BE à 24240 en y sortant 4 lots. Si après je suis pris à BE,
> 2A me propose comme prix de sortie à BE 24240 et non 24200.

C'est bien un bug. Le prix de BE configuré sur le trade (`be_price` / `be_points`) est un
**déclencheur d'allègement** : quand le cours l'atteint, on sort `be_size` lots et on remonte le
stop à l'entrée. Il n'a jamais vocation à être le prix de sortie quand on se fait *prendre* à BE —
là, le stop est au cours d'entrée.

## Cause : deux intentions confondues dans la même modale

`CloseTradeDialog` est ouverte avec `exit_type = BE` par deux chemins qui ne veulent pas dire la
même chose :

| Point d'entrée | Intention | Prix attendu | Taille attendue |
|---|---|---|---|
| `↑↑` prochain objectif (`getNextObjective`) | **mise à BE** : allègement de `be_size` lots à `be_price`, puis stop remonté à l'entrée | `be_price` (24240) | `be_size` (4 lots) |
| Bouton « Breakeven » (`pi-stop-circle`, désactivé tant que le trade est `OPEN`) | **sortie à BE** : le stop remonté à l'entrée a été touché | prix d'entrée (24200) | taille restante (6 lots) |

Le correctif du ticket #23 ([80 - Préremplissage du BE configuré](80-be-modal-prefill-configured.md))
avait câblé le préremplissage sur `trade.be_points` **dans les deux cas**, en ignorant le
`prefill.exit_price` que le chemin `↑↑` fournit déjà. Résultat : le chemin « mise à BE » tombait
juste par coïncidence, et le bouton « Breakeven » proposait le prix d'allègement.

Le fait que le bouton « Breakeven » soit **désactivé tant que `status === OPEN`** (règle métier de
[65 - Clôture de trade](65-close-trade-points.md) : on ne sort à BE que si le trade est déjà
sécurisé) confirme sa sémantique : il ne sert qu'à enregistrer une sortie au stop de BE.

## Correctif

`buildInitialForm()` distingue désormais les deux chemins par la présence de `prefill.exit_price` :

```js
if (exitType === ExitType.BE) {
  if (prefill?.exit_price != null) {
    const price = Number(prefill.exit_price)
    // BE points are signed (slippage either side of entry), cf. PricePointsInput.
    const points = trade.direction === Direction.BUY ? price - entry : entry - price
    return { ...base, exit_price: price, exit_points: points }
  }
  return { ...base, exit_price: entry, exit_points: 0 }
}
```

- **Mise à BE** (`↑↑`) : le prix planifié transmis dans le prefill est honoré, et les points sont
  recalculés en valeur *signée* (convention du mode BE de `PricePointsInput`). La taille reste
  celle du prefill (`be_size`), via le `base` existant. Le besoin du ticket #23 est préservé.
- **Sortie à BE** (bouton) : prix d'entrée, points à 0, et `exit_size` = taille restante (défaut de
  `base`) au lieu de `be_size`.
- Dans les deux cas les champs restent **éditables** : le trader ajuste sur le slippage/spread réel.

### Effet de bord assumé

Sur un trade dont le BE est configuré sans allègement (`be_size = 0`), le bouton « Breakeven »
préremplissait `exit_size = 0`, ce qui routait vers « mise à BE atteinte » (`markBeHit`). Il
propose désormais la taille restante et clôture réellement. C'est cohérent : ce bouton n'est
accessible que sur un trade déjà sécurisé, donc déjà passé à BE. Le cas « je protège mais je
n'allège pas » reste couvert par le chemin `↑↑`, qui appelle `markBeHit()` directement
(`action: 'mark'` dans `getNextObjective`) sans ouvrir la modale — ainsi que par la remise à 0
manuelle de la taille dans la modale, toujours testée.

## Hors périmètre : compensation automatique du spread

Le ticket proposait de préremplir « prix d'entrée + tolérance » pour compenser le spread. Non
retenu : le spread réel à l'instant du fill n'est pas calculable de façon fiable côté journal
(il dépend du broker, de l'instrument et du moment). Deux réponses existent déjà :

- **Côté saisie** : les champs restent éditables, le trader saisit son exécution réelle. Pour une
  exactitude au tick près, la **synchronisation broker** rapatrie le prix réel.
- **Côté statistiques** : le réglage **Seuil BE (%)** (`be_threshold_percent`, cf.
  [82 - Aides seuil BE](82-help-hints-be-threshold-point-value.md)) classe en break-even tout trade
  clôturé à moins de ce pourcentage du prix d'entrée — c'est là qu'on absorbe le spread.

## Couverture des tests

`frontend/src/components/trade/__tests__/CloseTradeDialog.spec.js`

| Test | Scénario | Statut |
|---|---|---|
| BUY : honours prefill.exit_price and derives signed points | mise à BE, achat (#23 préservé) | ✅ |
| SELL : honours prefill.exit_price and derives signed points | mise à BE, vente (#23 préservé) | ✅ |
| keeps the planned lightening size from the prefill | allègement `be_size` | ✅ |
| emits the configured BE price on submit | le prix d'allègement part bien au backend | ✅ |
| BUY : prefills the entry price (points 0) even when a BE is configured | sortie à BE, achat | ✅ |
| SELL : prefills the entry price (points 0) even when a BE is configured | sortie à BE, vente | ✅ |
| prefills the remaining size, not the planned be_size | taille restante | ✅ |
| ticket #32 scenario : 10 lots à 24200, BE à 24240 sur 4 lots | scénario exact du ticket → 24200 / 6 lots | ✅ |
| stays editable so the trader can book the real spread/slippage | ajustement manuel du slippage | ✅ |

Suite complète : 37 tests sur `CloseTradeDialog`, 437 tests frontend au vert.

## Fichiers touchés

| Fichier | Modification |
|---|---|
| `frontend/src/components/trade/CloseTradeDialog.vue` | Branche BE de `buildInitialForm()` : honore `prefill.exit_price` (mise à BE), sinon prix d'entrée + taille restante (sortie à BE) |
| `frontend/src/components/trade/__tests__/CloseTradeDialog.spec.js` | Bloc `#23` remplacé par un bloc couvrant les deux intentions |

Aucun changement backend : le contrat du payload de clôture est inchangé.
