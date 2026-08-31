# 105 — Éditer un trade synchronisé n'écrase plus le P&L du broker

## Le problème

Sur un trade venu d'une synchro broker, **ajouter une note suffisait à fausser son
P&L**. Le setup, les notes, les champs personnalisés et le risque (`sl_points`) ne
peuvent être saisis que côté journal — le broker fournit les chiffres et rien
d'autre. Éditer un trade synchronisé est donc le geste normal, pas l'exception :
le bug tombait à tous les coups.

Reproduit sur l'API locale, un `PUT /trades/{id}` portant **uniquement**
`{"notes": "..."}` sur un trade BingX à une jambe :

| | avant | après la note |
|---|---|---|
| `partial_exits.pnl` | 406,13 | **100,00** |
| `trades.pnl` | 406,13 | **100,00** |
| `pnl_percent` | 3,3844 | 0,8333 |
| `risk_reward` | 4,0613 | 1,0000 |

## La cause

`TradeService::recalcRealizedMetrics()` ré-dérive chaque sortie partielle depuis
le prix d'entrée du trade :

```php
$newPnl = ($partial['exit_price'] - $entryPrice) * $partial['size'] * $directionMultiplier;
if (abs($newPnl - (float) $partial['pnl']) > 0.001) {
    $this->partialExitRepo->updatePnl((int) $partial['id'], $newPnl);   // écriture en base
}
```

C'est légitime sur le chemin manuel : corriger un prix d'entrée doit propager aux
sorties déjà enregistrées. Mais la fonction est appelée **sans condition** —
« defensive recalc » — depuis `update()` et `markBeReached()`, donc à chaque
édition quelle qu'elle soit.

Or une jambe synchronisée porte le P&L **en devise** annoncé par le broker,
commissions comprises. La formule ci-dessus produit des **points bruts × lots**
(cf. `docs/09-trades.md:72`). Les deux unités ne se ressemblent pas : sur BingX,
`(60500 − 60000) × 0,2 = 100` remplaçait `406,13 €`. L'ancienne valeur était
écrasée en base, sans copie : la position est fermée, aucune synchro ultérieure
ne la ramène.

Personne n'avait restreint cette fonction au chemin manuel quand la synchro s'est
mise à écrire de la devise dans les mêmes colonnes.

## Le correctif

Une jambe qui porte un `external_id` a été écrite par la synchro : elle
appartient au broker, on ne la recalcule pas, on la somme telle quelle. C'est la
même règle de propriété que celle déjà en place pour les objectifs
(`BrokerTargetBuilder::isBrokerOwned`).

Les jambes saisies à la main continuent de suivre le prix d'entrée exactement
comme avant — le test `testUpdateEntryPriceRecomputesPartialAndTradePnl` le
verrouille et n'a pas bougé.

Second point, plus discret : ce qu'un broker compte **au niveau position**
au-delà de ses jambes (swap, commissions) est porté par le trade et par aucune
d'elles. Re-sommer les seules jambes l'effacerait. L'écart est donc mesuré sur
les jambes telles qu'elles étaient, puis reporté — et uniquement pour un trade
que la synchro a touché, pour qu'un trade saisi à la main reste ré-dérivé
intégralement. Même raisonnement que `BrokerOpenSyncService::realizedOnClose()`
(cf. [104](104-cloture-synchro-plusieurs-fenetres.md)).

## Vérification

Deux tests d'intégration dans `TradeFlowTest`, qui passent par le routeur et la
vraie base :

- `testAddingANoteToASyncedTradeLeavesItsBrokerLegAlone` — une note, et la jambe
  vaut toujours 406,13 ;
- `testEditingASyncedTradeKeepsWhatTheBrokerCountedAtPositionLevel` — un trade
  dont le total broker est inférieur de 2,00 à sa jambe garde ses 2,00 de frais
  après édition du setup.

Aucune reprise de données n'est possible ici, et c'est important à dire : les
trades déjà écrasés par une édition ont perdu leur montant broker sans trace. Ce
correctif empêche la casse à venir, il ne répare pas le passé. Si un trade
synchronisé affiche un P&L manifestement en points, la seule source restante est
la plateforme du broker.
