# 99 — L'actif ciblé par un plan de trading

> Lot 1 du chantier « plans ». Complète la doc [83 — Plans de trading](83-trading-plans.md).
>
> **Vocabulaire** — trois niveaux distincts, à ne pas confondre :
>
> | Mot | Exemple | Où il vit |
> |---|---|---|
> | **Actif** | le DAX | une ligne de `symbols` (menu *Mes actifs*) |
> | **Symbole** | `GER40`, `DE40.CASH` | `symbols.code`, `positions.symbol` |
> | **Ticker** | `EIGHTCAP:GER40` | broker + symbole ; c'est ce que `symbol_aliases` reconstitue avec `broker_template` + `broker_symbol` |
>
> Un actif est **désigné** par un symbole, qui varie d'un broker à l'autre — d'où
> la table d'alias. À l'écran : on **choisit un actif** (champ *Actif*, sélecteur
> sur *Mes actifs*), et les colonnes qui affichent `positions.symbol` s'appellent
> *Symbole*. Le lot 1 avait introduit « Instrument », et une première passe de ce
> chantier avait tout renommé « Actif », y compris les colonnes — deux erreurs,
> corrigées. L'écran *Mes actifs* intitulait sa colonne de code « Ticker » alors
> qu'elle contient un symbole : corrigé aussi.

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

### Migration 042 — une clé étrangère, pas une recopie

`trading_plans.symbol_id INT UNSIGNED NULL` + `FOREIGN KEY (symbol_id)
REFERENCES symbols(id) ON DELETE RESTRICT`, gardée par `INFORMATION_SCHEMA` pour
rester idempotente sur MariaDB comme sur MySQL.

> ⚠️ **La première version de cette migration posait `symbol VARCHAR(50)`**, en
> copiant le motif de `positions.symbol` et `symbol_aliases.journal_symbol`.
> C'était une faute : `symbols.code` est modifiable depuis *Mes actifs*, et rien
> ne propageait le changement — renommer `DE40.CASH` en `GER40` laissait le plan
> viser un code que plus rien ne portait, donc **ne matcher aucun signal, en
> silence**. `symbol_account_settings`, juste à côté, référençait pourtant déjà
> l'actif par `symbol_id` avec une FK. La migration a été réécrite avant tout
> merge : elle n'a jamais tourné ailleurs qu'en local.

`ON DELETE RESTRICT`, et pas `SET NULL` : `NULL` veut dire « tous les actifs »,
donc une suppression **élargirait** le filtre au lieu de le supprimer. Un
garde-fou ne doit jamais s'ouvrir tout seul. Les actifs étant supprimés en
douceur (`deleted_at`), la contrainte ne se déclenche qu'en suppression
définitive.

**`NULL` = tous les actifs**, ce qui est le comportement d'avant. Les plans déjà
en base ne changent donc pas de sens : personne ne voit son robot se mettre à
refuser des signaux du jour au lendemain.

### Le code de l'actif n'est jamais stocké sur le plan

`TradingPlanRepository` lit le plan avec une jointure sur `symbols` et expose
`symbol` = le code **courant** de l'actif. L'API continue donc d'accepter et de
renvoyer un code, le front n'a pas bougé, et les deux côtés de la comparaison de
`PlanEvaluator` viennent désormais de la **même ligne `symbols`** : ils ne
peuvent plus diverger.

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

- **Éditeur de plan** : le nom occupe la première ligne, et l'*Actif* ouvre la
  seconde, juste avant le *Sens autorisé* — les deux contraintes qui cadrent le
  plan avant qu'on parle de prix.
- **Le sélecteur ne propose pas « tous les actifs »**, et l'enregistrement est
  refusé tant qu'aucun n'est choisi. Un plan qui ne filtre aucun marché est
  précisément le trou que ce champ bouche ; le laisser proposer à l'écran
  reviendrait à le présenter comme un choix légitime.

## Le plan vise un actif, pas une chaîne de caractères

Première version du lot 1 : `checkSymbol()` comparait le symbole du signal au
`code` du plan en **égalité de chaînes**. Or un actif est désigné par un symbole
qui change d'un broker à l'autre. Une alerte envoyant `GER40` là où l'actif est
enregistré `DE40.CASH` était donc **rejetée** — un faux refus que le lot 1 avait
introduit, puisqu'aucun filtre d'actif n'existait avant lui.

Le même écart rendait le risque **non chiffrable** (`SignalRiskCalculator`
résolvait déjà par `findByUserAndCode()`), ce qui **désactivait les deux
plafonds de risque en silence**. Ce second défaut, lui, est antérieur au lot 1.

La table `symbol_aliases` existait exactement pour ça — `broker_template` +
`broker_symbol` → `journal_symbol` — mais n'était branchée **que sur l'import
CSV**.

### `SymbolResolver`

Un service dédié ramène ce qu'un signal appelle un instrument à l'actif de
l'utilisateur :

1. la chaîne telle qu'elle arrive, cherchée dans les actifs (`symbols.code`) ;
2. puis dans les alias, **tous templates confondus** — une alerte TradingView ne
   porte aucun tampon de broker, donc une recherche par template ne trouverait
   jamais les alias posés par l'import, ce qui les rendait inutiles hors import ;
3. puis, si la chaîne était un **ticker** (`EIGHTCAP:GER40`), la même recherche
   sur sa seule partie symbole.

La forme qualifiée est essayée **avant** sa forme courte : un utilisateur qui a
enregistré `EIGHTCAP:GER40` dans ses actifs l'a voulu ainsi.

Deux refus délibérés :

- **Alias ambigu** — le même symbole broker enregistré vers deux actifs
  différents renvoie `null`. Choisir l'un des deux ferait trader sous le mauvais
  instrument, en silence.
- **Alias orphelin** — un alias survit à l'actif supprimé. Renvoyer un code dont
  la ligne n'existe plus donnerait à l'appelant un symbole que rien ne peut
  valoriser.

### Où il est branché

- **`SignalRiskCalculator`** résout au lieu de chercher le code verbatim : les
  deux plafonds de risque redeviennent actifs quels que soient les symboles
  reçus, y compris pour les positions déjà en base.
- **`TradingViewWebhookService`** normalise le symbole du signal **avant**
  d'appeler l'évaluateur. `PlanEvaluator` reste **pur** : il compare toujours
  deux chaînes, mais les deux sont désormais le vocabulaire de l'utilisateur, ce
  qui rend aussi la raison de rejet lisible. Si rien ne résout, le symbole brut
  passe tel quel — « non couvert » est le bon verdict, et montrer exactement ce
  qui est arrivé en est la moitié actionnable.
- **`TradeService` / `OrderService`** font la même normalisation, pour qu'un
  trade enregistré par l'API sous un symbole broker ne soit pas marqué hors plan
  à tort.
- **Un bouton « + » crée un actif sans quitter le plan**, exactement comme sur le
  formulaire de trade (même `SymbolForm`, même store). S'apercevoir au milieu
  d'un plan que l'actif manque ne doit pas envoyer l'utilisateur sur un autre
  écran en perdant ce qu'il était en train de saisir. Le nouvel actif est
  sélectionné automatiquement.
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

**Unitaires** — `SymbolResolverTest` : un code déjà à soi se résout à lui-même ;
un symbole broker passe par son alias ; un ticker est coupé sur son préfixe ; la
forme qualifiée prime sur la forme courte ; espaces et chaîne vide tolérés ;
inconnu et alias orphelin renvoient `null`.

**Intégration** — `TradingViewWebhookFlowTest` : une alerte envoyant le symbole
de son broker matche le plan ; un ticker préfixé aussi ; un symbole
irrésoluble reste refusé et s'affiche tel qu'envoyé ; le plafond de risque
s'applique bien à un signal reçu sous le symbole du broker.

**Front** — `planForm.spec.js` : l'actif fait l'aller-retour formulaire ↔ API.

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
