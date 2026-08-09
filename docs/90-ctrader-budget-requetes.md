# Étape 90 — Budget de requêtes cTrader : une session, un snapshot

## Pourquoi

Le 2026-08-07, FTMO a désactivé le compte de trading 7589848 « due to the amount
of activity », avec un seuil annoncé à **2 000 trades ou requêtes serveur par
jour**. Le compte n'est piloté par aucun EA : le seul automatisme branché dessus
est la synchro du journal, en **lecture seule**.

Le compte était donc désactivé par notre propre synchro. Le décompte le montre
sans ambiguïté :

| | Avant | Après |
|---|---|---|
| Requêtes par cycle de synchro | **19** | **9** |
| Cycles par jour (intervalle 15 min) | 96 | 96 |
| **Requêtes par jour et par connexion** | **~1 824** | **~864** |
| Seuil FTMO | 2 000 | 2 000 |

Mesuré sur une passe complète (`fetchDeals`, `fetchOpenPositions`,
`fetchOpenOrders`, `fetchClosedOrders`, `fetchBalance`) contre un faux serveur
qui compte ce qui part sur le fil. Sur le code d'avant ce lot, le total montait
encore d'environ deux requêtes de plus, `ProtoOASymbolByIdReq` étant réémis à
chaque résolution de symboles — soit ~21 par cycle, ~2 016 par jour, **au-dessus
du seuil**. Les synchros manuelles s'ajoutaient par-dessus.

**Réserve honnête** : les exemples de FTMO ne citent que des écritures (trades,
modifications de SL/TP, annulations). Il n'est **pas confirmé** qu'ils comptent
les lectures. La question leur a été posée le 2026-08-07 ; la réponse conditionne
le dimensionnement définitif. En attendant, diviser le volume par deux est une
mesure sans regret.

## Ce qui coûtait cher

Le connecteur ouvrait **une session WebSocket par question posée**. Or chaque
session commence par la même danse d'authentification en deux temps :

```
fetchDeals          → connect, AppAuth, AccountAuth, Reconcile, DealList, SymbolsList, SymbolById, close
fetchOpenPositions  → connect, AppAuth, AccountAuth, Reconcile, SymbolById, close
fetchOpenOrders     → connect, AppAuth, AccountAuth, Reconcile, SymbolById, close
fetchClosedOrders   → connect, AppAuth, AccountAuth, OrderList, close
fetchBalance        → connect, AppAuth, AccountAuth, TraderReq, AssetList, close
```

Sur 19 requêtes, **10 ne servaient qu'à re-prouver qui nous sommes** et **3
décrivaient le même instant** — les trois `ProtoOAReconcileReq` partent à
quelques secondes d'intervalle et renvoient la même photo.

## Ce qui a changé

### Une session par run

La session authentifiée est conservée pour la durée d'un run et réutilisée par
les cinq appels. Elle est ouverte à la première question et raccrochée à la fin.

**La réutilisation est opt-in**, et c'est le point important :
`resetSyncCache()` — que `BrokerSyncService` appelle déjà en début de run —
l'active, `closeSession()` la coupe. `placeOrder`, `cancelOrder` et
`closePosition` s'exécutent dans une requête HTTP (webhook TradingView) et non
dans un run : ils gardent leur session isolée, sinon chaque requête web
laisserait une socket ouverte derrière elle.

`BrokerSyncService` appelle `closeSession()` dans un `finally` : un run qui
explose en vol ne laisse pas de socket pendante — et le scheduler en exécute des
milliers par jour.

### Un snapshot par run

`ProtoOAReconcileReq` est mémoïsé par compte pour la durée du run.

**Il est toujours demandé avec `returnProtectionOrders: true`**, quel que soit
l'appelant qui arrive le premier. C'est une contrainte, pas une préférence :
sans ce drapeau, cTrader **écrase** un plan de sortie étagé dans le scalaire
`position.takeProfit` — une position à cinq TP n'en rapporte qu'un, et aucun
filtrage ne rattrape les autres puisqu'ils n'ont jamais été envoyés. Or
`fetchDeals` réconcilie **avant** `fetchOpenPositions` : si sa requête à lui
était celle mise en cache, on réintroduisait silencieusement le bug des TP
multiples corrigé la veille. Un test verrouille ce point précis.

`fetchOpenOrders` reçoit donc désormais les ordres de protection dans son
`order[]`. Il les écartait déjà sur `closingOrder` — ce filtre, antérieur au
partage, est ce qui rend le partage sûr.

### Les tailles de lot ne sont demandées qu'une fois

Les noms de symboles étaient déjà mémoïsés, pas les `lotSize` :
`ProtoOASymbolByIdReq` repartait aux trois résolutions du run. Le cache couvre
maintenant les deux, et seuls les identifiants jamais résolus sont demandés — y
compris les échecs, pour qu'un symbole sans réponse ne soit pas redemandé en
boucle.

## Ce que ça ne change pas

- **Le nombre de cycles par jour** reste piloté par
  `BROKER_SYNC_INTERVAL_MINUTES`. Passer l'intervalle à 60 depuis le BO admin
  divise encore le volume par quatre, sans déploiement.
- **La parallélisation** ([89](89-broker-sync-parallelisation.md)) n'y change
  rien non plus : elle rend les cycles simultanés, pas plus nombreux. Le bouton
  de synchro manuelle, lui, ajoute bien un cycle hors intervalle par clic.
- **Le journal n'écrit jamais sur cTrader** depuis la synchro. Aucun ordre,
  aucune modification de SL/TP : les types `Amend*` ne sont pas dans
  `CtraderConnector::PAYLOAD_TYPES` et `modifyOrder()` lève `NOT_IMPLEMENTED`.
  Le seul chemin d'écriture est le webhook TradingView, donc uniquement si un
  robot est armé.

## Fichiers touchés

| Fichier | Rôle |
|---|---|
| `api/src/Services/Broker/CtraderConnector.php` | Session partagée (`acquireSession`/`releaseSession`/`discardSession`/`closeSession`), `reconcile()` mémoïsé, cache des tailles de lot. |
| `api/src/Services/Broker/BrokerSyncService.php` | `closeSession()` dans le `finally` du run. |

## Tests

`CtraderConnectorTest` compte ce qui part réellement sur le fil, via un faux
client qui répond **par type de requête** plutôt que selon une séquence figée —
l'ordre des requêtes étant précisément ce qui est sous test, un script fixe
aurait dû être réécrit à chaque changement qu'il est censé détecter.

- une authentification par run au lieu de cinq ;
- un `Reconcile` au lieu de trois ;
- le `Reconcile` partagé demande bien les ordres de protection ;
- une résolution de symboles au lieu de trois ;
- un run complet reste sous les dix requêtes ;
- la réutilisation est bien opt-in — hors run, les cinq appels
  s'authentifient toujours chacun de leur côté ;
- un nouveau run ne réutilise ni la session ni le snapshot du précédent (une
  photo périmée rapporterait des positions déjà fermées).

Le stub compte aussi les raccrochages, parce que partager une session déplace la
responsabilité de fermer la socket et que rien d'autre ne l'aurait remarqué :

- la socket partagée est fermée une fois et une seule, par `closeSession()` —
  aucun des cinq appels ne la ferme en cours de route ;
- **une authentification refusée ne laisse pas la socket ouverte.** C'est le
  piège du partage : entre le `connect` et la fin de l'auth, la socket n'a pas
  encore de propriétaire — elle n'est pas la session du run, donc ni le `catch`
  de `fetchDeals` ni le `finally` de `BrokerSyncService` ne peuvent l'atteindre.
  Un token expiré, c'est-à-dire le mode de panne courant ici, la faisait fuir
  jusqu'à la fin du process. `acquireSession()` ferme donc explicitement sur
  échec d'auth.

## Suites possibles

Elles restent au backlog, non traitées ici :

- **Compter et journaliser les requêtes émises par run.** Il a fallu déduire
  notre volume en lisant le code ; on devrait pouvoir le chiffrer depuis les
  logs.
- **Interdire deux connexions ACTIVE sur le même compte broker.** Rien ne
  l'empêche aujourd'hui, et c'est un doublement du volume qui passe inaperçu.
- **Dériver l'intervalle d'un budget quotidien** plutôt que le fixer à l'aveugle.
