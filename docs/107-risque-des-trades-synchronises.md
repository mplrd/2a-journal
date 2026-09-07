# 107 — Le R:R d'un trade synchronisé

> Fait suite à [106](106-pnl-en-devise-du-compte.md), qui a mis le P&L en devise.
> Le numérateur du R était bon depuis ce matin ; il lui manquait un dénominateur.

## Le problème

Tous les calculs de R:R du journal divisent par la même chose :

```
risk_reward = pnl / (taille × sl_points × valeur du point)
```

— `TradeService::recalcRealizedMetrics()`, `BrokerOpenSyncService::bankRealizedFromExits()`
et `computeRealizedFromBroker()`. Et **aucun chemin de synchronisation n'a jamais
écrit `sl_points`**. Ni `insertNewOpen()`, ni `updateBrokerFields()`, et l'import
des deals clôturés le pose explicitement à `null` (`ImportService:210`).

Donc `sl_points` reste NULL, le diviseur vaut 0, et les trois calculs retombent
sur la même branche :

```php
'risk_reward' => $riskAmount > 0 ? round($realized / $riskAmount, 4) : null,
```

**Constaté en production le 2026-09-07 : 234 positions cTrader, 234 `risk_reward`
à NULL.** Le R:R moyen du tableau de bord (`AVG(t.risk_reward)`) et la
distribution des R (`WHERE t.risk_reward IS NOT NULL`, `StatsRepository:463`)
n'avaient rigoureusement rien à afficher.

Le symptôme était invisible ailleurs : `pnl` et `pnl_percent` étaient corrects,
le classement gagnant / perdant aussi (`isWin()` retombe sur `t.pnl` quand le
pourcentage manque). Seul le R manquait, et il n'y a aucun affichage de R:R
**par trade** — la clé `trades.risk_reward` existe dans `fr.json` mais n'est
utilisée nulle part — donc rien ne signalait l'absence ligne à ligne.

## Ce que fait ce correctif

Le broker annonce le stop comme un **prix**. La distance à l'entrée, c'est le
risque pris :

```
sl_points = |prix d'entrée − prix du stop|
```

Écrit **une fois**, jamais réécrit — même contrat que `point_value` dans
[106](106-pnl-en-devise-du-compte.md).

### Pourquoi une seule fois, et pas à chaque passe

`sl_price` est le stop **courant**, et le broker le déplace : au BE, puis en
suivi. Le recalculer à chaque tour rétrécirait le diviseur à mesure que le trade
va bien, et ferait exploser le R au moment précis où le stop atteint l'entrée.

La règle est donc : **on remplit un risque absent, on n'écrase jamais un risque
présent.** Ça protège aussi celui que l'utilisateur a tapé à la main — sur un
trade synchronisé, c'est le seul chiffre qui lui appartienne.

### Trois impasses qui répondent NULL, pas zéro

| Cas | Réponse | Pourquoi |
|---|---|---|
| Le broker n'annonce aucun stop | NULL | Trader sans stop dur est un choix légitime |
| Le stop est **sur** l'entrée | NULL | Risque manqué, pas risque nul |
| Le stop est **au-delà** de l'entrée | NULL | Position déjà sécurisée : la distance est celle qu'on a emportée, pas celle qu'on a risquée |

Les deux derniers cas sont testés par `stopProtectsEntry()`, la même fonction qui
promeut un trade en SECURED. Une position vue pour la première fois alors que son
stop a déjà bougé est une position vue **trop tard** : enregistrer sa distance
courante sous-estimerait le risque d'autant que le trade a bien marché, et
distribuerait un R flatteur gratuitement.

Un diviseur inventé produit un R que rien, en aval, ne saurait distinguer d'un
vrai. NULL est ce qui dit « on ne sait pas ».

### Les deux chemins qui remplissent

**À la découverte** (`insertNewOpen`) : la valeur est figée sur la position, et
passée à `bankRealizedFromExits()` dans la foulée — une position découverte avec
des sorties partielles déjà encaissées obtient son R tout de suite, sans attendre
que quelqu'un saisisse quoi que ce soit.

**À la mise à jour** (`updateBrokerFields`) : si la ligne ne porte pas de risque
et que le snapshot annonce un stop, on le pose. C'est ce qui rattrape les
positions arrivées par l'import des deals clôturés, qui les crée à `sl_points`
NULL par construction, et celles découvertes avant que leur stop soit posé.

Mesuré contre le prix d'entrée **du snapshot**, celui que la même passe est en
train d'écrire, pas contre celui déjà en base.

## Ce qui n'est pas fait

**Les 234 trades d'historique gardent leur R à NULL.** Leur stop n'est plus
récupérable depuis le snapshot : ils sont clos, et `normalizeCtraderDeal()` — le
deal de clôture — ne porte aucun champ de stop.

La suite existe, et elle est identifiée : `ProtoOAOrder.stopLoss` (champ 15,
« Absolute stopLoss price ») porte le stop **d'origine** sur l'ordre d'ouverture,
et `ProtoOADeal.orderId` (champ 2, « Source order of the deal ») fait le lien.
Mieux : `CtraderConnector::fetchClosedOrders()` appelle **déjà**
`ProtoOAOrderListReq` sur la même fenêtre, et `normalizeCtraderClosedOrder()`
(`DealNormalizer:346`) jette tout sauf `external_id` et `final_status`. Le stop
initial passe déjà dans nos mains à chaque synchro.

**Deux hypothèses restent à constater sur une vraie réponse cTrader** avant de
bâtir dessus :

1. que l'ordre d'ouverture porte bien `positionId` ;
2. que son `stopLoss` reste celui de la prise de position quand le trader déplace
   son stop ensuite — si cTrader amende cet ordre au lieu de créer un ordre de
   protection distinct, on retombe sur le problème du BE et la route ne vaut rien.

C'est au backlog (`docs/evolutions.md`).

## Portée réelle

Ce correctif ne vaut que pour les positions que la synchro voit **ouvertes, avec
un stop qui porte encore du risque**. Concrètement :

- une position ouverte et passée au BE entre deux passes de synchro perd son
  risque initial, définitivement ;
- une position que le journal n'a jamais vue ouverte — import d'historique pur —
  n'a rien à en tirer.

La fréquence de synchro est donc ce qui détermine le rendement du correctif.

## Sur la valeur du point, une hypothèse démentie

En instruisant ce bug, l'hypothèse de départ était que `point_value = 1` sur les
positions cTrader était un défaut hérité, et que les vraies valeurs étaient 20
(NASDAQ) et 25 (DAX) — les réglages portés par les lignes `US100.CASH` et
`DE40.CASH`. Un R rempli sans corriger ça serait sorti faux d'un facteur 20 ou 25.

La valeur du point est **mesurable** sur un trade synchronisé, sans rien supposer :
le montant encaissé, la taille et les deux prix sont connus, et
`montant = points × taille × valeur du point` n'a qu'une inconnue.

```sql
SELECT p.symbol,
       AVG(pe.pnl / NULLIF((pe.exit_price - p.entry_price)
           * (CASE WHEN p.direction='BUY' THEN 1 ELSE -1 END) * pe.size, 0))
FROM partial_exits pe
JOIN trades t ON t.id = pe.trade_id
JOIN positions p ON p.id = t.position_id
WHERE p.external_id LIKE 'ctrader_%' AND pe.exit_price <> p.entry_price
GROUP BY p.symbol;
```

Mesure : **0,98 sur `US100.cash`, 1,15 sur `GER40.cash`**. Les deux tombent sur 1,
et l'utilisateur l'a confirmé indépendamment — il trade à 1 € / 1 $ du point. Les
lignes réglées à 20 et 25 appartiennent à d'autres comptes que la synchro cTrader
n'utilise pas.

`point_value = 1` sur ces positions est donc **juste**, pas un défaut hérité. Rien
à reprendre, aucun `pnl_percent` déplacé, aucun trade reclassé — et le
`point_value` figé par [106](106-pnl-en-devise-du-compte.md) est le bon.

La dispersion de la mesure (−6,46 à 33,62 sur le DAX) ne dit rien contre ça :
quand une jambe sort à deux points de l'entrée le dénominateur est minuscule et le
rapport explose, et les commissions sont dans le `pnl` sans être dans la formule.

**La leçon de méthode** : la valeur du point se mesure sur les données existantes
au lieu de se déduire des réglages. C'est aussi la réponse à la question laissée
ouverte par [106](106-pnl-en-devise-du-compte.md) sur le repricing de
l'historique — le repricing assisté devrait proposer la valeur mesurée, pas celle
déclarée.

## Couverture des tests

| Test | Scénario | Statut |
|---|---|---|
| `testInsertDerivesTheRiskInPointsFromTheBrokersStop` | entrée 60000, stop 59000 → 1000 points | ✅ |
| `…DerivesTheRiskOfAShortFromItsStopAbove` | short, stop au-dessus → distance absolue | ✅ |
| `…RecordsNoRiskWhenTheBrokerReportsNoStop` | pas de stop → NULL | ✅ |
| `…RecordsNoRiskWhenTheStopIsAlreadyAtTheEntry` | stop sur l'entrée → NULL | ✅ |
| `…RecordsNoRiskWhenTheStopHasAlreadyLockedInProfit` | stop au-delà de l'entrée → NULL | ✅ |
| `testUpdateFillsTheRiskWhenTheRowStillHasNone` | ligne sans risque + stop annoncé → rempli | ✅ |
| `testUpdateNeverOverwritesARiskAlreadyOnFile` | stop remonté au BE → risque intact | ✅ |
| `testTheDerivedRiskIsWhatFinallyGivesASyncedTradeAnR` | bout en bout : stop plein → −1R | ✅ |

Deux fixtures existantes ont été reprises : la liste blanche des champs pilotés
par le broker (`testUpdatesBrokerFieldsOfExistingOpenPositionPreservingMeta`)
accueille `sl_points`, et `testDoesNotRewriteTheTradeWhenTheRealizedTotalHasNotMoved`
porte désormais un risque et un R cohérents, sans quoi la passe avait de nouveau
quelque chose à écrire — ce que le test interdit à raison.

Suite complète : **2002 tests, 5081 assertions**, tous au vert.

## Ce que ça change à l'écran

Le R:R moyen du tableau de bord et la distribution des R cessent d'être vides,
au fur et à mesure que de nouvelles positions sont découvertes avec leur stop.
Rien de rétroactif : l'historique attend le lot suivant.

Aucune migration — `positions.sl_points` existe depuis toujours, il n'était
simplement jamais rempli de ce côté.
