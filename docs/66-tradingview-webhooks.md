# 66 - Webhooks TradingView → exécution broker

## Objectif

Permettre à un utilisateur de coller une URL dans le champ « Webhook URL » d'une alerte TradingView pour qu'à chaque déclenchement, le journal enregistre l'ordre et déclenche son placement automatique sur le broker lié au compte cible.

Cette feature livre **toute la plomberie d'ingestion** (auth, dédup, audit, UI de gestion, feature flag) et **les stubs des méthodes broker** d'exécution. L'implémentation réelle de `placeOrder()` sur chacun des 4 connecteurs est planifiée broker par broker dans des tickets de suite — voir la section *Limitations* en bas.

## Pourquoi

La spec v5 §2.9.1 mentionne TradingView en Phase 2 sans détail. Aujourd'hui un utilisateur qui veut automatiser un signal TradingView doit passer par un bot externe (Zapier, n8n, script perso) qui réécrit l'ordre vers le broker. Cette feature interne :

- ramène la chaîne **TV → broker** dans le journal (audit + historique au même endroit que les trades manuels)
- s'appuie sur les connexions broker déjà persistées par compte (`broker_connections`)
- impose un format de payload canonique : le journal fournit le **template JSON prêt à coller** dans l'alerte TV, l'utilisateur n'a rien à inventer.

## Architecture

### Schéma DB (migration `020_tradingview_webhooks.sql`)

Deux tables additives :

- `tradingview_webhooks` — une ligne par webhook exposé. Stocke `url_token_hash` (SHA-256 du token URL) + `body_secret_hash` (SHA-256 du secret JSON). Aucun secret en clair persisté. `status` ∈ `ACTIVE | REVOKED`. Compteurs `total_triggered` / `total_errors` + `last_triggered_at`.
- `tradingview_alert_events` — un log row par POST entrant. Champs : `webhook_id`, `external_alert_id`, `payload_raw` (JSON, avec `secret` redacté à `***`), `status` ∈ `RECEIVED | REJECTED | PROCESSED | FAILED | DUPLICATE`, `reject_reason`, `error_message`, `created_order_id`.

`UNIQUE (webhook_id, external_alert_id)` sur les events sert de dédup race-safe : TradingView ré-émettant la même alerte ne crée pas deux ordres.

### Endpoint d'ingestion

```
POST /api/webhooks/tradingview/{token}
```

- **Pas d'AuthMiddleware** (TV ne sait pas envoyer de header `Authorization`).
- `FeatureFlagMiddleware` lié au réglage `tradingview_webhooks_enabled` (cf. *Activation* ci-dessous).
- `RateLimitMiddleware` : 120 req/min/IP par défaut (cf. `TRADINGVIEW_RATE_MAX`).
- Le contrôleur retourne **toujours HTTP 200** avec `{ "received": true }`. Chaque outcome (token invalide, secret invalide, doublon, échec broker, etc.) est tracé dans `tradingview_alert_events` mais jamais exposé au caller public — pour éviter qu'un attaquant fingerprint un token valide.

### Service `TradingViewWebhookService::process()`

Pipeline strictement séquentiel, chaque étape loggue son event puis return :

1. `hash('sha256', $token)` → lookup `tradingview_webhooks`. Pas trouvé → `REJECTED/INVALID_TOKEN` (`webhook_id` = NULL dans l'event).
2. `status != ACTIVE` → `REJECTED/WEBHOOK_REVOKED`.
3. `hash_equals(body_secret_hash, hash('sha256', payload.secret))` — comparaison constant-time. Différent → `REJECTED/INVALID_SECRET`.
4. Validation payload (cf. ci-dessous). KO → `REJECTED/INVALID_PAYLOAD` avec détail dans `error_message`.
5. `existsByWebhookAndAlertId(webhook_id, alert_id)` → `DUPLICATE` (event avec `external_alert_id = NULL` pour ne pas violer le UNIQUE).
6. Broker connection : `BrokerConnectionRepository::findByAccountId()`. Absent → `REJECTED/NO_BROKER`. Pas `ACTIVE` → `REJECTED/BROKER_INACTIVE`.
7. `OrderService::createFromWebhook()` → crée la position + l'ordre PENDING avec `trigger_type = WEBHOOK` dans `status_history`.
8. `Connector::placeOrder()` → succès `PROCESSED` (incrémente `total_triggered`), erreur `BrokerOrderException` → `FAILED/BROKER_ERROR` (incrémente `total_errors`, l'ordre PENDING reste en base pour l'utilisateur).

### Authentification : URL token + body secret

TradingView ne fait que de la **substitution de variables** sur le template ; il n'exécute pas de crypto à l'envoi. Donc la « signature HMAC » initialement discutée est en pratique un **secret partagé statique** placé dans le JSON. Combiné au token de l'URL (lui aussi statique), on a deux secrets indépendants :

- Le **token URL** identifie le webhook (et donc l'utilisateur + le compte cible).
- Le **secret body** est un deuxième facteur qui survit à une fuite d'URL via logs/proxies/captures d'écran.

Les deux sont générés via `bin2hex(random_bytes(24))` (192 bits chacun) et stockés **uniquement hashés** côté DB. Affichage **one-shot** au moment de la création : si l'utilisateur les perd, il révoque + recrée.

### Payload canonique

L'app génère le template prêt à coller dans le champ « Message » de l'alerte TradingView :

```json
{
  "secret": "<body_secret_clair>",
  "alert_id": "{{ticker}}-{{interval}}-{{timenow}}",
  "symbol": "{{ticker}}",
  "direction": "BUY",
  "order_type": "MARKET",
  "entry_price": "{{close}}",
  "size": 1.0,
  "sl_points": 50,
  "targets": [{"points": 100, "size": 0.5}, {"points": 200, "size": 0.5}],
  "setup": ["TradingView"],
  "notes": "TradingView alert {{strategy.order.action}}"
}
```

Champs obligatoires : `secret`, `symbol`, `direction` (`BUY` ou `SELL`), `entry_price` > 0, `size` > 0, `sl_points` > 0.

Champs optionnels : `order_type` (default `MARKET`), `alert_id` (sans alert_id, pas de dédup), `targets`, `setup`, `notes`, `be_points`, `be_size`.

### CRUD côté compte

Sous `/api/accounts/{id}/webhooks` (AuthMiddleware + feature flag) :

- `GET` — liste des webhooks du compte. Pas d'URL ni de secret (hashes inrécupérables).
- `POST { name }` — crée, renvoie `{ webhook, url, body_secret, template }` (one-shot).
- `DELETE /{webhookId}` — révoque (status `REVOKED`, garde la ligne pour l'audit).
- `GET /{webhookId}/events?page=&per_page=` — historique paginé des events.

Limite hardcodée : **10 webhooks max par compte** (`AccountWebhookService::MAX_PER_ACCOUNT`).

### UI

Dans `AccountsView`, un nouveau bouton ⚡ (icône `pi-bolt`, jaune) à côté du bouton broker. Visible uniquement si `features.tradingviewWebhooks === true`. Ouvre un dialog hébergeant `TradingViewWebhooksPanel.vue` qui :

- liste les webhooks du compte (nom, statut, dernier déclenchement, compteurs)
- propose un formulaire « Créer » (nom obligatoire, max 120 caractères)
- à la création, affiche une **modale one-shot** avec URL + secret + template JSON (copy-to-clipboard), avec un avertissement explicite que ces valeurs ne seront plus affichées
- propose pour chaque webhook : « Voir l'historique » (table paginée des events) + « Révoquer » (avec confirmation PrimeVue)

i18n : namespace `webhook.tradingview.*` dans `fr.json` et `en.json` (sync vérifié).

## Activation (réglage admin, 2026-06-01)

Le flag `tradingview_webhooks_enabled` est désormais un **réglage de plateforme** géré via `PlatformSettingsService` (BO admin → *Paramètres de la plateforme*), au même titre que `broker_auto_sync_enabled`. Chaîne de résolution : **BDD (toggle admin) > variable d'env `TRADINGVIEW_WEBHOOKS_ENABLED` (legacy, fallback) > `false`**. Conséquences :

- l'admin **active/désactive la disponibilité** de la feature à chaud depuis l'écran Paramètres, sans redéploiement ;
- une fois activée, **chaque utilisateur gère ses propres webhooks** par compte (bouton ⚡ dans `AccountsView`, inchangé) ;
- le flag pilote à la fois l'endpoint `/features` (affichage du bouton ⚡ côté SPA), le `FeatureFlagMiddleware` de l'ingestion `POST /webhooks/tradingview/{token}` et le CRUD `/accounts/{id}/webhooks` ;
- **OFF par défaut** (cohérent avec la règle « connecteurs broker OFF tant que pas validés sandbox »).

> Note : le même rapatriement a été appliqué à `broker_auto_sync_enabled`, qui était déclaré dans `knownSettings()` mais dont `/features` et le middleware lisaient encore l'env brut — le toggle admin est maintenant effectif pour les deux.

## Sécurité

- Tokens et secrets stockés **SHA-256 uniquement** — pas de chiffrement réversible, pas de retrieval possible.
- `hash_equals()` pour la comparaison du secret (constant-time, anti timing-attack).
- Endpoint d'ingestion répond **toujours 200** : ne distingue jamais un token invalide d'un secret invalide vers le caller public.
- Rate limit par IP via `RateLimitMiddleware` existant.
- Feature flag OFF par défaut en prod (per memory `project_broker_features_disabled_prod.md`).
- Le `secret` du payload est redacté (`***`) avant écriture dans `tradingview_alert_events.payload_raw` — il n'apparaît jamais dans la table d'audit.
- Pas de logging stdout du payload complet — seuls `webhook_id`, `status`, `account_id` sont émis.

## Tests

- `tests/Unit/Services/Broker/ConnectorOrderMethodsTest.php` — sanity check que les 4 connecteurs throw `BrokerOrderException(NOT_IMPLEMENTED)` pour `placeOrder`/`cancelOrder`/`closePosition`.
- `tests/Unit/Exceptions/BrokerOrderExceptionTest.php` — propagation du provider code et du payload.
- `tests/Integration/Webhooks/TradingViewWebhookFlowTest.php` — 10 scénarios couvrant tous les `reject_reason`, le `DUPLICATE`, le `FAILED/BROKER_ERROR` (via un FakeConnector qui throw à la demande), le happy path `PROCESSED`, et la redaction du secret dans `payload_raw`.
- `frontend/src/components/webhook/__tests__/TradingViewWebhooksPanel.spec.js` — empty state, list rendering, disabled state du bouton create, ouverture du one-shot modal avec URL+secret+template visibles.

## Implémentation des connecteurs

Les 4 connecteurs implémentent `placeOrder/cancelOrder/closePosition` :

| Broker | Transport | Endpoint clé | Spécificités |
|--------|-----------|---------------|--------------|
| **MetaApi** | REST | `POST /users/current/accounts/{id}/trade` | Mapping `direction × order_type` → `ORDER_TYPE_*`. Succès = `stringCode ∈ TRADE_RETCODE_DONE/DONE_PARTIAL/PLACED`, sinon `BrokerOrderException` avec le code MT5. |
| **BingX** | REST signé HMAC | `POST /openApi/swap/v2/trade/order` (DELETE pour cancel, `/closePosition` pour close) | USDT-M perp. `side`+`positionSide` (BUY→LONG, SELL→SHORT). SL/TP envoyés comme JSON imbriqués. Close partiel non supporté (à faire via `placeOrder` reduceOnly). |
| **cTrader** | WebSocket Protobuf | `ProtoOANewOrderReq` / `ProtoOACancelOrderReq` / `ProtoOAClosePositionReq` | Auth deux étapes (Application puis Account). `symbolId` résolu via `ProtoOASymbolsListReq`. Volume en 1/100 de lot (size × 100, entier). Session refermée systématiquement (finally try/catch). |
| **Ouinex** | GraphQL | mutations `place_margin_order`, `cancel_margin_order`, `close_margin_position` | JWT auto-rafraîchi via `ensureJwt()` si expiré. `BUY`→`LONG`, `SELL`→`SHORT`. ⚠️ Les signatures de mutations sont best-effort (la doc Ouinex publique ne couvre que le read-side) — à valider/ajuster contre la sandbox. |

Toutes les erreurs broker (margin insuffisante, symbole inconnu, transport KO, credentials invalides…) sont mappées en `BrokerOrderException` avec un `providerCode` parlant (`INSUFFICIENT_MARGIN`, `UNKNOWN_SYMBOL`, `TRANSPORT_ERROR`, `INVALID_CREDENTIALS`, `BROKER_REJECTED`, `UNSUPPORTED_ORDER`, etc.). Le service webhook les attrape et marque l'event `FAILED/BROKER_ERROR` avec `[<providerCode>] <message>` dans `error_message`.

### Tests par broker

- `MetaApiConnectorTest` : market BUY, limit avec openPrice, rejet `TRADE_RETCODE_NO_MONEY`, credentials manquants, cancel, close full + partiel
- `BingxConnectorTest` : market BUY → LONG, limit SELL → SHORT (vérifie `positionSide` + `price` en query), erreur 101104, credentials manquants, cancel (DELETE), close full, partial close explicitement rejeté
- `CtraderConnectorTest` : market BUY avec conversion volume×100, limit avec `limitPrice`, symbole inconnu, `ProtoOAErrorRes` mappé en `BROKER_REJECTED`, credentials manquants, cancel, close full + partial
- `OuinexConnectorTest` : market BUY, limit SELL → SHORT, erreur GraphQL → `BROKER_REJECTED`, auto-signin si JWT absent, credentials manquants, cancel, close full + partial

## Limitations restantes

- **Sandbox non testés** : aucun des 4 brokers n'a été exercé contre un compte de test réel. Les implémentations suivent les specs publiques (et pour Ouinex, des hypothèses sur le schéma GraphQL côté mutation). Avant activation prod, **chaque broker doit être validé contre sa sandbox**. Les tests unitaires couvrent le shape du code, pas l'acceptation broker.
- **HMAC vrai** : impossible avec TradingView (templates statiques, pas de crypto). Si TV ajoute un jour le support d'un header `X-Signature` calculé, on pourra durcir le pipeline en remplaçant le secret body par une signature.
- **Whitelist IP TradingView** : non implémentée. Les IPs publiées par TV pourraient être whitelistées comme défense en profondeur.
- **Retry automatique** : non implémenté. Un event `FAILED/BROKER_ERROR` reste tel quel ; il faut soit recréer l'alerte côté TV, soit déclencher manuellement l'ordre PENDING.
- **Pas de mapping signal → preset** : on a privilégié l'option « payload complet » plutôt que « payload minimal mappé côté app ». Si le besoin émerge, un futur ticket peut ajouter des presets par signal.
- **BingX partial close** : pas géré via `closePosition()` (BingX exige un ordre opposé reduceOnly). Si on a besoin, ajouter un wrapper côté service.

Voir `docs/evolutions.md` pour le suivi.
