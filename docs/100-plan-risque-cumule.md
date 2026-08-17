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

### Ce qui compte comme « encore à risque »

« Encore à risque » est plus étroit que « encore ouvert », et toute la valeur du
filtre tient dans cet écart. `PositionRepository::findStillExposedByPlanAndAccount()`
retient :

| Position | Compte pour |
|---|---|
| Ordre `PENDING` | sa taille pleine |
| Trade `OPEN` | sa **taille restante** (`remaining_size`) |
| Trade `SECURED` (ou `be_reached`) | **rien** — il n'est plus retourné du tout |

**Les ordres en attente comptent**, et c'est délibéré. Le chemin robot crée
d'abord un ordre : ne compter que les trades vivants rendrait le filtre aveugle
**sur le chemin pour lequel il existe**. Une rafale de signaux passerait en
entier — chacun ne voyant aucune exposition — et le compteur ne démarrerait
qu'une fois les ordres exécutés, trop tard pour en refuser un seul. Un ordre
annulé ou expiré sort de lui-même du calcul.

**Un allègement libère de l'enveloppe.** Sortir la moitié d'une position divise
par deux ce qu'elle peut encore perdre ; elle ne doit donc plus peser que pour
la moitié. C'est `remaining_size`, pas la taille d'entrée.

**Un trade sécurisé ne pèse plus rien.** `SECURED` veut dire que le stop a été
remonté à l'entrée, et `TradeService` l'écrit noir sur blanc : *« the remainder
is risk-free »*. Le facturer à l'enveloppe reviendrait à **freiner un robot
précisément quand il a protégé tôt et que le marché lui donne raison** — soit
l'inverse du comportement à encourager. Un partiel en TP, lui, ne sécurise rien
(le stop reste à son niveau d'origine sur le reliquat) : le trade reste `OPEN` et
continue de compter, pour sa taille restante.

`be_reached` est vérifié en plus du statut. Les deux avancent ensemble
aujourd'hui (`markBeReached` promeut `OPEN` en `SECURED`), mais une synchro
broker qui poserait l'un sans l'autre ne doit pas ressusciter un risque qui
n'existe plus.

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

### Non mesurable ≠ sans borne

`PlanOpenRiskCalculator` a **trois** réponses possibles, et les confondre serait
le vrai piège :

| Réponse | Cas | Effet |
|---|---|---|
| un nombre | tout est mesurable | comparé au plafond |
| `INF` | une position **sans stop** | signal **refusé**, raison explicite |
| `null` | valeur du point non configurée, capital inconnu | filtre **ignoré** |

**Une position sans stop ne perd pas « une quantité inconnue », elle perd sans
borne.** La compter pour zéro sous-compterait ; désactiver le plafond en silence
laisserait l'utilisateur croire à une enveloppe qui ne tient plus. Les deux
reviennent à cacher le problème. On refuse donc, et la raison le dit :

> `an open position under the plan has no stop: plan risk unbounded`

C'est actionnable : poser un stop sur cette position, ou la fermer. Et ça ne
concerne que les plans qui ont **déclaré** une enveloppe : sans
`max_plan_risk_percent`, une position sans stop reste un problème, mais pas celui
de ce filtre.

Le cas `null` est d'une autre nature : rien ne dit que le risque est grand, on ne
sait simplement pas le **chiffrer**. La règle déjà en vigueur pour le plafond par
trade s'applique — on ne bloque jamais sur une lacune technique — et elle est ici
cohérente : le risque du signal entrant butera sur exactement le même mur
(même compte, donc même capital ; et depuis le [lot 1](99-plan-instrument-cible.md)
un plan vise un instrument, donc la même valeur du point), donc les deux
plafonds sont inertes ensemble, pas l'un sans l'autre.

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

### Ce que ça change pour un robot qui protège tôt

C'est le point qui a fait revoir la première version de ce lot. Un robot qui
remonte son stop à BE dès qu'il est en profit **libère son enveloppe** et peut
reprendre position. Un robot qui allège de moitié en libère la moitié. Compter la
taille d'entrée jusqu'à la clôture aurait puni la gestion de risque la plus
saine — celle qu'on veut voir — et bloqué le robot au moment exact où le marché
lui donnait raison.

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

**Unitaires** — `PlanOpenRiskCalculatorTest` : rien à risque ⇒ `0.0` et non
`null` (deux réponses différentes) ; somme ; position sans stop ⇒ `INF` ; stop à
zéro ⇒ `INF` ; position non chiffrable ⇒ `null` ; la position exclue ne compte
pas ; chaque position est valorisée sur son compte.

**Intégration** — `TradingViewWebhookFlowTest` : un signal seul ne déclenche
jamais le cumul ; une position ouverte sous le plan le fait dépasser et aucun
ordre n'est créé ; **un ordre en attente compte aussi** ; **un trade sécurisé ne
compte plus** ; **un trade allégé ne compte que pour son reliquat** ; une
position sans stop fait refuser le signal en le disant ; la même position est
sans effet si le plan n'a pas déclaré d'enveloppe ; une position sur un autre
compte ne compte pas ; un plafond assez haut laisse passer.

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
