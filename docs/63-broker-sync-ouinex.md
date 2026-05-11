# Étape 63 — Synchronisation broker Ouinex (Phase 1 dérivés)

## Résumé

Ajout d'**Ouinex** comme troisième provider de synchronisation broker, à côté de cTrader et MetaApi. La connexion se fait via une clé d'API/secret générés depuis l'espace Ouinex de l'utilisateur ; le journal échange ces credentials contre un JWT (mutation GraphQL `service_signin`) puis rapatrie à chaque sync :

1. Les **positions clôturées** depuis le dernier curseur (`closed_margin_positions`, incrémental).
2. Le **snapshot complet des positions ouvertes** (`open_margin_positions`, plein à chaque run — pas de curseur, c'est le broker la source de vérité du "live").

Cette livraison couvre la **Phase 1** du plan E-02 (cf. `docs/evolutions.md`) — les **dérivés** uniquement. La Phase 2 (spot, `closed_orders` order-by-order avec pairing FIFO) fera l'objet d'une branche dédiée ultérieure. Une livraison ultérieure (paquet B sur cette même branche) ajoutera la prise en charge des **ordres en attente** (`open_orders`).

Bonus pour l'utilisateur : pas d'export CSV à manipuler, pas de mapping de colonnes, un cursor incrémental qui n'importe que les positions nouvelles, et une réconciliation **différentielle** qui maintient l'état "live" des positions ouvertes à chaque sync **sans écraser les méta-données saisies par l'utilisateur** (setup, notes, custom fields).

## Pourquoi un connector et pas un import fichier

La doc API Ouinex (collection Postman publique) montre que l'export CSV `orders-history_*.csv` est de l'**order-by-order** (un leg par ligne, pas du round-trip). Notre pipeline d'import attend des lignes round-trip type FTMO (entry + exit + pnl sur la même ligne) → pas exploitable directement. À l'inverse, la requête GraphQL `closed_margin_positions` retourne **directement** des positions agrégées avec `entry_price` / `exit_price` / `pnl`, exactement le format que `DealNormalizer` sait digérer.

→ On raccroche au pattern `ConnectorInterface` existant, pas au pipeline `import/`.

## UX

### Côté utilisateur Ouinex (à faire une fois)

1. Se connecter sur Ouinex.
2. **Settings → API** → **Create API Key**.
3. Activer au minimum la permission **`closed_margin_positions`**. (Les autres permissions — `open_margin_positions`, `closed_orders` — ne sont pas utilisées par la Phase 1 ; à activer en prévision de la Phase 2 spot pour ne pas avoir à régénérer la clé plus tard.)
4. Noter la **Service API Key** et le **Service API Secret**. Ils ne seront plus visibles ensuite.

### Côté journal

1. **Mes comptes → bouton sync** (icône `sync` verte, visible quand le feature flag `broker_auto_sync` est ON) → ouvre le panneau de connexion broker du compte.
2. Si aucune connexion n'est configurée, trois boutons sont désormais proposés : **Connecter cTrader**, **Connecter MT4/MT5**, **Connecter Ouinex**.
3. Le dialog Ouinex demande deux champs : **Clé d'API Ouinex** + **Secret d'API Ouinex**. Le secret est masqué (`type="password"`).
4. Validation → le journal stocke les credentials chiffrés en base (clé `BROKER_ENCRYPTION_KEY`, AES-256-CBC, IV aléatoire par enregistrement) **sans** appeler Ouinex à ce stade. Le JWT n'est négocié qu'au premier `sync`, lazy.

Une fois connecté, le bouton **Synchroniser maintenant** rapatrie :
- les positions **clôturées** depuis le dernier cursor enregistré, ajoutées au journal en `TradeStatus::CLOSED` ;
- l'état **complet** des positions actuellement ouvertes côté Ouinex, réconciliées avec le journal :
  - nouvelle position → insérée en `TradeStatus::OPEN` ;
  - position déjà connue → ses champs broker (entry_price, size, SL) sont rafraîchis, le **setup / notes / champs personnalisés** que l'utilisateur a saisis pendant la durée de vie de la position sont **préservés** ;
  - position passée de "live" à "fermée" entre deux syncs → le trade OPEN existant **transitionne** vers CLOSED en place (avg_exit_price, pnl, closed_at remplis depuis `closed_margin_positions`), et là encore les méta-données utilisateur sont conservées.

Un panneau **Historique** liste les runs précédents (date, statut, count).

## Architecture

```
┌──────────────────────────────┐
│  BrokerSyncController         │
│  POST /broker/connections     │  provider=OUINEX → createOuinexConnection
│  POST /broker/connections/    │
│       {id}/sync               │  → BrokerSyncService::sync
└──────────────┬───────────────┘
               │
               ▼
┌──────────────────────────────┐
│  BrokerSyncService            │
│  match(provider) →            │
│    OUINEX → ouinexConnector   │
│                               │
│  Pipeline en deux passes :    │
│   1) fetchDeals (incrémental) │
│   2) fetchOpenPositions       │
│      + BrokerOpenSyncService  │
│        (diff)                 │
└──────┬──────────────────┬────┘
       │                  │
       ▼                  ▼
┌──────────────┐  ┌────────────────────────┐         ┌─────────────────────────┐
│ImportService │  │BrokerOpenSyncService    │         │  Ouinex GraphQL API     │
│              │  │                         │         │  live-api.ouinex.com    │
│insère les    │  │INSERT/UPDATE/TRANSITION │ HTTPS   │  /graphql               │
│closed comme  │  │selon le diff snapshot   ├────────►│                         │
│deals_imported│  │vs DB (préserve méta)    │         │  service_signin         │
└──────────────┘  └────────────┬────────────┘         │  closed_margin_positions│
                               │                      │  open_margin_positions  │
                               ▼                      └─────────────────────────┘
                  ┌────────────────────────┐
                  │ PositionRepository      │
                  │ TradeRepository         │
                  │  - update broker fields │
                  │  - transition OPEN→CLOSED│
                  └────────────────────────┘
```

### Authentification — JWT lazy avec marge de sécurité

Le JWT est obtenu via la mutation GraphQL `service_signin(service_api_key, service_api_secret)`. La réponse renvoie `jwt` + `expires_at` ISO. Le connector :

- **Cache** le JWT dans le blob de credentials chiffrés en base (champ `jwt` + `jwt_expires_at` Unix).
- **Refresh** automatique si :
  - le JWT n'existe pas (premier sync),
  - il est expiré,
  - il expire dans **moins de 60 secondes** (marge de sécurité pour ne pas racer la limite de validité serveur).
- En cas d'absence de `expires_at` parsable, fallback à **1h** de TTL conservateur.

Quand `refreshCredentials` retourne un blob différent de l'entrée, `BrokerSyncService` le ré-encrypte et persiste — le JWT est ainsi réutilisé pour le `fetchDeals` de la même run sans nouvelle requête `service_signin`, et toute la durée pendant laquelle il reste valide.

### Pagination + cursor — dédup côté client

`closed_margin_positions` accepte uniquement un `pager: { offset, limit }`, **pas** de `dateRange` filter côté serveur. Le connector pagine par lots de 100 jusqu'à ce qu'une page ne soit pas pleine, et :

- **Trace** le `cursor` sur `end_ts` brut (avant tout filtrage), donc même les positions explicitement skippées (déjà importées) servent à avancer le curseur. Évite de re-paginer indéfiniment les mêmes pages anciennes lorsqu'un sync s'est mal passé en cours.
- **Filtre** côté client : positions avec `end_ts <= sync_cursor` ignorées, le reste est normalisé.
- Le cursor enregistré en base est `max(end_ts)` de la run, prêt pour le prochain sync incrémental.

### Mapping des champs

| Ouinex (`closed_margin_position`) | Deal normalisé | Commentaire |
|---|---|---|
| `instrument_id` | `symbol` | direct |
| `side` | `direction` | direct (BUY/SELL) — **pas** d'inversion comme MetaApi |
| `entry_price` | `entry_price` | direct |
| `exit_price` | `exit_price` | direct |
| `amount` | `size` | direct |
| `pnl` | `pnl` | direct, arrondi à 2 décimales |
| `start_ts` | `opened_at` | ISO → `Y-m-d H:i:s` |
| `end_ts` | `closed_at` | ISO → `Y-m-d H:i:s` |
| `margin_position_id` | `external_id` | préfixé `ouinex_` (dédoublonnage) |
| `leverage`, `stop_loss`, `take_profit`, `close_reason` | — | non utilisés Phase 1 |

**Différence importante avec MetaApi** : MetaApi expose les *deals* (legs d'exécution), donc le `side` du deal de clôture est l'**inverse** de la direction de la position (un sell-to-close clôture un long). À l'opposé, Ouinex `closed_margin_positions` expose des positions agrégées : `side` = direction de la position elle-même → mappage 1:1.

### Fail-safes côté normalizer

Si un `closed_margin_position` arrive sans `end_ts` ou sans `exit_price` (anomalie API : une position non vraiment clôturée qui aurait fuité dans le résultat), `normalizeOuinexMarginPosition` retourne `null` plutôt que d'ingérer un trade à moitié calculé.

De même pour `open_margin_position` : si `entry_price` est null/absent, `normalizeOuinexOpenMarginPosition` retourne `null` — sans entry, la position est inutilisable côté journal.

### Snapshot OPEN — réconciliation différentielle

Contrairement à `fetchDeals` (incrémental, append-only via `external_id`), `fetchOpenPositions` retourne **l'état complet** des positions actuellement ouvertes — c'est le broker la source de vérité du "live". Le pipeline `closed → fetch + import` reste utilisable tel quel pour les fermetures déjà décidées ; pour le live, on a besoin d'une réconciliation à 3 cas.

`BrokerOpenSyncService::apply(userId, accountId, batchId, openSnapshot, closedSnapshot)` réalise ce diff. Il indexe par `external_id` :

| Cas | Détection | Action |
|---|---|---|
| **INSERT** | snapshot OPEN ∖ DB | `PositionRepository::create` + `TradeRepository::create` (status=OPEN). Champs : direction, symbol, entry_price, size, sl_price, external_id (`ouinex_<margin_position_id>`), import_batch_id (même batch que les closed). |
| **UPDATE** | DB ∩ snapshot OPEN | `PositionRepository::update` **whitelisté** sur les champs broker (`entry_price`, `size`, `sl_price`, `direction`, `symbol`) — les `setup`, `notes`, `custom_field_values` ne sont **jamais touchés**. `TradeRepository::update` rafraîchit `remaining_size`. Le `status=OPEN` est explicitement non écrit pour ne pas réinitialiser un trade déjà SECURED côté journal. |
| **TRANSITION** | DB OPEN ∖ snapshot OPEN, mais présent dans `closedSnapshot` | `TradeRepository::update` du trade existant : `status=CLOSED`, `avg_exit_price`, `pnl`, `closed_at`, `remaining_size=0`, `exit_type=MANUAL`. La position elle-même **n'est pas modifiée** — setup/notes/custom de l'utilisateur survivent à la clôture. |
| **SKIP** | DB OPEN ∖ snapshot OPEN, absent aussi du `closedSnapshot` | rien. Defensive : pourrait être un trou de pagination, un blip API, ou un sync où le cursor a déjà avancé au-delà du moment de clôture. La prochaine sync réconciliera si l'anomalie persiste. |

L'invariant clé qui rend la TRANSITION possible : le `margin_position_id` Ouinex est stable pendant tout le cycle de vie d'une position. `normalizeOuinexOpenMarginPosition` et `normalizeOuinexMarginPosition` produisent **le même `external_id`** pour le même `margin_position_id`, donc le diff matche sur cette clé sans ambiguïté.

Le scope de la diff est strictement limité par le préfixe `ouinex_` (via `findOpenByExternalIdPrefixInAccount`). Les positions saisies manuellement (no external_id) ou importées depuis un fichier (préfixes type `ftmo_`, `metaapi_`) sont **invisibles** pour le service — aucun risque de touche-touche.

## Fichiers impactés

### Backend

| Fichier | Type | Rôle |
|---|---|---|
| `api/src/Enums/BrokerProvider.php` | modif | + cas `OUINEX` |
| `api/src/Services/Broker/ConnectorInterface.php` | modif | + signature `fetchOpenPositions()` (no-op par défaut pour cTrader/MetaApi) |
| `api/src/Services/Broker/OuinexConnector.php` | nouveau | implémente `ConnectorInterface` (GraphQL, JWT, pagination closed + open) |
| `api/src/Services/Broker/CtraderConnector.php` | modif | + `fetchOpenPositions()` no-op |
| `api/src/Services/Broker/MetaApiConnector.php` | modif | + `fetchOpenPositions()` no-op |
| `api/src/Services/Broker/DealNormalizer.php` | modif | + `normalizeOuinexMarginPosition()` et `normalizeOuinexOpenMarginPosition()` |
| `api/src/Services/Broker/BrokerOpenSyncService.php` | nouveau | diff snapshot → INSERT/UPDATE/TRANSITION/SKIP en préservant les méta utilisateur |
| `api/src/Services/Broker/BrokerSyncService.php` | modif | injection 3e connector + 4e service + appel diff après import closed |
| `api/src/Repositories/PositionRepository.php` | modif | + `findOpenByExternalIdPrefixInAccount()` pour le diff (JOIN trades, scope par préfixe) |
| `api/src/Controllers/BrokerSyncController.php` | modif | + branche `createOuinexConnection()` (clé/secret) |
| `api/config/broker.php` | modif | + section `ouinex.graphql_url` (env `OUINEX_GRAPHQL_URL`) |
| `api/config/routes.php` | modif | instancie `OuinexConnector` + `BrokerOpenSyncService` et les injecte dans `BrokerSyncService` |
| `api/cli/sync-brokers.php` | modif | idem côté scheduler CLI (était jusqu'ici à 2 connectors seulement, le 3e n'avait pas été câblé) |
| `api/database/migrations/018_broker_provider_ouinex.sql` | nouveau | `ALTER TABLE broker_connections MODIFY provider ENUM(... 'OUINEX')` |

### Frontend

| Fichier | Type | Rôle |
|---|---|---|
| `frontend/src/services/brokerSync.js` | modif | + `createOuinexConnection(accountId, apiKey, apiSecret)` |
| `frontend/src/components/broker/OuinexConnectDialog.vue` | nouveau | formulaire deux champs (clé + secret), pattern jumeau de `MetaApiConnectDialog` |
| `frontend/src/components/broker/BrokerConnectionPanel.vue` | modif | + bouton "Connecter Ouinex" + label provider + handler `onOuinexConnected` |
| `frontend/src/locales/fr.json` + `en.json` | modif | + clés `connect_ouinex`, `ouinex_instructions`, `ouinex_api_key(_placeholder)`, `ouinex_api_secret(_placeholder)` |

## Variables d'environnement

| Variable | Type | Défaut | Rôle |
|---|---|---|---|
| `OUINEX_GRAPHQL_URL` | string | `https://live-api.ouinex.com/graphql` | Endpoint GraphQL utilisé par `OuinexConnector`. Override utile en test ou si Ouinex change de domaine. |

Pas de nouvelle variable secrète. La clé `BROKER_ENCRYPTION_KEY` (déjà en place pour cTrader/MetaApi, AES-256-CBC, voir `CredentialEncryptionService`) protège aussi les credentials Ouinex au repos.

## Couverture de tests

Suite complète au vert (1165 tests, 3182 assertions). Sur le périmètre Ouinex/Diff/Sync :

| Test | Type | Fichier |
|---|---|---|
| `service_signin` succès → JWT en cache | Unit | `OuinexConnectorTest::testRefreshCredentialsCallsServiceSigninWhenJwtMissing` |
| JWT expiré → re-signin | Unit | `…WhenJwtExpired` |
| JWT à 30s d'expiration → re-signin (marge 60s) | Unit | `…WhenJwtAboutToExpire` |
| JWT valide → pas d'appel HTTP | Unit | `testRefreshCredentialsReturnsCachedJwtWhenStillValid` |
| `testConnection` → `true` sur signin OK | Unit | `testTestConnectionReturnsTrueWhenServiceSigninSucceeds` |
| `testConnection` → `false` sur HTTP error | Unit | `…WhenSigninFailsHttp` |
| `testConnection` → `false` sur GraphQL error | Unit | `…WhenGraphqlReturnsErrors` |
| `fetchDeals` happy path 1 page | Unit | `testFetchDealsReturnsNormalizedClosedMarginPositions` |
| `fetchDeals` paginate jusqu'à page partielle | Unit | `…PaginatesUntilEmptyPage` |
| `fetchDeals` cursor = max(`end_ts`) page brute | Unit | `…ReturnsLatestEndTsAsCursor` |
| `fetchDeals` filtre `end_ts <= cursor` côté client | Unit | `…FiltersOutPositionsOlderThanCursor` |
| `fetchDeals` re-signin lazy si pas de JWT | Unit | `…RefreshesJwtOnExpiredCredsBeforeQuery` |
| `fetchDeals` API vide → résultat vide propre | Unit | `…ReturnsEmptyResultWhenApiReturnsEmpty` |
| `fetchOpenPositions` happy path + normalize | Unit | `testFetchOpenPositionsReturnsNormalizedSnapshot` |
| `fetchOpenPositions` paginate full | Unit | `…PaginatesUntilEmptyPage` |
| `fetchOpenPositions` re-signin lazy | Unit | `…RefreshesJwtIfMissing` |
| `fetchOpenPositions` API vide | Unit | `…ReturnsEmptyWhenApiReturnsEmpty` |
| Normalizer closed round-trip BTCUSDT | Unit | `DealNormalizerTest::testNormalizeOuinexMarginPositionMapsRoundTripFields` |
| Normalizer SELL préservé 1:1 (vs MetaApi inversé) | Unit | `…PreservesShortDirection` |
| Normalizer skip closed sans `end_ts` | Unit | `…SkipsPositionWithoutEnd` |
| Normalizer open mappe les champs live | Unit | `…NormalizeOuinexOpenMarginPositionMapsLiveFields` |
| Normalizer open external_id == closed (invariant transition) | Unit | `…OpenMarginPositionExternalIdMatchesClosed` |
| Normalizer open skip sans entry_price | Unit | `…OpenMarginPositionSkipsIfMissingEntryPrice` |
| Normalizer open garde SL/TP null si absent | Unit | `…OpenMarginPositionLeavesSlTpNullWhenAbsent` |
| Diff insère un OPEN inconnu | Unit | `BrokerOpenSyncServiceTest::testInsertsNewOpenPositionAsOpenTrade` |
| Diff met à jour broker fields **sans** toucher méta user | Unit | `…UpdatesBrokerFieldsOfExistingOpenPositionPreservingMeta` |
| Diff transitionne OPEN→CLOSED en place | Unit | `…TransitionsOpenToClosedWhenPositionAppearsInClosedSnapshot` |
| Diff laisse l'orphelin tranquille (defensive) | Unit | `…LeavesOrphanOpenAloneWhenNotInAnySnapshot` |
| Diff mixed (insert + update + transition) | Unit | `…ProcessesMixedSnapshotInOneCall` |
| Diff ne touche jamais les non-Ouinex (scope préfixe) | Unit | `…NeverTouchesNonOuinexPositions` |
| Service utilise `ouinexConnector` pour provider OUINEX | Unit | `BrokerSyncServiceTest::testSyncUsesOuinexConnectorForOuinexProvider` |
| Sync appelle bien diff après import closed | Unit | `…CallsOpenSnapshotDiffAfterClosedImport` |
| Sync stub OPEN reste no-op si connector silencieux | Unit | `…SkipsOpenSnapshotWhenConnectorReturnsEmpty` |

## Phase 2 — pour mémoire

La Phase 2 (spot via `closed_orders` GraphQL, FIFO pairing service, persistance des legs non-clôturés entre runs) est cadrée dans `docs/evolutions.md → E-02 Phase 2`. Elle livrera sur la branche `feat/import-ouinex-spot` et fera l'objet d'une doc séparée.

Risques connus à ne pas re-scoper en Phase 1 :
- Stack de legs non clôturés persistante entre deux syncs incrémentaux.
- Cold sync vs incremental sync sur l'historique complet d'un compte spot.
- Gestion des fees (à exposer en field séparé ou retrancher du pnl).
