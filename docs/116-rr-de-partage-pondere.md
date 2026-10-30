# 116 — R/R du partage pondéré par les quantités

## Contexte (ticket #41)

Le texte de partage d'une position (ordre / trade ouvert) affiche une ligne `⚖️ R/R`.
Jusqu'ici elle valait `targets[0].points / sl_points` : le **reward du premier objectif
seulement**, divisé par la distance du SL.

Pour un plan en sorties multiples (scale-out), ce chiffre est trompeur : il ignore les
TP suivants et les quantités réparties sur chaque TP. Exemple remonté par l'utilisateur —
un SELL GER40 avec SL à 567 pts et des objectifs jusqu'à +5713 pts affichait `R/R: 0.71`
(400 / 567), parce que seul le TP le plus proche était pris en compte.

## Fonctionnalité

La ligne `R/R` du partage est désormais le **R-multiple moyen de l'échelle d'objectifs,
pondéré par la quantité sortie à chaque TP** :

```
R/R = Σ(points_i × size_i) / Σ(size_i) / sl_points
```

- Chaque objectif pèse selon la quantité qu'on y sort : un TP sur lequel on sort une grosse
  part compte davantage qu'un TP lointain sur une petite part.
- **Un seul objectif** ⇒ `points / sl_points` : identique au comportement précédent, aucune
  régression sur les partages mono-TP.
- **Pas de SL** (`sl_points ≤ 0`) ⇒ la ligne R/R est **omise** (plutôt qu'une division par zéro).
- **Repli** : si aucun objectif ne porte de quantité exploitable (jamais observé en base —
  le formulaire impose une quantité par objectif), on retombe sur la **moyenne simple** des
  distances : `Σ(points_i) / n / sl_points`.

### Exemple (le trade du ticket #41)

| Objectif | points | quantité | points × quantité |
|----------|-------:|---------:|------------------:|
| TP1      |    400 |     0,08 |             32,00 |
| TP2      |    910 |     0,05 |             45,50 |
| TP3      |   1713 |     0,05 |             85,65 |
| TP4      |   5713 |     0,02 |            114,26 |
| **Total**|        | **0,20** |        **277,41** |

`R/R = 277,41 / 0,20 / 567 = 1387,05 / 567 = 2,4462962963`

→ au lieu de `0,71` (TP1 seul) avec l'ancienne formule.

## Choix d'implémentation

- **Le BE n'entre pas dans le calcul** : c'est une réduction de risque (on remonte le SL),
  pas un objectif de gain. L'inclure comme jambe à 0 reward fausserait le R planifié.
- **Dénominateur = `sl_points` seul** (forme « R moyen »), pas `sl_points × taille totale`.
  Quand les objectifs couvrent toute la taille de la position — le cas normal — les deux
  formes sont identiques ; la forme moyenne reste lisible quand ce n'est pas le cas et ne
  dépend pas d'un reliquat non ciblé.
- **Front et back strictement alignés** : le texte est généré côté front (aperçu en direct
  dans le formulaire) **et** côté back (endpoints de partage). Les deux calculent la même
  valeur au même format (`number_format(.,10)` tronqué / `toFixed(10)`), afin que l'aperçu
  et le partage effectif ne divergent jamais.
  - Back : `ShareService::calculateRiskReward()` (`api/src/Services/ShareService.php`)
  - Front : `weightedRiskReward()` (`frontend/src/composables/useSharePreview.js`)
- **Le R/R réalisé des trades clôturés (`trades.risk_reward`) n'est pas touché** : il agrège
  déjà le P&L réel de toutes les sorties partielles rapporté au risque initial. Seule la
  ligne R/R du **plan** (ordre / trade ouvert) change.

## Couverture des tests

### Backend — `api/tests/Unit/Services/ShareServiceTest.php`

| Test | Scénario | Attendu |
|------|----------|---------|
| `testGenerateTextForOrderWithMultipleTargets` | 2 TP (0,5 / 0,5), SL 50 | `R/R: 1.6` (pondéré) |
| `testRiskRewardIsSizeWeightedAcrossTheWholeLadder` | 4 TP du ticket #41, SL 567 | `R/R: 2.4462962963` |
| `testRiskRewardFallsBackToUnweightedMeanWhenTargetsHaveNoSize` | 2 TP sans quantité | `R/R: 2` (moyenne simple) |
| `testRiskRewardOmittedWhenNoStopLoss` | `sl_points = 0` | pas de ligne R/R |
| `testGenerateTextForBuyOrder` / `...SellOrder` | 1 seul TP | `R/R` inchangé (2.2 / 2) |

### Frontend — `frontend/src/__tests__/useSharePreview.spec.js`

| Test | Scénario | Attendu |
|------|----------|---------|
| mono-TP | 1 TP, SL 50 | `R/R: 2.2` (pas de régression) |
| échelle pondérée | 4 TP du ticket #41 | `R/R: 2.4462962963`, et **plus** `0.7054673721` |
| repli sans quantité | 2 TP sans size | `R/R: 2` |
| sans SL | `sl_points = 0` | pas de ligne R/R |
| sans objectif | `targets = []` | pas de ligne R/R |

Suites : `ShareServiceTest` 23/23 ✅ · `useSharePreview.spec.js` 5/5 ✅ · suite front complète 657 ✅.
