# 79 - Correctif : l'édition de la taille d'un trade resynchronise `remaining_size`

## Contexte

Ticket support #31. Un utilisateur saisit un trade à **1 contrat** (avec SL, mise à BE et TP planifiés), réalise son erreur, édite le trade et corrige la taille en **0,04**. Au moment de gérer la position (mise à BE), 2A lui propose de traiter **0,96 contrat** (= 1 − 0,04) au lieu de 0 : l'application raisonnait toujours sur la taille initiale.

## Cause

`remaining_size` (taille restante à gérer, portée par la ligne `trades`) était :

- initialisée à la taille à la création,
- décrémentée à chaque sortie partielle (`close()`),
- mais **jamais recalculée lors d'une édition de la taille** (`TradeService::update()`).

Le champ `size` (position) était bien mis à jour, mais `remaining_size` restait figée sur l'ancienne valeur. Comme les positions agrégées s'appuient sur `SUM(remaining_size)` (`PositionRepository`), tout le calcul de « ce qu'il reste à gérer » partait sur une valeur obsolète.

## Correctif

Dans `TradeService::update()`, lorsque `size` est éditée, on recalcule `remaining_size` en **préservant la quantité déjà sortie** :

```php
if (array_key_exists('size', $data)) {
    $oldSize = (float) $trade['size'];
    $oldRemaining = (float) $trade['remaining_size'];
    $alreadyExited = max(0.0, $oldSize - $oldRemaining);
    $newSize = (float) $data['size'];
    $tradeUpdates['remaining_size'] = max(0.0, $newSize - $alreadyExited);
}
```

- `alreadyExited` = ce qui a déjà été sorti (= ancienne taille − ancien restant).
- `remaining = nouvelle taille − déjà sorti`, **borné à 0** (jamais négatif si on réduit la taille en dessous de ce qui a déjà été sorti).

Cas du ticket (aucune sortie encore) : `alreadyExited = 0`, donc `remaining = 0,04`. ✅

`remaining_size` n'est touchée que si `size` est présente dans la requête : éditer un autre champ (notes, entry…) ne modifie pas le restant.

## Couverture des tests

| Test | Type | Scénario | Statut |
|---|---|---|---|
| `testUpdateSizeResyncsRemainingSizeWhenNoExitsYet` | Unit | 1 → 0,04 sans sortie ⇒ remaining 0,04 | ✅ |
| `testUpdateSizeResyncsRemainingSizeAccountingForPriorExits` | Unit | taille 2 (1 déjà sortie), 2 → 3 ⇒ remaining 2 | ✅ |
| `testUpdateSizeBelowAlreadyExitedClampsRemainingToZero` | Unit | taille réduite sous le déjà-sorti ⇒ remaining 0 | ✅ |
| `testUpdateWithoutSizeChangeLeavesRemainingUntouched` | Unit | édition d'un autre champ ⇒ pas de patch `remaining_size` | ✅ |
| `testUpdateSizeResyncsRemainingSize` | Integration | création → PUT size 0,04 → GET ⇒ size et remaining = 0,04 | ✅ |
| `testUpdateSizeKeepsAlreadyExitedQuantity` | Integration | taille 2, 1 sortie, PUT size 3 → GET ⇒ remaining 2 | ✅ |
