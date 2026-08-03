# 22 — Connecteurs broker (cTrader + MetaApi/MT4/MT5)

## Fonctionnalités

Synchronisation automatique de l'historique des trades fermés depuis les plateformes broker via API, sans export/import manuel.

### Connecteurs disponibles

| Connecteur | Plateforme | Protocole | Auth |
|-----------|------------|-----------|------|
| cTrader | cTrader | JSON WebSocket (port 5036) | OAuth2 |
| MetaApi | MT4, MT5 | REST | Bearer token |

### Workflow utilisateur

**cTrader** :
1. Clic "Connecter cTrader" → formulaire credentials
2. L'utilisateur fournit : Client ID, Client Secret, Access Token, Account ID (depuis openapi.ctrader.com)
3. Clic "Synchroniser" → récupération automatique des trades fermés via WebSocket JSON

**MetaApi (MT4/MT5)** :
1. Clic "Connecter MT4/MT5" → formulaire credentials
2. L'utilisateur fournit son token MetaApi + ID compte MetaApi (depuis metaapi.cloud)
3. Clic "Synchroniser" → récupération via REST

### Fonctionnalités communes
- **Sync incrémentale** : seuls les trades depuis la dernière sync sont récupérés (`sync_cursor`)
- **Déduplication** : les trades déjà importés sont ignorés via `external_id`
- **Auto-création symboles** : les symboles inconnus sont créés dans "Mes actifs" (type OTHER, devise du compte)
- **Résolution symboles** : utilise les alias existants (`symbol_aliases`) pour mapper broker → journal
- **Historique des syncs** : chaque sync est journalisée dans `sync_logs`
- **Auto-sync périodique** : les connexions `ACTIVE` sont synchronisées automatiquement par un scheduler dédié (container séparé sur Railway). Le bouton "Synchroniser" reste disponible pour un refresh à la demande. Voir [31-broker-auto-sync.md](31-broker-auto-sync.md) pour l'architecture, les env vars (intervalle, circuit breaker), et la procédure de déploiement.

## Architecture

```
CtraderConnector  ─┐
                    ├──> BrokerSyncService ──> ImportService.importNormalizedPositions()
MetaApiConnector  ─┘
```

Le `BrokerSyncService` orchestre :
1. Déchiffre les credentials
2. Rafraîchit les tokens si nécessaire
3. Appelle le connecteur (fetchDeals)
4. Normalise les deals via `DealNormalizer`
5. Groupe en positions via `RowGroupingService`
6. Persiste via le pipeline d'import existant

## Choix d'implémentation

### Réutilisation du pipeline d'import

La méthode `ImportService::importNormalizedPositions()` a été extraite de `confirm()` pour être partagée entre l'import fichier et la sync API. Même logique de dédup, résolution symboles et création positions/trades.

### cTrader JSON WebSocket

cTrader n'a pas d'API REST pour les trades. Le protocole natif est Protobuf over TCP, mais le port 5036 accepte du JSON over WebSocket — évitant la compilation protobuf. La lib `textalk/websocket` fournit un client synchrone adapté au pattern connect → fetch → disconnect.

**Format des messages (impératif).** Chaque frame suit l'enveloppe JSON officielle ([doc cTrader](https://help.ctrader.com/open-api/sending-receiving-json/)) :

```json
{ "clientMsgId": "12", "payloadType": 2100, "payload": { "clientId": "…", "clientSecret": "…" } }
```

- `payloadType` est le **code numérique** `ProtoOAPayloadType` (2100 = AppAuth, 2102 = AccountAuth, 2142 = ErrorRes, 51 = heartbeat…), **jamais** le nom de message.
- Les champs métier sont **imbriqués sous `payload`**.
- `clientMsgId` (compteur monotone) identifie la requête.

Côté réception, `sendAndReceive()` : ignore les heartbeats (`payloadType 51`) en bouclant, détecte les erreurs sur le **code numérique 2142** (une comparaison sur le nom de message ne matcherait jamais), et retourne le sous-objet `payload`. La table nom→code vit dans `CtraderConnector::PAYLOAD_TYPES`.

**Lecture complète (parité Ouinex/BingX).** Au-delà de `fetchDeals` (trades clos via `ProtoOADealListReq`), le connecteur câble tout le read path consommé par `BrokerSyncService` :

| Méthode | Message cTrader | Contenu |
|---------|-----------------|---------|
| `fetchOpenPositions` | `ProtoOAReconcileReq` → `position[]` | positions ouvertes (entry, SL/TP, volume) |
| `fetchOpenOrders` | `ProtoOAReconcileReq` → `order[]` | ordres en attente (hors `closingOrder` = SL/TP de position) |
| `fetchClosedOrders` | `ProtoOAOrderListReq` | statuts terminaux (EXECUTED/CANCELLED/EXPIRED) pour désambiguïser une disparition d'ordre |
| `fetchBalance` | `ProtoOATraderReq` → `trader` | balance (`balance / 10^moneyDigits`) |

Les `symbolId` numériques sont résolus en noms via `ProtoOASymbolByIdReq`. Les normalizers (`normalizeCtraderOpenPosition` / `OpenOrder` / `ClosedOrder`) préservent l'invariant `external_id` = `ctrader_<positionId>` / `ctrader_order_<orderId>`, indispensable aux transitions OPEN→CLOSED et au diff d'ordres. La tolérance enum (nom `'BUY'` **ou** code `1`) couvre l'incertitude sur la sérialisation JSON des enums, à figer au premier run live.

**Piège des champs répétés : `array_values()` est obligatoire.** Corrigé le 2026-08-01, au premier vrai run de synchro cTrader. `fetchDeals` dédoublonnait les identifiants de symbole avec `array_unique(array_column($deals, 'symbolId'))` — or `array_unique()` **conserve les clés d'origine**. Dès que deux deals partagent un symbole, les clés deviennent trouées (`0, 2, 4`), et `json_encode()` sérialise alors un tableau troué en **objet** :

```json
"symbolId": {"0":1,"2":22,"4":41}     // ← ce qu'on envoyait
"symbolId": [1,22,41]                 // ← ce que ProtoOASymbolByIdReq attend
```

cTrader lit `symbolId` comme un `repeated int64` et rejette la requête entière :

```
cTrader API error: INVALID_REQUEST - Unexpected IOException
(of type …JsonFormat$ParseException): 1:44: Couldn't parse integer: For input string: "{"
```

La colonne 44 désigne exactement l'accolade ouvrante de `symbolId` pour un `ctidTraderAccountId` à 8 chiffres. Le bug ne se déclenche **que** s'il y a un doublon de symbole — donc jamais en test unitaire sur un jeu de symboles distincts, et systématiquement en réel.

Règle générale : **tout tableau destiné à un champ répété doit passer par `array_values()`**. Les autres occurrences du connecteur (`fetchOpenOrders:184`, `:250`) l'appliquaient déjà ; c'était la seule oubliée. Audit fait sur `api/src/` — aucune autre instance n'atteint un format de fil (`ImportService:97` est réindexé par le `sort()` qui suit, les `account_ids` partent en SQL).

### Chiffrement des credentials

AES-256-CBC via `CredentialEncryptionService`. La clé vient de la variable d'environnement `BROKER_ENCRYPTION_KEY`. Chaque connexion a son propre IV. Les credentials ne sont jamais exposés dans les réponses API.

### Credentials par utilisateur

Chaque utilisateur fournit ses propres identifiants API :
- **cTrader** : Client ID + Client Secret + Access Token (depuis openapi.ctrader.com). Le compte et le serveur ne sont **pas saisis** : ils sont découverts depuis l'access token (`86-ctrader-account-discovery.md`). Le `ctidTraderAccountId` stocké n'est pas le numéro de compte affiché dans la plateforme (`traderLogin`)
- **MetaApi** : API Token + Account ID MetaApi (depuis metaapi.cloud)

Le serveur cTrader est stocké par connexion (`environment` : `LIVE` / `DEMO`) dans le blob chiffré, et non plus lu depuis la variable globale `CTRADER_WS_HOST` — un compte n'existe que sur un seul des deux serveurs. Les connexions antérieures, sans cette clé, continuent d'utiliser `CTRADER_WS_HOST`.

Pas de clés API partagées au niveau de l'application. Les credentials sont stockés chiffrés AES-256-CBC par utilisateur dans `broker_connections`.

## Endpoints API

| Méthode | Route | Description |
|---------|-------|-------------|
| POST | `/broker/connections` | Créer connexion (cTrader ou MetaApi) |
| PUT | `/broker/connections/{id}` | Reconfigurer les identifiants sur place (voir `85-broker-connection-reconfigure.md`) |
| POST | `/broker/ctrader/accounts` | Lister les comptes cTrader d'un access token (voir `86-ctrader-account-discovery.md`) |
| GET | `/broker/connections?account_id=X` | Statut connexion |
| POST | `/broker/connections/{id}/sync` | Déclencher sync |
| DELETE | `/broker/connections/{id}` | Supprimer connexion |
| GET | `/broker/connections/{id}/logs` | Historique syncs |

La création et la reconfiguration renvoient toutes deux `connection_test: { success, error }` : le résultat du `testConnection()` du provider, exécuté **sans bloquer** l'enregistrement.

## Tables

### `broker_connections`
Un enregistrement par compte. Credentials chiffrés, statut (PENDING/ACTIVE/ERROR/REVOKED), curseur de sync incrémentale, timestamps dernière sync.

### `sync_logs`
Audit trail : connexion, deals récupérés/importés/ignorés, erreurs, timestamps.

## Frontend

- **BrokerConnectionPanel** : statut connexion, bouton sync, dernière sync, **reconfiguration**, déconnexion
- **CtraderConnectDialog** : formulaire Client ID, Client Secret, Access Token, Account ID, **serveur Live/Démo**
- **MetaApiConnectDialog** : formulaire token + account ID
- Tous les dialogues ont un mode **reconfiguration** (prop `connection`) : identifiants non secrets préremplis, secrets vides où « vide = conservé ». Logique partagée dans `useBrokerCredentialForm`.
- **SyncHistoryDialog** : DataTable des `sync_logs`
- Bouton sync (pi-sync) dans la vue Comptes à côté de chaque compte

## Couverture des tests

| Test | Scénario | Statut |
|------|----------|--------|
| `CredentialEncryptionServiceTest` (6) | Encrypt/decrypt, wrong key, corrupted data | ✅ |
| `DealNormalizerTest` | cTrader (deals + positions/ordres ouverts + ordres clos) + MetaApi + Ouinex + BingX | ✅ |
| `MetaApiConnectorTest` | fetchDeals, cursor, testConnection, refresh no-op | ✅ |
| `CtraderConnectorTest` | format wire (payloadType numérique + `payload` + `clientMsgId`), détection erreur 2142, skip heartbeat, fetchOpen{Positions,Orders}, fetchClosedOrders, fetchBalance, ordres sortants, refresh | ✅ |
| `BrokerSyncServiceTest` | sync flow, wrong user, inactive, cursor, provider routing | ✅ |

Suite unitaire Broker complète : **184 tests, 613 assertions**, aucune régression.

## Configuration requise

Variables d'environnement (serveur) :
```
BROKER_AUTO_SYNC_ENABLED=true        # active la feature (défaut: false)
BROKER_ENCRYPTION_KEY=<base64 encoded 32-byte key>
```

### Feature flag `BROKER_AUTO_SYNC_ENABLED`

La synchronisation automatique est désactivée par défaut. Elle doit être activée explicitement via la variable d'environnement `BROKER_AUTO_SYNC_ENABLED=true` (sur Railway : variable sur le service API, puis redémarrage).

Comportement quand le flag est `false` :
- Les routes `/broker/*` renvoient **403 FORBIDDEN** (`broker.error.auto_sync_disabled`) via `FeatureFlagMiddleware`
- Le bouton "Synchroniser" et la dialog de connexion sont masqués dans `AccountsView.vue`
- L'endpoint public `GET /features` expose `{ broker_auto_sync: false }`, consommé au boot par `useFeaturesStore` (Pinia)

Cela permet de livrer le code en production tout en gardant la feature inactive tant qu'elle n'est pas validée en environnement de test.

Credentials utilisateur (fournis par chaque trader dans l'UI) :
- **cTrader** : Client ID, Client Secret, Access Token, Account ID (depuis openapi.ctrader.com)
- **MetaApi** : API Token, Account ID MetaApi (depuis metaapi.cloud)

## Dépendances ajoutées

- `guzzlehttp/guzzle` ^7.10 — client HTTP (MetaApi REST)
- `textalk/websocket` ^1.5 — client WebSocket synchrone (cTrader deals)
