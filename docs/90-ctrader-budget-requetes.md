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

## Les garde-fous

Diviser le volume par deux ne sert à rien si personne ne s'aperçoit qu'il
remonte. Deux garde-fous accompagnent donc la réduction.

### Chaque run dit ce qu'il a dépensé

Pour chiffrer notre volume au moment de l'incident, il a fallu lire le
connecteur ligne à ligne. C'est le vrai défaut : **un budget que personne ne
peut mesurer est un budget que personne ne voit se faire dépasser.**

`CtraderConnector` compte désormais ce qui part, dans `sendAndReceive()` — le
point de passage unique, pour que le compte ne puisse pas diverger de la réalité
à mesure que le connecteur gagne des appels. `BrokerSyncService` lit le total en
fin de run et le journalise :

```json
{"job":"ctrader","event":"sync_request_budget","connection_id":42,"requests":9,
 "by_type":{"ProtoOAApplicationAuthReq":1,"ProtoOAAccountAuthReq":1,
            "ProtoOAReconcileReq":1,"ProtoOADealListReq":1, ...}}
```

Trois choix à noter :

- **Les types sont nommés, pas numérotés.** Une ligne qui dit
  `ProtoOAReconcileReq` est exploitable ; une qui dit `2124` demande une table de
  correspondance.
- **La ligne est émise même quand le run échoue** — elle est dans le `finally`.
  Un run qui plante a quand même dépensé ses requêtes, et une boucle d'échecs est
  précisément la façon dont un quota se consomme sans qu'on le voie venir.
- **Un connecteur qui ne compte pas n'émet rien.** Une ligne « 0 requête » pour
  MetaApi se lirait comme « cette synchro n'a rien coûté », soit l'inverse du
  vrai. Seul cTrader compte aujourd'hui.

### Un compte broker, une connexion

Rien n'empêchait de relier deux comptes du journal au même compte cTrader. Les
deux connexions se synchronisent alors chacune sur son propre rythme et
**doublent le volume**, sans que rien ne le signale : chaque connexion, prise
isolément, est en parfaite santé.

`BrokerConnectionService` refuse maintenant la création ou la reconfiguration
qui pointerait sur un compte broker déjà relié. Ce que ça implique :

- **Pas de contrainte SQL possible.** L'identifiant du compte broker vit dans le
  blob `credentials_encrypted` — hors de portée d'un `UNIQUE KEY`. Le contrôle
  est donc applicatif, par déchiffrement des connexions de l'utilisateur. Le
  balayage est borné : un utilisateur a une poignée de connexions.
- **Chaque provider a son identifiant**, déclaré par un flag `identity` dans
  `BrokerCredentialMapper::SPEC` : `ctid_trader_account_id` pour cTrader,
  `metaapi_account_id` pour MetaApi. Ouinex et BingX n'ont pas de numéro de
  compte — la clé d'API *est* le compte, c'est donc elle qui l'identifie.
- **Les connexions `REVOKED` sont ignorées.** Elles ne synchronisent jamais, donc
  ne dépensent rien ; les compter empêcherait l'utilisateur de reconnecter un
  compte qu'il vient de déconnecter.
- **Une connexion ne se bloque pas elle-même.** Le piège évident : une
  reconfiguration qui ne change que l'access token trouverait « une autre »
  connexion sur ce compte broker — elle-même — et rejetterait toute
  reconfiguration. D'où l'exclusion explicite, et le test qui la tient.

**Limite assumée** : le contrôle est **par utilisateur**. Deux utilisateurs
différents branchés sur le même compte broker ne sont pas détectés — il faudrait
déchiffrer les connexions de toute la base à chaque création, ce qui est hors de
proportion avec le cas visé, à savoir la duplication accidentelle par un même
utilisateur.

## Fichiers touchés

| Fichier | Rôle |
|---|---|
| `api/src/Services/Broker/CtraderConnector.php` | Session partagée (`acquireSession`/`releaseSession`/`discardSession`/`closeSession`), `reconcile()` mémoïsé, cache des tailles de lot, comptage des requêtes (`getRequestCounts()`). |
| `api/src/Services/Broker/BrokerSyncService.php` | `closeSession()` et journalisation du budget dans le `finally` du run. |
| `api/src/Services/Broker/BrokerLogger.php` | `event()` à côté de `failure()` — un run nominal n'a pas à s'annoncer comme un échec. |
| `api/src/Services/Broker/BrokerConnectionService.php` | `assertBrokerAccountFree()` sur la création et la reconfiguration. |
| `api/src/Services/Broker/BrokerCredentialMapper.php` | Flag `identity` par provider + `brokerAccountIdentity()`. |
| `frontend/src/locales/{fr,en}.json` | `broker.error.broker_account_already_connected`. |

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

Les garde-fous ont leurs propres tests :

- un run rapporte ce qu'il a dépensé, total et détail par type nommé ;
- le compteur repart de zéro à chaque run — un compteur cumulatif rapporterait
  le total du process, pas le coût par run dans lequel le budget s'exprime ;
- `BrokerSyncService` journalise le budget contre la bonne `connection_id`, et
  n'émet rien pour un connecteur qui ne compte pas ;
- deux connexions sur le même compte broker sont refusées, à la création comme à
  la reconfiguration ; un compte broker différent passe ; une connexion `REVOKED`
  ne bloque pas ; une connexion garde le droit de se reconfigurer elle-même.

Note de méthode sur la capture des logs : `error_log()` **préfixe chaque ligne
d'un horodatage quand il écrit dans un fichier**, préfixe qui n'existe pas quand
la même ligne part vers stderr — c'est-à-dire en production. Le helper de test le
retire, sinon chaque ligne revient en JSON illisible et les assertions se lisent
comme « rien n'a été journalisé ».

## Suites possibles

Reste au backlog, non traité ici :

- **Dériver l'intervalle d'un budget quotidien** plutôt que le fixer à l'aveugle.
  Demande un compteur journalier persisté par connexion (donc une migration) et
  une surface de configuration côté admin — écarté sciemment de ce lot.
- **Étendre le comptage aux autres connecteurs.** `BingxConnector` a déjà un
  `$requestsSent` interne, utilisé pour son pacing mais non exposé ; MetaApi et
  Ouinex ne comptent rien. Le crochet côté `BrokerSyncService` est générique et
  les accueillera sans modification.
