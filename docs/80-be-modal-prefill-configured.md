# 80 - Correctif : la modale BE prérremplit le break-even configuré sur le trade

> **Affiné par [84 - Sortie à BE au cours d'entrée](84-be-exit-price-entry.md) (ticket #32).**
> Le préremplissage décrit ici ne s'applique plus qu'au chemin « mise à BE » (bouton `↑↑` prochain
> objectif), qui transmet le prix planifié dans le prefill. Le bouton « Breakeven » de la grille
> signifie « je me suis fait prendre à BE » et propose désormais le **prix d'entrée** et la taille
> restante. Les extraits de code ci-dessous ne reflètent plus l'implémentation.

## Contexte

Ticket support #23. À la mise à BE (bouton « Breakeven » de la grille, voir [65 - Clôture de trade](65-close-trade-points.md)), la modale `CloseTradeDialog` proposait **toujours le prix d'entrée** (`exit_price = entry`, points = 0), en ignorant le break-even que l'utilisateur avait paramétré sur le trade (BE remonté de quelques points pour couvrir les frais, avec une quantité à sortir).

L'utilisateur signalait : « j'ai renseigné un prix de mise à BE avec quantité à sortir, mais 2A ne me propose pas le prix renseigné avant ».

## Cause

Dans `buildInitialForm()`, la branche BE forçait :

```js
if (exitType === ExitType.BE) {
  return { ...base, exit_price: entry, exit_points: 0 }
}
```

Ce comportement est correct pour le cas classique **BE = prix d'entrée**, mais ne tient pas compte des champs `be_points` / `be_size` saisis à la création du trade. C'est pourquoi le bug passait inaperçu : tant que le BE était à l'entrée, le préremplissage tombait juste.

## Correctif

La branche BE prérremplit désormais le break-even **configuré sur le trade**, avec repli sur le prix d'entrée si aucun BE n'a été paramétré :

```js
if (exitType === ExitType.BE) {
  const bePoints = trade.be_points != null ? Number(trade.be_points) : null
  const hasBe = bePoints != null && bePoints > 0
  const bePrice = hasBe
    ? (trade.direction === Direction.BUY ? entry + bePoints : entry - bePoints)
    : entry
  const beSize = trade.be_size != null ? Number(trade.be_size) : null
  return {
    ...base,
    exit_price: bePrice,
    exit_points: hasBe ? bePoints : 0,
    exit_size: prefill?.exit_size ?? (beSize != null ? beSize : remaining),
  }
}
```

- `exit_price` / `exit_points` : repris de `be_points` (BUY : `entry + be_points`, SELL : `entry - be_points`), cohérent avec la formule `be_price` du reste de l'app.
- `exit_size` : repris de `be_size` quand il est défini, sinon la taille restante (comportement existant).
- Les champs restent **éditables** (slippage / BE remonté au moment de l'exécution).

Le contrat « protéger sans alléger » est préservé : un `be_size` configuré à 0 prérremplit `exit_size = 0`, ce qui route vers l'action « mise à BE atteinte » au lieu d'une sortie partielle.

## Couverture des tests

| Test (`CloseTradeDialog.spec.js`) | Scénario | Statut |
|---|---|---|
| BUY : prefills exit_price = entry + be_points et points = be_points | BE configuré, achat | ✅ |
| SELL : prefills exit_price = entry - be_points et points = be_points | BE configuré, vente | ✅ |
| prefills exit_size from the configured be_size | quantité à sortir au BE | ✅ |
| falls back to entry (points 0) when no BE is configured | repli cas classique | ✅ |
| emits the configured BE price on submit | le prix configuré part bien au backend | ✅ |
