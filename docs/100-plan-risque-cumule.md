# 100 — Le risque cumulé plafonné par plan

> Lot 4 du chantier « plans ». Complète la doc [83 — Plans de trading](83-trading-plans.md).

## Le manque

`max_risk_percent` plafonne le risque **d'un signal**. Un robot peut respecter
1 % par trade sur vingt entrées et engager 20 % du capital : le plafond par trade
ne dit rien de l'exposition totale, et rien d'autre ne la regardait.

C'est le point 4 remonté par un bêta-testeur, et il porte : la discipline « 1 %
par trade » est une règle de dimensionnement, pas une règle d'exposition. Les
deux se ressemblent assez pour qu'on croie tenir la seconde en appliquant la
première.

## La correction

Le plan porte un second plafond, `max_plan_risk_percent`, qui s'applique à la
somme du risque **de toutes les positions encore exposées sous ce plan, sur le
compte visé, signal entrant compris**.

### Migration 043

`trading_plans.max_plan_risk_percent DECIMAL(6,3) NULL`, même type que
`max_risk_percent`, gardée par `INFORMATION_SCHEMA`.

`NULL` = pas de plafond cumulé, ce qui est le comportement d'avant : les plans
déjà en base ne changent pas de sens.

### Par (plan, compte)

Un pourcentage n'a de sens que rapporté à un capital. Le cumul est donc calculé
**compte par compte** : une exposition prise ailleurs ne dit rien du compte
qu'on est en train d'engager. Deux comptes suivant le même plan ont chacun leur
enveloppe de 5 %, pas 2,5 % chacun.

### Ce qui compte comme « encore exposé »

`PositionRepository::findStillExposedByPlanAndAccount()` retient :

- les **trades** `OPEN` et `SECURED` ;
- les **ordres en attente** (`PENDING`).

Les ordres comptent, et c'est délibéré. Le chemin robot crée d'abord un ordre :
ne compter que les trades vivants rendrait le filtre aveugle **sur le chemin pour
lequel il existe**. Une rafale de signaux passerait en entier — chacun ne voyant
aucune exposition — et le compteur ne démarrerait qu'une fois les ordres exécutés,
trop tard pour en refuser un seul. Un ordre annulé ou expiré sort de lui-même du
calcul, son statut n'étant plus `PENDING`.

### Deux simplifications assumées

La taille retenue est **celle prise à l'entrée** :

- une **sortie partielle** ne réduit pas le risque compté ;
- un **stop remonté à BE** non plus (un trade `SECURED` compte à plein).

Les deux **sur-comptent**. Pour un garde-fou, c'est le bon sens de l'erreur : il
refuse au lieu de laisser passer. C'est l'inverse du défaut corrigé au [lot 1](99-plan-instrument-cible.md),
où l'absence d'instrument laissait passer.

### Le calcul

```
PlanEvaluator (pur)  ←  max_plan_risk_percent, riskPercent, openRiskPercent
        ↑
PlanOpenRiskCalculator  ←  PositionRepository + SignalRiskCalculator
```

`PlanEvaluator` reste sans I/O — c'est tout son intérêt : il compare des nombres.
Le nouveau `PlanOpenRiskCalculator` va chercher les positions et les additionne,
en passant chacune par le **même** `SignalRiskCalculator::computePercent()` que le
signal entrant. Le cumul et le signal sont donc mesurés à la même règle.

Le paramètre `$openRiskPercent` est le **dernier** de `evaluate()`, après `$now`
et non à côté de `$riskPercent`. C'est volontaire : les appelants qui n'ont rien
à sommer gardent leur appel à six arguments.

### L'ordre des filtres

```
checkSymbol → checkDirection → checkZones → checkWindows → checkRisk → checkCumulativeRisk
```

Le cumul est vérifié **en dernier**, après le plafond par trade. Quand les deux
sont dépassés, c'est le plafond par trade qu'on renvoie : c'est celui sur lequel
le trader peut agir tout de suite, en réduisant cette entrée-là.

### Quand le total n'est pas calculable

Si **une seule** position du plan a un risque non calculable (valeur du point non
configurée, capital inconnu, pas de SL), le total renvoyé est `null` et **le
filtre est ignoré**.

Écarter la position fautive de la somme aurait sous-compté, et un garde-fou qui
sous-compte laisse passer. « Je ne sais pas » est la réponse honnête, et la règle
déjà en vigueur pour le plafond par trade s'applique : on ne bloque jamais un
signal sur une lacune technique.

> ⚠️ Conséquence à connaître : **une position sans SL sous le plan désactive le
> plafond cumulé** tant qu'elle est ouverte, sans rien afficher. C'est la même
> limite que le plafond par trade, et elle mériterait un signalement à l'écran
> (versé à `docs/evolutions.md`).

### La raison renvoyée

> `plan risk 10.000% (open 5.000% + signal 5.000%) exceeds plan max 8.000%`

Les deux moitiés sont nommées. « 10 % dépasse 8 % » ne dirait pas au trader s'il
doit fermer une position ou réduire celle-ci.

## Effets

| Chemin | Effet |
|---|---|
| Robot (webhook) | Rejet `OUT_OF_PLAN`, aucun ordre envoyé au broker, raison dans l'événement d'audit |
| Saisie manuelle | Trade/ordre enregistré et marqué `OUT_OF_PLAN` — **jamais bloqué** |

Le cumul n'est calculé que pour les plans qui définissent le plafond : un plan
sans `max_plan_risk_percent` ne déclenche aucune requête supplémentaire.

### Le cas de la modification d'un trade

Réévaluer un trade déjà ouvert le ferait compter **deux fois** : une fois dans le
cumul des positions ouvertes, une fois comme signal entrant. `TradeService` passe
donc sa propre `position_id` en exclusion.

## À l'écran

- **Éditeur de plan** : *Risque max par trade* et *Risque max cumulé* côte à côte,
  chacun avec son infobulle d'aide.
- **Liste des plans** : une pastille `cumul ≤ 5 %` à côté de `≤ 1 %`.
- Le libellé « Aucun filtre » tient désormais compte de l'instrument et du cumul.
  Il ne regardait ni l'un ni l'autre, et un plan n'ayant qu'un instrument
  s'affichait « Aucun filtre » **à côté de la pastille de son instrument**.

## Tests

**Unitaires** — `PlanEvaluatorTest` : pas de plafond, en dessous, à la limite
exacte (atteignable), au-dessus ; le cas qui motive tout — un signal dans le
plafond par trade refusé par le cumul ; les deux moitiés nommées dans la raison ;
cumul ou signal non calculable ⇒ filtre ignoré ; appel à six arguments inchangé ;
plafond par trade prioritaire quand les deux sautent.

**Unitaires** — `PlanOpenRiskCalculatorTest` : rien d'ouvert ⇒ `0.0` et non
`null` (deux réponses différentes) ; somme ; une position non calculable rend le
total inconnu ; la position exclue ne compte pas ; chaque position est valorisée
sur son compte.

**Intégration** — `TradingViewWebhookFlowTest` : un signal seul ne déclenche
jamais le cumul ; une position ouverte sous le plan le fait dépasser et aucun
ordre n'est créé ; **un ordre en attente compte aussi** ; une position sur un
autre compte ne compte pas ; un plafond assez haut laisse passer.

`TradingPlanServiceTest` : persistance, champ optionnel, remise à vide, refus
d'une valeur nulle ou négative.

**Front** — `planForm.spec.js` : aller-retour formulaire ↔ API, y compris la
remise à vide (`undefined` → `null`, sinon la colonne ne serait pas effacée).

## Points d'attention

- `PlanEvaluator::evaluate()` prend maintenant **sept** paramètres. Le prochain
  ajout devrait passer par un objet de signal plutôt que par un huitième
  argument (versé à `docs/evolutions.md`).
- `TradeService`, `OrderService` et `TradingViewWebhookService` reçoivent
  `PlanOpenRiskCalculator` ; il est optionnel sur les deux premiers, comme les
  autres dépendances « plans », pour ne pas casser les contextes qui les
  construisent sans.
- Le plan de démo ne définit **pas** de plafond cumulé : les verdicts d'adhérence
  du seeder sont calculés à la main sur les seules zones, et poser un plafond
  rendrait ces verdicts faux.
