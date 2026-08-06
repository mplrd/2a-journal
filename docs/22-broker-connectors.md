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

Les `symbolId` numériques sont résolus en noms via `ProtoOASymbolsListReq` (voir plus bas). Les normalizers (`normalizeCtraderOpenPosition` / `OpenOrder` / `ClosedOrder`) préservent l'invariant `external_id` = `ctrader_<positionId>` / `ctrader_order_<orderId>`, indispensable aux transitions OPEN→CLOSED et au diff d'ordres. La tolérance enum (nom `'BUY'` **ou** code `1`) couvre l'incertitude sur la sérialisation JSON des enums, à figer au premier run live.

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

**`moneyDigits`, pas des centimes.** Corrigé le 2026-08-03. cTrader n'exprime pas les montants en centimes : chaque message porte un `moneyDigits` qui est l'**exposant** à appliquer, et la doc du champ est explicite — « Affects grossProfit, swap, commission, balance, pnlConversionFee ».

Deux endroits convertissaient, et pas de la même façon :

| Endroit | Avant | Après |
|---|---|---|
| `CtraderConnector::fetchBalance` | `balance / 10^moneyDigits` | inchangé, déjà correct |
| `DealNormalizer::normalizeCtraderDeal` | `grossProfit / 100` **en dur** | `grossProfit / 10^moneyDigits` |

Le `/100` n'est juste que si `moneyDigits` vaut 2. Chez un broker qui renvoie 8, tous les P&L importés sont **un million de fois trop grands**. `moneyDigits` est lu sur `closePositionDetail` en priorité (c'est à lui qu'appartient `grossProfit`), avec repli sur celui du deal, puis sur 2 — ce dernier cas préservant à l'identique le comportement des comptes déjà synchronisés.

**Devise du solde (`getBalanceCurrency`).** `BrokerSyncService` persiste, à côté du solde, la devise dans laquelle il est libellé — via une méthode optionnelle `getBalanceCurrency()` qu'il résout par `method_exists()`. Seul BingX l'implémentait : une synchro cTrader enregistrait donc toujours une devise nulle, et le rapprochement avec `accounts.currency` (`AccountsView::hasCurrencyMismatch`) ne pouvait jamais se déclencher.

`CtraderConnector` l'implémente désormais : `fetchBalance` résout `trader.depositAssetId` en nom lisible via `ProtoOAAssetListReq`, sur la session déjà authentifiée. Échec toléré — pas de devise plutôt qu'une devise inventée, car une valeur fausse ferait croire à une concordance.

**Le comportement reste « signaler, pas convertir »** : `accounts.currency` est déclarée par l'utilisateur et sert à afficher tout l'historique. La synchro ne l'écrase pas ; elle affiche un avertissement quand les deux diffèrent, sans appliquer de conversion.

### Fidélité des deals cTrader

Corrigé le 2026-08-05, au premier run avec de vrais trades importés. Quatre défauts distincts faisaient que la donnée synchronisée ne correspondait pas à la réalité du compte. Ils sont indépendants mais se cumulaient sur le même trade.

**1. Le nom du symbole n'est pas sur `ProtoOASymbol`.** Tous les trades arrivaient étiquetés `SYM_331` — le repli du code. cTrader éclate le symbole sur **deux** types :

| Message | Type renvoyé | Contient |
|---|---|---|
| `ProtoOASymbolsListReq` (2114) | `ProtoOALightSymbol` | `symbolId`, **`symbolName`** |
| `ProtoOASymbolByIdReq` (2116) | `ProtoOASymbol` | `symbolId`, `lotSize`, `digits`, swaps, horaires… — **pas de `symbolName`** |

`resolveSymbolNames()` lisait `symbolName` sur la réponse *by-id* : le champ n'existe pas dans ce message, le `??` tombait donc systématiquement sur `'SYM_' . $id`. Le connecteur appelle désormais les deux (`resolveSymbols()`) : le nom vient de la liste light, le `lotSize` de la réponse by-id. La liste light couvre tout l'univers du broker, elle est donc **mémoïsée par compte pour la durée de la synchro** (`resetSyncCache()` la purge) — une synchro résout les symboles trois fois : deals, positions, ordres.

**1 bis. Les symboles archivés ne sont dans aucune des deux listes.** Complément du 2026-08-05 : `ProtoOASymbolsListReq` porte un champ `includeArchivedSymbols` et **n'inclut pas** les symboles retirés sans lui. Un instrument que le broker a archivé est donc absent de la liste light — un seul symbole reste bloqué sur `SYM_<id>` pendant que tous les autres du même compte se résolvent, ce qui ressemble à s'y méprendre à de la donnée périmée.

Pas besoin de la requête supplémentaire : `ProtoOASymbolByIdRes` renvoie `archivedSymbol[]` **à côté** de `symbol[]`, et on l'appelle déjà pour le `lotSize`. Attention au nom du champ — `ProtoOAArchivedSymbol` expose `name`, pas `symbolName`. Le repli ne s'applique que si la liste light n'a rien pour cet id (`??=`), la liste light restant la source primaire.

**2. Le volume ne se divise pas par 100000.** Un DAX de 1,5 contrat était importé en `0.0015`. `volume` (deal et `tradeData`) comme `lotSize` sont exprimés **en cents** — centièmes d'unité — donc :

```
lots = volume / lotSize          // ce que cTrader affiche
```

Le `/100000` codé en dur ne correspondait à aucune unité. Repli sans `lotSize` : `/100`, soit la signification documentée du champ (unités).

**3. `tradeSide` d'un deal de clôture est le sens de la clôture.** Un deal qui porte un `closePositionDetail` est celui qui **ferme** la position : son `tradeSide` est l'inverse du sens de la position (on solde un long en vendant). Le recopier tel quel inversait **tous** les trades cTrader importés — un short pris en TP apparaissait en achat gagnant. Idem pour la date : `createTimestamp` est celui du deal de clôture, donc chaque trade semblait ouvert à l'instant où il se fermait. Le connecteur apparie désormais la position à son deal d'**ouverture** (celui sans `closePositionDetail`) et injecte `positionOpenTimestamp`.

**4. Une clôture partielle n'est pas un trade clos.** Un TP1 laisse la position ouverte. Le deal correspondant était pourtant importé comme un trade terminé : l'utilisateur voyait une position fantôme (« achat à profit instantané ») à côté du short qui tournait toujours. `fetchDeals` interroge maintenant `ProtoOAReconcileReq` **avant** la liste des deals et :

- écarte les deals de clôture dont la position figure encore dans le snapshot live ;
- les republie via `fetchOpenPositions` en `exits[]` sur la position vivante (même contrat que BingX, cf. `BrokerOpenSyncService`) ;
- **élargit la fenêtre** jusqu'à la plus ancienne position ouverte. Le curseur n'avance que vers le futur : sans ça, les clôtures partielles d'une position ouverte de longue date sortiraient de la fenêtre et seraient perdues. Les positions déjà importées dans la plage élargie sont ignorées sur `external_id`.

`tradeData.volume` d'une position ouverte est le volume **restant** (cTrader le décrémente à chaque clôture partielle). La taille d'origine est donc reconstruite : `size = restant + Σ sorties`, `remaining_size = restant`.

**Deux identifiants, deux portées.** `external_id` (`ctrader_<positionId>`) nomme la **position** et est partagé par tous ses deals de clôture ; il ne peut donc pas dédoublonner les sorties. Chaque sortie porte en plus `exit_external_id` (`ctrader_deal_<dealId>`), et c'est lui qui sert au dédoublonnage des `partial_exits`. Même valeur émise pendant que la position est ouverte et une fois qu'elle est close : le TP1 n'est pas réécrit au moment de la clôture définitive.

**Conséquence sur `BrokerOpenSyncService`.** L'indexation du snapshot clos par `external_id` gardait la **dernière** ligne. Une position soldée en plusieurs fois (TP1 puis le reste) n'héritait alors que du P&L de la dernière jambe. Les lignes partageant un `external_id` sont désormais **fusionnées** — P&L et taille additionnés, prix de sortie repondéré, clôture datée de la dernière jambe — et chaque jambe est écrite en `partial_exits`, comme le fait déjà la clôture manuelle (`TradeService::exit`).

### Fuseau horaire des dates synchronisées

Corrigé le 2026-08-05, repéré en comparant l'heure affichée à l'heure réelle d'ouverture d'un trade. Concerne **tous les connecteurs**, pas seulement cTrader.

Les colonnes `DATETIME` du journal contiennent de l'**heure locale murale**, pas de l'UTC : le formulaire de trade enregistre littéralement ce que l'utilisateur tape dans le `DatePicker`. Les brokers, eux, renvoient tous des instants — epoch en millisecondes (cTrader, BingX) ou ISO-8601 avec offset (Ouinex, MetaApi) — et les connecteurs les rendaient en UTC :

| | Ouverture réelle | En base | Affiché |
|---|---|---|---|
| Trade saisi à la main | 07:29 Paris | `07:29:00` | 07:29 ✅ |
| Trade synchronisé (avant) | 07:29 Paris | `05:29:00` | 05:29 ❌ |

Deux heures d'écart entre deux lignes voisines de la même liste, et un décalage variable selon l'heure d'été. `gmdate()` et `new DateTime($iso)->format()` produisaient tous deux de l'UTC — le second parce qu'un `DateTime` conserve l'offset porté par la chaîne (`Z` = UTC).

`DealNormalizer` prend désormais un fuseau au constructeur et convertit dans `toJournalDatetime()`, DST comprise (`DateTimeZone`, pas un offset fixe). La valeur vient de **`users.timezone`** (colonne existante, défaut `Europe/Paris`), lue par `BrokerSyncService` et poussée aux connecteurs via le trait `NormalizesInUserTimezone`.

Deux replis silencieux, tous deux vers le comportement UTC antérieur : pas de fuseau transmis, et fuseau illisible (`users.timezone` est du texte libre — une faute de frappe ne doit pas faire échouer une synchro entière).

**Non traité** : les lignes déjà en base gardent leur heure UTC ; seules les synchros ultérieures écrivent en heure locale. Une resynchro depuis zéro les réaligne.

**Deux compléments (2026-08-05, au test de la correction précédente).** La conversion était juste mais n'atteignait pas les lignes attendues :

- `BrokerOpenSyncService::updateBrokerFields` rafraîchissait `entry_price`, `size`, `direction`, `symbol` et `remaining_size` — mais **pas `opened_at`**. Une position déjà connue du journal gardait donc son horodatage d'origine indéfiniment : corrigée sur toutes les colonnes sauf celle qui avait changé. Rafraîchi désormais, et uniquement quand le snapshot en porte un (le snapshot live BingX n'a pas d'heure d'ouverture — écrire `null` effacerait ce qu'on détient déjà).
- `cli/sync-brokers.php` construit sa propre instance de `BrokerSyncService` et ne recevait pas le `UserRepository` : l'auto-sync planifiée retombait sur UTC pendant qu'une synchro manuelle écrivait en heure locale, la même position dérivant selon le dernier chemin l'ayant touchée. Câblé.

### Ordres synchronisés : date de placement et expiration

Corrigé le 2026-08-05, même campagne. Deux trous, indépendants du fuseau mais rendus visibles par lui.

**La date de placement était perdue.** Les connecteurs normalisent bien `created_at` — le moment où le broker a placé l'ordre — mais `OrderRepository::create` n'écrivait jamais la colonne. Le `DEFAULT CURRENT_TIMESTAMP` de MySQL horodatait donc chaque ordre synchronisé **à l'instant de la synchro**, et dans le fuseau de la session SQL par-dessus le marché. La valeur calculée était simplement jetée. `created_at` est désormais transmise, avec un `COALESCE(:created_at, CURRENT_TIMESTAMP)` qui préserve le défaut pour les ordres créés dans l'application.

**L'expiration n'était jamais rafraîchie.** `BrokerOrderSyncService::updateBrokerFields` réécrivait les champs de la *position* (symbole, sens, taille, entrée, SL) mais rien sur la ligne `orders` — même angle mort que `opened_at` sur les positions. Une `expires_at` déjà en base restait figée, y compris après le passage en heure locale. `OrderRepository::updateExpiry()` a été ajouté pour ça, distinct de `updateStatus()` afin qu'une synchro corrige la date sans toucher au cycle de vie.

### Taille affichée dans le bloc « En cours » du dashboard

`positions.size` porte la taille **d'origine**, `trades.remaining_size` ce qu'il reste après les sorties partielles. Le panneau « En cours » du dashboard lisait `size` — il annonçait donc 2.5 contrats sur un short déjà à moitié soldé au TP1, alors qu'il n'en tournait plus que 1.5. Le défaut est ancien mais était invisible : avant la reconstruction de la taille d'origine, les deux colonnes étaient toujours égales sur les positions synchronisées. `remaining_size` était d'ailleurs déjà remontée par `StatsRepository::getOpenTrades()`, simplement jamais affichée.

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
