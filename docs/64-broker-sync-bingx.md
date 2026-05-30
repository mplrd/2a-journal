# Étape 64 — Synchronisation broker BingX (Phase 1 USDT-M Perpetual)

> **⚠️ Document historique.** Le sync décrit ici (via `/openApi/swap/v1/trade/positionHistory`)
> ne ramenait que les positions entièrement clôturées et manquait les TP partiels, le
> scaling-out et les trades en plusieurs fills. Voir **doc 67** pour le refactor
> fills-based qui le remplace.

## Résumé

Ajout de **BingX** comme quatrième provider de synchronisation broker (à côté de cTrader, MetaApi, Ouinex). Cette livraison **réutilise intégralement** le pipeline de sync mis en place pour Ouinex (étape 63) grâce au refactor "enum-driven prefix" qui rend les services de diff (`BrokerOpenSyncService`, `BrokerOrderSyncService`) agnostiques au provider — seul le `BingxConnector` et les méthodes `normalizeBingxXxx` du `DealNormalizer` sont nouveaux.

**Périmètre Phase 1 : USDT-M Perpetual Futures uniquement.** Coin-M Perpetual et Standard Contracts ne sont pas couverts sur cette branche : l'API BingX ne fournit pas d'endpoint `positionHistory` natif sur ces deux produits, donc rapatrier les positions clôturées nécessiterait un service de pairing à partir des fills (effort équivalent à la Phase 2 spot Ouinex — backlog `feat/import-bingx-cm-std`).

## Refactor préalable — enum-driven prefix

Avant de coder BingX, on a refactoré les services de diff pour les rendre 100 % partagés entre providers. Avant : `BrokerOpenSyncService::OUINEX_PREFIX = 'ouinex_'` hardcodé. Après : la méthode `apply()` prend un `BrokerProvider $provider` en premier paramètre et dérive le préfixe via `BrokerProvider::externalIdPrefix()` (resp. `orderExternalIdPrefix()` côté ordres).

Conséquence : ajouter BingX ne touche **aucun** code de diff. C'est purement une nouvelle paire connector + normalize methods + DI.

## UX

### Côté utilisateur BingX (à faire une fois)

1. Espace utilisateur BingX → **API Management**.
2. **Create API Key** avec au minimum les droits lecture sur :
   - Lecture **Positions** (pour `user/positions` et `positionHistory`).
   - Lecture **Ordres** (pour `openOrders` et `allOrders`).
3. Noter l'**API Key** et le **Secret Key** — le secret n'est pas réaffiché ensuite.

### Côté journal

1. **Mes comptes → bouton sync** (icône `sync` verte, derrière le feature flag `broker_auto_sync`) → ouvre le panneau de connexion broker du compte.
2. Quatre boutons sont proposés : **Connecter cTrader**, **Connecter MT4/MT5**, **Connecter Ouinex**, **Connecter BingX**.
3. Le dialog BingX demande **API Key** + **Secret Key**. Le secret est masqué (`type="password"`).
4. Validation → credentials chiffrés (AES-256-CBC + IV aléatoire) et stockés. **Aucun appel à BingX** à ce stade — la signature est calculée à la volée à chaque sync.

Une fois connecté, le bouton **Synchroniser maintenant** déclenche exactement le même pipeline que pour Ouinex :
- Positions clôturées (incrémental, curseur sur `closeTime`),
- Snapshot des positions ouvertes (réconciliation différentielle, méta utilisateur préservées),
- Snapshot des ordres pending,
- Snapshot des ordres récemment finalisés (EXECUTED / CANCELLED / EXPIRED).

## Architecture

```
┌──────────────────────────────┐
│  BrokerSyncController         │
│  POST /broker/connections     │  provider=BINGX → createBingxConnection
│  POST /broker/connections/    │
│       {id}/sync               │  → BrokerSyncService::sync
└──────────────┬───────────────┘
               │
               ▼
┌──────────────────────────────┐
│  BrokerSyncService            │
│  match(provider) →            │
│    BINGX → bingxConnector     │
│                               │
│  Pipeline identique Ouinex :  │
│   1) fetchDeals (incrémental) │
│   2) fetchOpenPositions diff  │
│   3) fetchOpenOrders +        │
│      fetchClosedOrders diff   │
└──────┬──────────────────┬────┘
       │                  │
       ▼                  ▼
┌──────────────┐  ┌────────────────────────┐         ┌─────────────────────────┐
│ImportService │  │BrokerOpenSyncService    │         │  BingX REST API         │
│              │  │BrokerOrderSyncService   │ HTTPS   │  open-api.bingx.com     │
│closed deals  │  │                         ├────────►│                         │
│              │  │Préservent les méta user │         │  HMAC-SHA256 signature  │
└──────────────┘  └────────────┬────────────┘         │  X-BX-APIKEY header     │
                               │                      └─────────────────────────┘
                               ▼
                  ┌────────────────────────┐
                  │ PositionRepository      │
                  │ OrderRepository         │
                  │ TradeRepository         │
                  └────────────────────────┘
```

### Authentification HMAC-SHA256

Pas de JWT, pas de cycle de refresh. Chaque requête signe ses paramètres :

1. Tous les params sortés ASCII ascendant.
2. Sérialisés en `key=value&key=value` **sans URL-encoding**.
3. HMAC-SHA256 avec le secret, hex lowercase.
4. Signature ajoutée à la query string en `&signature=<hex>`.
5. Header `X-BX-APIKEY: <api_key>`.
6. Param `timestamp` en millisecondes (requis, fenêtre 5000ms par défaut).

C'est implémenté dans `BingxConnector::sign()` (5 lignes) et `httpGetSigned()` orchestre l'ensemble.

### Mapping des endpoints

| Concept journal | Endpoint BingX | Notes |
|---|---|---|
| `fetchDeals` (closed positions) | `GET /openApi/swap/v1/trade/positionHistory` | Pagination `pageIndex`/`pageSize`. Fenêtre max 3 mois. Requiert `symbol` par appel → on énumère les symbols via `user/positions` |
| `fetchOpenPositions` | `GET /openApi/swap/v2/user/positions` | Snapshot complet en un appel |
| `fetchOpenOrders` | `GET /openApi/swap/v2/trade/openOrders` | Tolère deux formats de réponse (`data.orders[]` ou bare array) |
| `fetchClosedOrders` | `GET /openApi/swap/v2/trade/allOrders` | Requiert `symbol` → même stratégie qu'au-dessus |
| `testConnection` | `GET /openApi/swap/v3/user/balance` | Endpoint léger signé, valide la clé sans pulls lourds |

### Mapping des champs

Closed position (`positionHistory.list[]`) :

| BingX | Deal normalisé | Note |
|---|---|---|
| `positionId` | `external_id` (préfixe `bingx_`) | clé de dédoublonnage |
| `symbol` | `symbol` | direct |
| `positionSide` (LONG/SHORT) | `direction` (BUY/SELL) | conventionnel |
| `avgPrice` | `entry_price` | cast string→float |
| `closeAvgPrice` | `exit_price` | fallback `exitPrice`/`closePrice` si BingX drift |
| `positionAmt` | `size` | cast string→float |
| `realisedProfit` | `pnl` | fallback `realizedPnl` |
| `openTime` ms | `opened_at` | conversion millis → `Y-m-d H:i:s` |
| `closeTime` ms | `closed_at` | fallback `updateTime` |

> **Note** : la doc API BingX ne détaille pas explicitement les champs du `positionHistory.list[]`. Les noms ci-dessus sont **inférés** à partir de la schéma des positions ouvertes (qui sont documentées) et de la convention BingX. Si un drift est détecté au premier sync live, ajuster `DealNormalizer::normalizeBingxClosedPosition` (les fallbacks `??` empêchent l'erreur fatale).

Open position (`user/positions`) :

Mapping similaire, sans `exit_price`/`pnl`/`closed_at`. `sl_price`/`tp_price` restent null — BingX ne les remonte pas sur ce endpoint.

Pending order (`openOrders`) :

| BingX | Order normalisé |
|---|---|
| `orderId` | `external_id` (préfixe `bingx_order_`) |
| `symbol` | `symbol` |
| `side` (BUY/SELL) | `direction` (déjà aligné) |
| `price` | `entry_price` |
| `origQty` | `size` |
| `time` ms | `created_at` |

Closed order (`allOrders`) : juste `external_id` + `final_status` (FILLED→EXECUTED, CANCELED→CANCELLED, EXPIRED→EXPIRED).

### Limitation Phase 1 — symbols couverts

`positionHistory` et `allOrders` requièrent un `symbol` par appel. Le connector énumère les symbols depuis `user/positions` (snapshot des positions actuellement ouvertes). **Conséquence** : si l'utilisateur a fermé toutes ses positions sur un symbol entre deux syncs **et** que la fermeture est antérieure au curseur précédent, ce symbol ne sera plus interrogé.

En pratique le curseur incrémente à chaque sync (max `closeTime` vu), et le scheduler tourne toutes les 15 min — l'écart entre 2 syncs reste très inférieur à la fenêtre de 3 mois supportée par BingX. La limitation se déclenche seulement si l'utilisateur ferme tout un symbol et arrête la sync pendant des semaines avant de relancer.

Pour une couverture totale on pourrait persister un set "symbols vus" en base. Tracé en backlog si nécessaire (voir `docs/evolutions.md`).

## Fichiers impactés

### Backend

| Fichier | Type | Rôle |
|---|---|---|
| `api/src/Enums/BrokerProvider.php` | modif | + cas `BINGX` |
| `api/src/Services/Broker/BingxConnector.php` | nouveau | implémente `ConnectorInterface` (HMAC, REST, 4 fetches) |
| `api/src/Services/Broker/DealNormalizer.php` | modif | + `normalizeBingxClosedPosition`, `normalizeBingxOpenPosition`, `normalizeBingxOpenOrder`, `normalizeBingxClosedOrder` |
| `api/src/Services/Broker/BrokerSyncService.php` | modif | injection 4e connector + `match` provider |
| `api/src/Controllers/BrokerSyncController.php` | modif | + branche `createBingxConnection()` |
| `api/config/broker.php` | modif | + section `bingx.base_url` (env `BINGX_BASE_URL`) |
| `api/config/routes.php` | modif | instancie `BingxConnector` + DI |
| `api/cli/sync-brokers.php` | modif | DI côté scheduler CLI |
| `api/database/migrations/019_broker_provider_bingx.sql` | nouveau | `ALTER TABLE … MODIFY provider ENUM(…, 'BINGX')` |
| `api/database/schema.sql` | modif | provider ENUM à jour |

### Refactor préalable (commit séparé `231ea12`)

| Fichier | Type | Rôle |
|---|---|---|
| `api/src/Enums/BrokerProvider.php` | modif | + `externalIdPrefix()` / `orderExternalIdPrefix()` |
| `api/src/Services/Broker/BrokerOpenSyncService.php` | modif | `apply()` prend le provider en param |
| `api/src/Services/Broker/BrokerOrderSyncService.php` | modif | idem |
| `api/src/Services/Broker/BrokerSyncService.php` | modif | passe le provider aux diff services |

### Frontend

| Fichier | Type | Rôle |
|---|---|---|
| `frontend/src/services/brokerSync.js` | modif | + `createBingxConnection(accountId, apiKey, apiSecret)` |
| `frontend/src/components/broker/BingxConnectDialog.vue` | nouveau | formulaire 2 champs (clé + secret) |
| `frontend/src/components/broker/BrokerConnectionPanel.vue` | modif | + bouton "Connecter BingX" + entry dans `PROVIDER_LABELS` + handler |
| `frontend/src/locales/fr.json` + `en.json` | modif | + clés `connect_bingx`, `bingx_instructions`, `bingx_api_key(_placeholder)`, `bingx_api_secret(_placeholder)` |

## Variables d'environnement

| Variable | Type | Défaut | Rôle |
|---|---|---|---|
| `BINGX_BASE_URL` | string | `https://open-api.bingx.com` | Endpoint REST BingX. Override possible vers le fallback `.pro` ou le testnet VST (`open-api-vst.bingx.com`). |

Pas de nouvelle variable secrète. `BROKER_ENCRYPTION_KEY` (déjà en place) protège aussi les credentials BingX au repos.

## Couverture de tests

Suite complète au vert (1204 tests, 3339 assertions). Sur le périmètre BingX :

| Test | Type | Fichier |
|---|---|---|
| `testConnection` succès | Unit | `BingxConnectorTest::testTestConnectionReturnsTrueWhenBalanceCallSucceeds` |
| `testConnection` HTTP error → false | Unit | `…ReturnsFalseOnHttpError` |
| `testConnection` business error → false | Unit | `…ReturnsFalseOnBingxBusinessError` |
| Signature HMAC = HMAC-SHA256 sur canonical sorted | Unit | `testSignatureIsBuiltFromSortedParamsAndAppendedToQuery` |
| `refreshCredentials` no-op | Unit | `testRefreshCredentialsIsNoOpForHmac` |
| `fetchOpenPositions` happy path + LONG→BUY | Unit | `testFetchOpenPositionsReturnsNormalizedSnapshot` |
| `fetchOpenPositions` SHORT→SELL | Unit | `…MapsShortToSell` |
| `fetchOpenPositions` API vide | Unit | `…ReturnsEmptyWhenApiReturnsEmptyList` |
| `fetchOpenOrders` `data.orders` shape | Unit | `testFetchOpenOrdersReturnsNormalizedSnapshot` |
| `fetchOpenOrders` bare array shape | Unit | `…HandlesBareArrayResponse` |
| `fetchClosedOrders` mappe FILLED/CANCELED/EXPIRED | Unit | `testFetchClosedOrdersReturnsFinalStatuses` |
| `fetchDeals` itère symbols actifs + normalize | Unit | `testFetchDealsIteratesActiveSymbolsAndReturnsNormalizedClosedPositions` |
| `fetchDeals` filtre `closeTime <= cursor` | Unit | `…FiltersClosedPositionsOlderThanCursor` |
| `fetchDeals` empty si aucun symbol actif | Unit | `…ReturnsEmptyWhenNoSymbols` |
| Service utilise `bingxConnector` pour provider BINGX | Unit | `BrokerSyncServiceTest::testSyncUsesBingxConnectorForBingxProvider` |

## Phase 2 BingX — pour mémoire

`feat/import-bingx-cm-std` ultérieure : Coin-M Perpetual + Standard Contracts. Sans `positionHistory` natif, la stratégie sera de **synthétiser les positions clôturées** depuis les fills d'ordres (`/cswap/v1/trade/allFillOrders` ou `/contract/v1/allOrders` selon le produit) — c'est équivalent en effort à la Phase 2 spot Ouinex (pairing FIFO + état persistant entre syncs). Cadrage exact à faire au démarrage de la branche.

Le pattern est éprouvé : un `BingxCswapConnector` (ou méthode dédiée du `BingxConnector` actuel) qui fait le pairing au moment de `fetchDeals` et expose les positions normalisées comme aujourd'hui. Les services de diff côté journal n'ont **rien à changer**.
