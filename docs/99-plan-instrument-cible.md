# 99 — L'instrument ciblé par un plan de trading

> Lot 1 du chantier « plans ». Complète la doc [83 — Plans de trading](83-trading-plans.md).

## Le défaut

Un plan de trading filtre les signaux d'un robot : il décide si une entrée a le
droit d'être prise. Son filtre le plus utilisé est la **zone de prix**.

Or une zone était un couple de bornes nues — `direction`, `low_price`,
`high_price` — et **rien dans la chaîne ne nommait d'instrument** : ni `robots`,
ni `trading_plans`, ni `trading_plan_zones`. `PlanEvaluator` comparait donc le
prix d'un signal aux zones **sans jamais regarder le symbole**.

Conséquence : un robot reçoit sur son webhook les signaux de son indicateur, tous
marchés confondus. **Un signal portant sur un instrument que le plan n'a jamais
visé, dont le prix tombait par hasard dans une zone, franchissait le filtre et
partait au broker.** Pour un garde-fou dont le rôle est d'empêcher une entrée
hors cadre, l'erreur allait dans le mauvais sens : elle laissait passer.

La doc 83 annonçait pourtant le cas d'usage « un robot avec un plan *DAX* + un
plan *Nasdaq*, chaque signal matche son marché ». Ce n'était pas ce qui était
codé : ça ne tenait que tant que les deux instruments ne cotaient pas dans les
mêmes eaux.

## La correction

Le plan porte désormais l'instrument qu'il vise.

### Où l'instrument est posé

**Sur le plan, pas sur la zone.** Un plan vise un instrument et ses zones en
héritent — c'est le modèle mental « mon plan Nasdaq ». Un robot qui suit
plusieurs marchés attache plusieurs plans, ce qui est exactement le cas d'usage
que la doc 83 décrivait déjà.

### Migration 042 — additive et réversible de fait

`trading_plans.symbol VARCHAR(50) NULL` (même type que `positions.symbol`),
gardée par `INFORMATION_SCHEMA` pour rester idempotente sur MariaDB comme sur
MySQL.

**`NULL` = tous les instruments**, ce qui est le comportement d'avant. Les plans
déjà en base ne changent donc pas de sens : personne ne voit son robot se mettre
à refuser des signaux du jour au lendemain. L'utilisateur resserre son plan quand
il le décide.

### Le filtre

`PlanEvaluator::checkSymbol()` s'exécute **en tête** de la chaîne, avant le sens,
les zones, les fenêtres et le risque :

```php
evaluate() = checkSymbol() ?? checkDirection() ?? checkZones()
                            ?? checkWindows() ?? checkRisk()
```

Cet ordre n'est pas cosmétique. Les autres filtres ne veulent rien dire tant
qu'on ne sait pas si le plan parle de cet instrument, et quand il n'en parle pas,
**nommer l'instrument est la seule raison utile à renvoyer**. Un plan Nasdaq
confronté à un signal DAX répond « symbol DAX not covered (plan targets
NASDAQ) », et non une histoire de zone qui laisserait croire à un problème de
prix.

La comparaison ignore la casse et les espaces de bordure.

### Validation à l'écriture

`TradingPlanService` vérifie que le symbole **fait partie des actifs de
l'utilisateur** (`SymbolRepository::findByUserAndCode()`), et stocke la forme
canonique de l'actif.

Ce contrôle n'est pas du zèle : **une faute de frappe ferait rejeter la totalité
des signaux, en silence**. Le plan viserait un instrument qui n'arrive jamais, et
rien à l'écran ne l'expliquerait. Un symbole inconnu renvoie
`plan.error.invalid_symbol`.

C'est aussi ce qui garantit que le code stocké dans le plan est le même que celui
que le webhook reçoit, puisque le calcul du risque résout déjà le symbole du
signal par ce même `findByUserAndCode()`.

## Ce que ça change à l'écran

- **Éditeur de plan** : le nom occupe la première ligne, et l'*Instrument* ouvre
  la seconde, juste avant le *Sens autorisé* — les deux contraintes qui cadrent
  le plan avant qu'on parle de prix.
- **Le sélecteur ne propose pas « tous les instruments »**, et l'enregistrement
  est refusé tant qu'aucun n'est choisi. Un plan qui ne filtre aucun marché est
  précisément le trou que ce champ bouche ; le laisser proposer à l'écran
  reviendrait à le présenter comme un choix légitime. Les actifs viennent de
  *Mon compte › Mes actifs* ; s'il n'y en a aucun, la liste le dit et renvoie là-bas
  plutôt que de s'afficher vide.
- **Les textes d'aide passent en infobulle** (`FieldHelpIcon`) à côté du libellé,
  pour l'instrument, les zones de prix et le risque max. Ils restaient auparavant
  affichés en permanence sous chaque champ : trois paragraphes de rappel à lire à
  chaque ouverture, alors qu'on ne les consulte qu'une fois. L'infobulle porte
  aussi un `aria-label`, sinon l'aide disparaîtrait pour un lecteur d'écran et au
  doigt sur mobile.
- **Liste des plans** : l'instrument s'affiche en **premier** dans la colonne des
  filtres, avant les zones — c'est lui qui leur donne leur sens.

> L'API, elle, accepte toujours `symbol` à `NULL` : c'est ce qui laisse vivre les
> plans créés avant ce champ (voir *Points d'attention*). C'est l'éditeur qui
> refuse d'en fabriquer de nouveaux. Ouvrir un ancien plan demande donc de choisir
> son instrument avant de pouvoir le réenregistrer.

## Tests

**Unitaires** — `PlanEvaluatorTest` :

- un plan sans instrument s'applique à n'importe quel symbole ;
- un plan visant un instrument accepte celui-ci et refuse les autres ;
- **le cas qui motive le filtre** : symbole étranger refusé alors même que son
  prix tombe dans une zone ;
- comparaison insensible à la casse et aux espaces ;
- l'instrument est contrôlé avant tous les autres filtres.

**Intégration** — `TradingViewWebhookFlowTest` :

- un signal `EURUSD` à 1.1000 est **rejeté** par un plan visant `GBPUSD` dont la
  zone couvre pourtant 1.0900–1.1100, et **aucun ordre n'est créé** ;
- le même plan visant `EURUSD` laisse passer le signal.

`TradingPlanServiceTest` : stockage, forme canonique, refus d'un symbole inconnu
ou appartenant à un autre utilisateur, remise à « tous les instruments ».

**Front** — `planForm.spec.js` : l'instrument fait l'aller-retour formulaire ↔ API.

## Points d'attention

- La signature de `PlanEvaluator::evaluate()` prend un paramètre `$symbol` de
  plus. Les trois appelants — webhook TradingView, `TradeService`,
  `OrderService` — l'avaient déjà en main, aucun n'a eu besoin d'aller le
  chercher.
- `TradingPlanService` reçoit désormais `SymbolRepository` par son constructeur.
- Les plans existants restent à `NULL`. **Tant qu'ils ne sont pas précisés, le
  défaut décrit plus haut reste ouvert pour eux** — c'est le prix d'une migration
  qui ne modifie le comportement de personne sans son accord. À signaler aux
  utilisateurs qui exploitent des robots.
