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
1. Clic "Connecter cTrader" → redirection vers cTrader OAuth
2. L'utilisateur autorise l'accès → callback avec tokens
3. Clic "Synchroniser" → récupération automatique des trades fermés

**MetaApi (MT4/MT5)** :
1. Clic "Connecter MT4/MT5" → formulaire credentials
2. L'utilisateur fournit son token MetaApi + ID compte MetaApi
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

### OAuth2 cTrader

Flow Authorization Code standard :
- `GET /broker/ctrader/authorize?account_id=X` → redirect vers cTrader
- Callback : échange code → tokens → stockage chiffré → redirect vers le frontend
- State parameter avec expiry 5min pour prévenir CSRF

## Endpoints API

| Méthode | Route | Description |
|---------|-------|-------------|
| POST | `/broker/connections` | Créer connexion MetaApi |
| GET | `/broker/connections?account_id=X` | Statut connexion |
| POST | `/broker/connections/{id}/sync` | Déclencher sync |
| DELETE | `/broker/connections/{id}` | Supprimer connexion |
| GET | `/broker/connections/{id}/logs` | Historique syncs |
| GET | `/broker/ctrader/authorize?account_id=X` | OAuth cTrader |
| GET | `/broker/ctrader/callback` | Callback OAuth |

## Tables

### `broker_connections`
Un enregistrement par compte. Credentials chiffrés, statut (PENDING/ACTIVE/ERROR/REVOKED), curseur de sync incrémentale, timestamps dernière sync.

### `sync_logs`
Audit trail : connexion, deals récupérés/importés/ignorés, erreurs, timestamps.

## Frontend

- **BrokerConnectionPanel** : statut connexion, bouton sync, dernière sync, déconnexion
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

Variables d'environnement :
```
BROKER_ENCRYPTION_KEY=<base64 encoded 32-byte key>
CTRADER_CLIENT_ID=<from openapi.ctrader.com>
CTRADER_CLIENT_SECRET=<from openapi.ctrader.com>
CTRADER_REDIRECT_URI=http://2a.journal.local/api/broker/ctrader/callback
```

Pour MetaApi : le trader crée son compte sur metaapi.cloud et fournit son token + account ID.

## Dépendances ajoutées

- `guzzlehttp/guzzle` ^7.10 — client HTTP (MetaApi REST + cTrader OAuth)
- `textalk/websocket` ^1.5 — client WebSocket synchrone (cTrader deals)
