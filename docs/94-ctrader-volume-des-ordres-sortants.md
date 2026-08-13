# Étape 94 — Le volume des ordres sortants cTrader

## Résumé

`placeOrder()` et la clôture partielle de `closePosition()` convertissaient une taille en lots par un `× 100` en dur. La conversion s'appuie désormais sur le **`lotSize` réel du symbole**, exactement l'inverse du chemin de lecture. Les deux sens du connecteur parlent enfin la même langue.

## Le défaut

Le chemin de **lecture** a été corrigé le 2026-08-06 : `DealNormalizer::ctraderVolumeToLots()` calcule `volume / lotSize`, la valeur exacte du symbole. Le chemin d'**écriture**, lui, faisait toujours :

```php
$volume = (int) floor(((float) $order['size']) * 100);
```

`ProtoOADeal.volume`, `ProtoOATradeData.volume` et `ProtoOASymbol.lotSize` sont tous exprimés en **centièmes de l'unité de base**. Un lot vaut donc `lotSize` de ces centièmes, et cette valeur dépend de l'instrument :

| Instrument | `lotSize` | 0,10 lot vaut | Ce qu'on envoyait |
|---|---|---|---|
| Indice CFD (GER40, US100) | 100 | 10 | 10 ✅ |
| Paire FX (EURUSD) | 10 000 000 | 1 000 000 | 10 ❌ |

Le `× 100` n'est juste que lorsqu'un lot vaut une unité — ce qui est le cas des indices CFD **par coïncidence**. C'est pourquoi le défaut n'est jamais apparu : les comptes en service ne tradent que des indices. Sur une paire FX, l'ordre partait **cent mille fois trop petit**, et se faisait rejeter sous le volume minimum du broker.

Le risque était donc le refus, pas l'exécution erronée. Mais un connecteur dont l'aller et le retour ne s'accordent pas est une bombe à retardement, et ces méthodes n'attendent que l'activation des robots pour servir.

## La correction

```php
volume = lots × lotSize        (inverse exact de la lecture)
volume = lots × 100            (repli, quand le lotSize est introuvable)
```

### Résoudre le `lotSize`

`ProtoOASymbolsListReq` ne renvoie que des `ProtoOALightSymbol` : l'identifiant et le nom, **pas** la taille de lot. Il faut donc un `ProtoOASymbolByIdReq` séparé. Le résultat est mémoïsé dans le cache que le chemin de synchro remplit déjà, donc un run ayant déjà résolu le symbole ne paie rien.

Pour une **clôture partielle**, la requête ne nomme pas un symbole mais une position. Le snapshot `ProtoOAReconcileReq` est le seul à dire sur quel symbole elle porte : on y lit `position[].tradeData.symbolId`, puis le `lotSize`. Une **clôture totale** n'envoie aucun volume (`0` = tout fermer côté cTrader), donc elle ne déclenche aucune de ces requêtes.

### Le repli, et pourquoi il est journalisé

Quand le `lotSize` reste introuvable, on retombe sur `× 100` — le même repli que le chemin de lecture, et le sens documenté du champ. Il ne peut que **sous-dimensionner** un ordre, jamais le sur-dimensionner : le broker le rejette au lieu d'exécuter quelque chose d'involontaire.

Il émet néanmoins une ligne `lot_size_unresolved` sur stderr. Un repli silencieux sur un ordre **sortant** est précisément ce qu'on ne veut pas découvrir après coup. Deux tests vérifient que la ligne part.

Le repli couvre aussi le cas d'une position absente du snapshot : elle a pu se clôturer entre la décision et l'appel. Refuser net serait pire que d'envoyer un volume dont cTrader jugera lui-même.

## Coût en requêtes

Un `placeOrder` passe de 4 à 5 requêtes, une clôture partielle de 3 à 5. Sans effet sur le budget de l'évolution #22 : ces méthodes ne servent qu'aux ordres sortants (robots TradingView), qui sont des actions ponctuelles, pas un cycle périodique. La synchronisation, elle, ne change pas.

## Tests

`tests/Unit/Services/Broker/CtraderConnectorTest.php` — 4 tests ajoutés, 4 mis à jour :

| Cas | Attendu |
|---|---|
| 0,10 lot EURUSD (`lotSize` 10 000 000) | volume **1 000 000** — c'était 10 |
| 1,5 lot GER40 (`lotSize` 100) | volume **150**, inchangé : le cas qui marchait par coïncidence doit continuer |
| `lotSize` introuvable | repli `× 100` **et** ligne `lot_size_unresolved` |
| Clôture partielle 0,5 lot | `lotSize` résolu via la position, volume **5 000 000** |
| Position hors snapshot | repli + ligne de log |
| Clôture totale | **aucune** requête supplémentaire |

Suites complètes : **1097 unitaires**, **743 intégration**.

## Reste ouvert

`modifyOrder()` lève toujours `NOT_IMPLEMENTED` — aucune conversion de volume n'y est en jeu aujourd'hui. Si elle est implémentée un jour, elle devra passer par `lotsToVolume()`.
