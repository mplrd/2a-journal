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

### Chiffrement des credentials

AES-256-CBC via `CredentialEncryptionService`. La clé vient de la variable d'environnement `BROKER_ENCRYPTION_KEY`. Chaque connexion a son propre IV. Les credentials ne sont jamais exposés dans les réponses API.

### Credentials par utilisateur

Chaque utilisateur fournit ses propres identifiants API :
- **cTrader** : Client ID + Client Secret + Access Token + Account ID (depuis openapi.ctrader.com)
- **MetaApi** : API Token + Account ID MetaApi (depuis metaapi.cloud)

Pas de clés API partagées au niveau de l'application. Les credentials sont stockés chiffrés AES-256-CBC par utilisateur dans `broker_connections`.

## Endpoints API

| Méthode | Route | Description |
|---------|-------|-------------|
| POST | `/broker/connections` | Créer connexion (cTrader ou MetaApi) |
| GET | `/broker/connections?account_id=X` | Statut connexion |
| POST | `/broker/connections/{id}/sync` | Déclencher sync |
| DELETE | `/broker/connections/{id}` | Supprimer connexion |
| GET | `/broker/connections/{id}/logs` | Historique syncs |

## Tables

### `broker_connections`
Un enregistrement par compte. Credentials chiffrés, statut (PENDING/ACTIVE/ERROR/REVOKED), curseur de sync incrémentale, timestamps dernière sync.

### `sync_logs`
Audit trail : connexion, deals récupérés/importés/ignorés, erreurs, timestamps.

## Frontend

- **BrokerConnectionPanel** : statut connexion, bouton sync, dernière sync, déconnexion
- **CtraderConnectDialog** : formulaire Client ID, Client Secret, Access Token, Account ID
- **MetaApiConnectDialog** : formulaire token + account ID
- **SyncHistoryDialog** : DataTable des `sync_logs`
- Bouton sync (pi-sync) dans la vue Comptes à côté de chaque compte

## Couverture des tests

| Test | Scénario | Statut |
|------|----------|--------|
| `CredentialEncryptionServiceTest` (6) | Encrypt/decrypt, wrong key, corrupted data | ✅ |
| `DealNormalizerTest` (6) | cTrader + MetaApi deals, skip non-closing, direction | ✅ |
| `MetaApiConnectorTest` (6) | fetchDeals, cursor, testConnection, refresh no-op | ✅ |
| `CtraderConnectorTest` (4) | refreshCredentials, normalize deals, buildMessage | ✅ |
| `BrokerSyncServiceTest` (5) | sync flow, wrong user, inactive, cursor, provider routing | ✅ |

**Total : 27 tests, 70 assertions** pour les connecteurs.

Suite complète : **740 tests, 2040 assertions**, aucune régression.

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
