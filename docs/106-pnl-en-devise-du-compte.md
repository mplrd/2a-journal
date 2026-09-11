# 106 — Le P&L d'un trade est un montant, dans la devise du compte

> Évolution #24 de `docs/specs/trading-journal-evolutions.md`.
> Fait suite aux correctifs [104](104-cloture-synchro-plusieurs-fenetres.md) et
> [105](105-jambes-broker-non-recalculees.md), qui traitaient les symptômes de la
> même cause.

## Le problème

Deux sources écrivaient `trades.pnl`, dans deux unités différentes.

La saisie manuelle y mettait `(prix de sortie − prix d'entrée) × taille`, un écart
de prix brut — ni des points, ni de l'argent. Un stop DAX de 66 points sur un
solde de 0,5 lot y était stocké **−33**. Sur le forex c'est pire : 40 pips gagnés
sur 2 lots de GBPUSD donnaient **0,01**.

La synchro broker, elle, y mettait le montant annoncé par le broker, en devise,
commissions comprises.

`point_value` — ce que vaut un point de l'instrument sur ce compte — n'entrait
nulle part dans ce calcul, alors qu'il est au cœur du calcul de risque depuis
toujours (`SignalRiskCalculator` : `taille × sl_points × point_value`).

**Le reste de l'application lit déjà ce chiffre comme de l'argent** :

| Où | Ce qui en est fait |
|---|---|
| `AccountRepository` | `capital courant = capital initial + SUM(trades.pnl) + ajustements` |
| `DrawdownService::computeForAccount()` | compare `SUM(trades.pnl)` à `max_drawdown`, **un montant** |
| `SignalRiskCalculator` | divise un risque en devise par ce capital |

Conséquence concrète sur un DAX à 25 €/pt : six stops pleins affichaient **3,96 %**
de drawdown consommé au lieu de 99 %, et **l'alerte ne partait jamais**.

## Ce que fait ce correctif

### La formule, par jambe

Un trade se solde en plusieurs sorties — chaque TP partiel, le BE, le stop final.
`partial_exits` porte une ligne par sortie, chacune avec **sa** taille et **son**
P&L. `trades.pnl` n'est que leur somme, et `trades.remaining_size` — ce qui reste
ouvert — ne pèse rien tant qu'il n'est pas sorti.

```
partial_exits.pnl = (prix de sortie − prix d'entrée) × sens × taille de la jambe × valeur du point
trades.pnl        = Σ des jambes
```

Le stop qui a déclenché tout ça, sur une prop firm à 1 €/pt : `−66 × 0,5 × 1 = −33`,
inchangé. Chez un broker à 25 €/pt : `−66 × 0,5 × 25 = −825`, et c'est ce montant
que le capital et le drawdown lisaient déjà.

Le produit est commutatif : 25 lots à 1 €/pt et 1 lot à 25 €/pt donnent le même
montant. Aucun cas particulier à coder pour les prop firms.

### La valeur du point est figée à la prise du trade

Nouvelle colonne `positions.point_value` (`DECIMAL(10,5) NOT NULL DEFAULT 1`),
résolue **une seule fois** à la création de la position, jamais relue depuis les
réglages ensuite.

Modifier la valeur du point d'un actif ne déplace donc que les trades **suivants** :
le capital, le drawdown et le P&L déjà en place ne bougent jamais dans le dos du
trader. C'est la contrepartie assumée — corriger d'anciens trades demande un geste
explicite, il n'y a pas de rattrapage automatique.

Les cinq chemins qui créent une position la figent : saisie manuelle d'un trade,
placement d'un ordre, synchro des positions ouvertes, synchro des ordres en
attente, import de fichier.

### Les deux ratios reçoivent la même valeur du point

C'est le point le moins visible et le plus important. Deux colonnes divisent le
P&L par la taille :

- `risk_reward = pnl / (taille × sl_points)`
- `pnl_percent = pnl / (prix d'entrée × taille) × 100`

Multiplier le seul numérateur par `point_value` aurait multiplié **tous les R par
autant** — un 2R sur un DAX à 25 €/pt se serait affiché 50R. En portant
`point_value` **aussi aux deux dénominateurs**, il se simplifie :

```
R = (pts × taille × pv) / (taille × sl_pts × pv) = pts / sl_pts   inchangé
% = (pts × taille × pv) / (prix × taille × pv)   = pts / prix     inchangé
```

Les deux chiffres sortent **identiques à avant, au centime près**. Le changement
reste confiné à `pnl`, et le dénominateur du risque devient celui que
`SignalRiskCalculator` utilisait déjà — une formule de risque au lieu de deux.

Ce n'est pas qu'une question d'affichage : `StatsRepository` classe gagnant /
perdant / breakeven sur `pnl_percent` seul. Un facteur 25 dessus aurait reclassé
tous les breakeven.

### Un bug déjà présent, corrigé au passage

Sur un trade **synchronisé**, `pnl` était déjà en devise pendant que le
dénominateur du R restait en points. Le R et le `pnl_percent` de ces trades
étaient donc **déjà faux d'un facteur `point_value`** — un stop plein, un franc
−1R, s'affichait −25R sur un DAX à 25 €/pt. Invisible à 1 €/pt, le cas courant en
prop firm, ce qui explique que personne ne l'ait vu.

Le test `testTheRiskRewardOfASyncedTradeAccountsForThePointValue` le reproduisait
à −25 avant correctif.

**Portée réelle** : corrigé pour les positions créées à partir de maintenant, qui
portent leur vraie valeur du point. Les positions déjà en base restent à 1 (voir
plus bas), donc leurs ratios ne bougent pas — ils restent cohérents avec le P&L
tel qu'il est stocké, ce qui est le mieux qu'on puisse faire sans inventer de
données.

### Les jambes importées sont protégées comme les jambes broker

Le correctif [105](105-jambes-broker-non-recalculees.md) empêche `recalcRealizedMetrics()`
de réécrire une jambe portant un `external_id` — ce que le broker a écrit
appartient au broker.

Un **import CSV** pose le même problème sans cocher le même critère : il annonce
son P&L en devise, jambe par jambe, mais n'écrit aucun `external_id` dessus. La
même édition anodine qui ravageait un trade synchronisé aurait ravagé un trade
importé.

La règle devient donc : **une jambe n'est recalculable que si personne d'autre ne
l'a écrite** — pas d'`external_id` sur la jambe, et pas de lot d'import sur la
position (`positions.import_batch_id`, que la synchro broker pose également).

Contrepartie, assumée : une jambe saisie à la main sur une position synchronisée
ne suit plus une correction du prix d'entrée. Sur une telle position le prix
d'entrée appartient de toute façon au broker, qui le réécrit à chaque passe.

## L'historique n'est pas repris — migration 045

La migration ajoute **la colonne et rien d'autre**. Les positions existantes
restent à 1, donc leur P&L est rigoureusement inchangé, quelle que soit l'unité
dans laquelle il se trouve.

Ce n'était pas l'intention de départ. Une première version repeuplait
`point_value` depuis le réglage courant de chaque actif puis recalculait les
jambes. Essai à blanc sur un jeu de données réaliste, trois comptes :

| Compte | P&L cumulé | après reprise | conséquence |
|---|---|---|---|
| FTMO Challenge (100 k€, DD max 10 k€) | +1 030 | **+15 660** | objectif de gain franchi d'un coup |
| MFF Évaluation (50 k$, DD max 5 k$) | −330 | **−7 500** | drawdown consommé 6,6 % → **150 %** |

**Pourquoi** : `symbols.point_value` n'a jamais servi qu'au calcul de risque
(`SignalRiskCalculator`). Personne n'a jamais eu de raison de curer ces valeurs, et
`SymbolAccountSettingsRepository::autoMaterializeForUser()` matérialise les
réglages par compte en recopiant ce défaut — un réglage « explicite » est donc
indiscernable d'un défaut hérité. Un DAX à 25 €/pt sur un compte prop firm où le
point vaut réellement 1 n'est pas un cas tordu, c'est le cas courant.

Reprendre l'historique avec ces valeurs, ce n'est pas retrouver une donnée perdue :
c'est en inventer une, et détruire au passage la seule copie du P&L réel.

**Conséquence assumée** : l'historique garde son unité d'origine, les nouveaux
trades sont en devise. Les deux cohabitent dans les mêmes statistiques tant que
l'historique n'est pas repris — c'est-à-dire exactement la situation d'avant, ni
meilleure ni pire, mais qui cesse de s'aggraver.

Un repricing reste possible et souhaitable, mais il demande des valeurs de point
que l'utilisateur a **réellement validées**, actif par actif et compte par compte.
Donc un geste explicite dans l'interface, avec un aperçu de ce que ça déplace,
pas une migration silencieuse. C'est au backlog (`docs/evolutions.md`).

## Choix d'implémentation

**Un service dédié plutôt qu'une résolution en repository.** `PointValueResolver`
enveloppe `SymbolResolver` + `SymbolAccountSettingsRepository` avec la même
précédence que `SignalRiskCalculator` : le réglage par (actif, compte) d'abord, le
défaut de l'actif derrière. Injecter un service dans `PositionRepository` aurait
été plus court et aurait mis de la logique métier dans la couche d'accès aux
données.

**Toute impasse résout à 1, jamais à « inconnu ».** C'est la différence de nature
avec le calcul de risque, qui peut légitimement répondre « je ne sais pas » et
désactiver un plafond. Un trade a toujours un P&L. Actif supprimé, code inconnu,
valeur négative saisie à la main : la réponse est 1, ce qui reproduit exactement
l'arithmétique d'avant plutôt que d'aplatir le trade à zéro.

**Le résolveur est optionnel partout.** Comme les autres collaborateurs de
`TradeService`, il est nullable et vaut 1 quand il manque — un chemin non câblé se
dégrade au comportement précédent au lieu de casser.

**`size` reste la taille réelle chez le broker.** La tentation de saisir
`size = 1, point_value = 25` pour une position de 25 lots est arithmétiquement
équivalente, mais `BrokerOpenSyncService::updateBrokerFields` réécrit `size` depuis
le snapshot à chaque passe : la position redeviendrait 25 lots à 25 €/pt.

## Couverture des tests

| Test | Scénario | Statut |
|---|---|---|
| `PointValueResolverTest::testResolvePrefersThePerAccountSetting` | le réglage (actif, compte) l'emporte sur le défaut de l'actif | ✅ |
| `…::testResolveFallsBackToTheAssetDefault` | pas de réglage par compte → défaut de l'actif | ✅ |
| `…::testResolveReturnsOneWhenTheAssetIsUnknown` | actif introuvable → 1, sans lire les réglages | ✅ |
| `…::testResolveReturnsOneWhenTheStoredValueIsNotPositive` | valeur nulle en base (ligne éditée à la main) → 1 | ✅ |
| `…::testResolveReturnsOneWhenTheAccountSettingIsNotPositive` | réglage négatif → 1 | ✅ |
| `…::testResolveReturnsOneWhenTheSymbolIsBlank` | symbole vide → 1, sans résolution | ✅ |
| `TradeServiceTest::testCreateFreezesTheResolvedPointValueOnThePosition` | la création fige la valeur résolue | ✅ |
| `…::testCreateFreezesOneWhenNoResolverIsWired` | sans résolveur câblé → 1 | ✅ |
| `…::testClosePnlIsMultipliedByThePointValue` | stop DAX 66 pts × 0,5 × 25 → −825 | ✅ |
| `…::testCloseRiskRewardIsUnchangedByThePointValue` | −1R que le point vaille 1 ou 25 | ✅ |
| `…::testClosePnlPercentIsUnchangedByThePointValue` | pourcentage identique à 1 et à 25 | ✅ |
| `BrokerOpenSyncServiceTest::testInsertFreezesThePointValueOnTheNewPosition` | une position découverte par la synchro fige sa valeur | ✅ |
| `…::testTheRiskRewardOfASyncedTradeAccountsForThePointValue` | R d'un trade synchronisé : −1R, plus −25R | ✅ |

Suite complète : **1994 tests, 5068 assertions**, tous au vert.

## Ce que ça change à l'écran

**Rien pour un actif à 1 €/pt** — le cas courant en prop firm, et celui de tous
les comptes actuels. Tout pour le forex et les indices à 10 / 20 / 25 / 50, où le
chiffre affiché devient enfin un montant.

Le R et le taux de réussite ne bougent pas d'un centième, par construction.

Les points restent la langue du trader : leur affichage en second rang **sur le
trade** reste à faire, et n'est pas dans ce lot.

## Multi-devise

Pas de conversion FX. La devise vient du compte (`accounts.currency`), et les
visuels en argent héritent leur portée du filtre compte. Le R et le taux de
réussite restent les unités transverses.

Si une sélection multi-comptes multi-devises devait un jour être gérée, les
visuels concernés sont : equity/cumulé (une série par devise), calendrier, P&L par
symbole, cartes KPI (`total_pnl`, `best`/`worst`, et surtout `profit_factor`, ratio
de deux sommes qui ne survit pas au mélange), heatmap en P&L, le `total_pnl` des
agrégats par dimension, et les gains / pertes moyens et max du détail du camembert
([109](109-gains-pertes-moyens-et-max.md)). `WinLossChart` (ses comptes), la
distribution de R et les stats par session ne bougent pas.
