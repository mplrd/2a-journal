# Évolutions à prévoir

Liste des améliorations identifiées en cours de route mais sortant du scope d'une feature en cours. À planifier / prioriser quand les branches concernées seront mergées.

## Statut / calculs

### RR négatif sur un compte 100% shorts en profit

**Contexte** : sur un compte qui n'a que des trades SELL et qui est globalement profitable, le R:R affiché ressort négatif. Symptôme probable : un signe non inversé quelque part pour les SELL dans le calcul du R:R agrégé (StatsRepository ou StatsService).

**Diag 2026-04-24** :
- Code relu : `TradeService::calculateFinalMetrics` (l.568) calcule `rr = totalPnl / (size * slPoints)` où `totalPnl` agrège des `partial_exits.pnl` déjà signés via `directionMultiplier` (l.287) → OK.
- Agrégation : `StatsRepository::getOverview` et `dimensionStatsSelect` font simplement `AVG(t.risk_reward)` sur la valeur stockée → OK.
- Seeder démo : formule cohérente BUY/SELL, avg_rr SELL = +0.537 en démo.
- Tentative de reproduction en réimportant le compte réel : **non reproductible au 2026-04-24**.
- Guard test posé : `StatsFlowTest::testAvgRrPositiveWhenAllSellsProfitable` (crée 3 SELL gagnants via l'API, vérifie `avg_rr > 0` sur `/stats/overview` et `/stats/by-direction`).

**À faire si ça revient** :
- Screenshot + URL (filtres actifs dans la querystring).
- Snapshot du trade concerné : `SELECT pnl, risk_reward, direction, entry_price, avg_exit_price, size, sl_points FROM trades t JOIN positions p ON p.id=t.position_id WHERE ...`.
- Vérifier si `risk_reward` est négatif au niveau d'un trade (bug de persistence) ou si l'agrégation est en cause.

**Repéré le** : 2026-04-23.
**Statut** : en veille, non reproductible, filet en place.
**Priorité** : haute si ça revient (fausse les stats).

---

## Code / conventions

### Schema fix — unique constraint setups vs soft-delete

Aujourd'hui : `UNIQUE KEY uk_setups_user_label (user_id, label)` ne tient pas compte de `deleted_at`. Conséquence : un setup soft-deleted bloque la création/le rename d'un autre setup vers le même label, même longtemps après. Workaround actuel (étape 61) : on hard-delete le ghost soft-deleted au moment du rename.

Vrai fix : remplacer la contrainte par une contrainte qui n'applique l'unicité qu'aux lignes actives, via colonne générée :

```sql
ALTER TABLE setups
    ADD COLUMN active_label VARCHAR(100)
        AS (IF(deleted_at IS NULL, label, NULL)) VIRTUAL,
    DROP INDEX uk_setups_user_label,
    ADD UNIQUE KEY uk_setups_user_active_label (user_id, active_label);
```

MariaDB autorise plusieurs NULL dans une UNIQUE → les soft-deleted ne se gênent plus. Migration à planifier hors urgence (pas de bug bloquant après le workaround). Une fois en place, on pourra retirer le hard-delete du ghost dans `SetupService::update` et conserver l'historique soft-deleted complet.

Le même problème existe pour `symbols` (`uk_symbols_user_code`) et probablement d'autres tables avec soft-delete + unique : à auditer en même temps.

---

## Intégrations broker

### E-02 — Connexion Ouinex (broker-sync, pas import fichier)

**Contexte** : ticket initial `retours-beta-tests.md#E-02` formulé "Source d'import : Ouinex". Après lecture de la doc Ouinex (collection Postman publique servie sous `api.ouinex.com`) le 2026-05-05, **pivot architectural assumé** : Ouinex est une API GraphQL (`https://live-api.ouinex.com/graphql`), pas un broker qui exporte un format CSV exploitable. L'export CSV existe (`orders-history_*.csv`) mais c'est de l'**order-by-order** (un leg par ligne, pas du round-trip), incompatible avec le pipeline d'import actuel qui attend des lignes round-trip type FTMO (entry_price + exit_price + pnl sur la même ligne).

→ On raccroche au pattern `ConnectorInterface` existant (`api/src/Services/Broker/`, déjà utilisé par `CtraderConnector` et `MetaApiConnector`), pas au pipeline `import/`. UX : ajout d'Ouinex au dropdown des providers dans le form de connexion broker au niveau du compte (entrée déjà présente pour cTrader / MetaApi).

**Authentification Ouinex** : Bearer JWT obtenu via mutation `service_signin` à partir d'une API key (created via `create_api_key` côté UI Ouinex). Permissions à activer côté API key : `closed_orders`, `open_orders`, `closed_margin_positions`, `open_margin_positions`. Refresh JWT à expiration via re-`service_signin`.

#### Phase 1 — Derivatives

**Scope** : `closed_margin_positions` GraphQL query (paginé `PagerInput { offset, limit }`), filtrage incrémental via `start_ts > cursor`. Retour API contient `entry_price`, `exit_price`, `pnl`, `side`, `amount`, `leverage`, `stop_loss`, `take_profit`, `start_ts`, `end_ts`, `instrument_id`, `margin_position_id` → mapping **direct** vers le format deal standard (cf. `DealNormalizer::normalizeMetaApiDeal` comme template). Couvre les usages user "court terme cryptos sur dérivés" + "trading tradfi en usdt sur dérivés".

**Sous-tâches** :
- Ajouter `BrokerProvider::OUINEX` (+ vérifier le check_constraint sur `broker_connections.broker_provider` — migration additive si nécessaire)
- `OuinexConnector implements ConnectorInterface` :
  - `testConnection()` → round-trip `service_signin`
  - `fetchDeals($credentials, $sinceCursor)` → boucle paginée sur `closed_margin_positions` + normalisation deals + cursor = max(`start_ts`)
  - `refreshCredentials()` → re-`service_signin` si JWT expiré
- Étendre `DealNormalizer` avec `normalizeOuinexMarginPosition()` (pour cohérence avec le pattern existant)
- Frontend : ajouter Ouinex au dropdown providers du form de connexion broker (form champs : `api_key`, `api_secret` ou équivalent selon ce que renvoie `create_api_key`)
- Tests backend : unit `OuinexConnector` avec HTTP mocké + intégration via `BrokerSyncService`
- Tests frontend : form de connexion accepte les creds Ouinex
- Doc : `docs/<prochain-num>-broker-sync-ouinex.md`

**Branche dédiée** : `feat/import-ouinex` (déjà créée le 2026-05-05, vide pour l'instant).

#### Phase 2 — Spot + LegPairingService

**Scope** : `closed_orders` GraphQL query (paginé), retour order-by-order (un leg par ligne : `order_id`, `side`, `price`, `executed_quantity`, `executed_quote_qty`, `instrument_id`, `created_at`, `updated_at`). Couvre l'usage user "trading swing et invest crypto en spot" (BTCEUR, BTCUSDT, etc.).

**Sous-tâches** :
- Service `OuinexSpotPairingService` (ou méthode privée du connector si suffisant) :
  - Group orders par `instrument_id`
  - Trier par `updated_at` (ou `created_at`)
  - FIFO matching : empile les buys, dépile au prochain sell (et vice-versa pour les SELL trades, si le user shorte sur spot — rare mais possible)
  - Gestion fills partiels : un sell de 0.5 BTC contre un buy de 1 BTC → produit 1 trade clos + 0.5 BTC en stack restant
  - Gestion fees : retrancher les fees côté `pnl` (ou les exposer en field séparé selon ce que retourne l'API — à investiguer en Phase 2)
  - Calcul P&L : `(sell_executed_quote_qty - buy_executed_quote_qty)` ajusté des fees
- Branche dédiée : `feat/import-ouinex-spot` (à créer après merge Phase 1)
- Doc séparée : `docs/<num>-broker-sync-ouinex-spot.md`

**Risques Phase 2** :
- Stack restant entre deux syncs : il faut persister l'état de pairing (lots non clôturés) entre les runs, sinon on perd le matching après un sync incrémental. Probablement un nouveau champ JSON sur `broker_connections` ou une table dédiée `ouinex_pending_legs`.
- Cold sync vs incremental sync : au premier sync on doit pouvoir tirer toute l'histoire, pas juste les X derniers jours.
- Conversions, dust : à voir si on les ignore ou si on les remonte.

**Repéré le** : 2026-05-05.
**Priorité** : Phase 1 = haute (déclenchera la livraison E-02 partielle), Phase 2 = moyenne (peut attendre la fin de Phase 1 et un peu de feedback user).

---

### Connexion BingX — Phase 2 (Coin-M Perpetual + Standard Contracts)

**Contexte** : la Phase 1 BingX (cf. `docs/64-broker-sync-bingx.md`, branche `feat/import-bingx`) couvre uniquement **USDT-M Perpetual** parce que c'est le seul produit BingX qui expose un endpoint `positionHistory` natif (round-trip avec entry/exit/pnl agrégés, directement consommable par notre pipeline). Pour les deux autres produits dérivés :

- **Coin-M Perpetual (`cswap`)** : pas de `positionHistory`. Les fills sont disponibles via `/openApi/cswap/v1/trade/allFillOrders` (avec `realizedPnl` par fill).
- **Standard Contracts (`contract`)** : pas de `positionHistory` ni de `openOrders` dédiés. Juste `/openApi/contract/v1/allOrders` (history mixte) et `/openApi/contract/v1/allPosition` (live).

→ Il faut **synthétiser les positions clôturées** à partir des order fills (effort équivalent à la Phase 2 spot Ouinex décrite plus haut).

**Sous-tâches** :
- `BingxCswapConnector` (ou méthodes dédiées dans `BingxConnector`) :
  - Réutiliser l'auth HMAC déjà en place.
  - Pour les closed positions : fetch `allFillOrders` paginé par symbol, grouper les fills par `orderId` ou par séquence d'open/close pour reconstituer un round-trip (volume aggregated, prix moyen pondéré, pnl somme des realizedPnl).
  - Pour les open positions : direct mapping comme USDT-M (les endpoints `cswap/v1/user/positions` et `contract/v1/allPosition` existent).
- `BingxStandardConnector` :
  - Idem mais sur l'endpoint `contract/v1/allOrders` (history mixte) avec filtrage status FILLED → pairing FIFO ou par séquence.
  - À voir si standard contracts ont vraiment des pending orders (la doc ne les mentionne pas explicitement).
- Persistance de l'état de pairing entre syncs (lots non clôturés) — même problème que la Phase 2 Ouinex spot. Probable champ JSON sur `broker_connections` ou table dédiée.
- Tests TDD comme pour la Phase 1.
- Branche dédiée : `feat/import-bingx-cm-std` (à créer après merge Phase 1 sur develop).
- Doc séparée : `docs/<prochain-num>-broker-sync-bingx-cm-std.md`.

**Réutilisation** :
- Services de diff (`BrokerOpenSyncService`, `BrokerOrderSyncService`) déjà agnostiques au provider depuis le refactor enum-driven (commit `231ea12`) → aucune modification nécessaire.
- `DealNormalizer` étendu avec `normalizeBingxCswap*` / `normalizeBingxStandard*` méthodes.
- DI et controller juste un peu étendus si on garde un seul `BrokerProvider::BINGX` (vraisemblable, l'utilisateur configure une API key qui couvre les 3 produits).

**Risques** :
- Identifiers d'orders Coin-M ≠ USDT-M (préfixe distinct côté external_id pour ne pas collider).
- Variations de schéma payload entre produits — chaque normalize* doit être robuste aux fallbacks.
- L'utilisateur peut trader en Coin-M sans jamais avoir d'open position visible (fermeture rapide) → couverture des symbols à enrichir (cf. limitation suivante).

**Repéré le** : 2026-05-11.
**Priorité** : moyenne. À déclencher après feedback Phase 1 BingX en prod.

---

### BingX Phase 1 — couverture des symbols élargie

**Contexte** : aujourd'hui `BingxConnector::fetchDeals` et `fetchClosedOrders` énumèrent les symbols depuis `user/positions` (positions actuellement ouvertes). Conséquence : si l'utilisateur ferme toutes ses positions sur un symbol et arrête de sync pendant des semaines, ce symbol n'est plus interrogé pour son `positionHistory` → trou d'historique.

En pratique le scheduler tourne toutes les 15 min (cf. étape 31), donc l'écart entre 2 syncs reste très inférieur à la fenêtre 3 mois supportée par BingX. La limitation se déclenche uniquement sur un usage discontinu.

**À faire** : persister un set "symbols seen" en base (champ JSON sur `broker_connections` ou table associée) — à chaque sync, on union le set avec les symbols vus dans `user/positions`. Le `fetchDeals` itère ce set complet plutôt que juste l'instantané.

**Repéré le** : 2026-05-11.
**Priorité** : basse, à faire si un user signale le bug.

---

### BingX Phase 1 — vérifier les fields `positionHistory.list[]` au premier test live

**Contexte** : la doc API BingX ne détaille **pas** les fields exacts retournés dans `positionHistory.list[]` (cf. `docs/64-broker-sync-bingx.md`). Le `DealNormalizer::normalizeBingxClosedPosition` utilise des fields **inférés** depuis la schéma des positions ouvertes et la convention BingX : `avgPrice` pour l'entry, `closeAvgPrice` pour l'exit, `realisedProfit` pour le pnl, `closeTime`/`openTime` en millisecondes, `positionAmt` pour la size.

Les fallbacks `??` empêchent l'erreur fatale, mais si BingX utilise d'autres noms en réalité, le normalize retournera `null` ou des champs vides → le user n'aura pas ses closed positions.

**À faire** : au premier sync live d'un user BingX, vérifier les logs sync_logs et `api/last_sync_error`, OU faire un dump direct via curl signé sur `positionHistory` pour confirmer le payload. Ajuster `normalizeBingxClosedPosition` si drift détecté.

**Repéré le** : 2026-05-11.
**Priorité** : haute dès qu'un user BingX réel existe (sinon on rate ses imports). En attendant : sans utilisateur BingX réel sur la plateforme, pas de pression immédiate.

---

### Cipher du chiffrement at-rest des credentials broker — passer de CBC à GCM

**Contexte** : audit privacy de la feature E-02 Ouinex (2026-05-07). `api/src/Services/Broker/CredentialEncryptionService.php:7` utilise **`aes-256-cbc`** pour chiffrer le blob de credentials (clé/secret/JWT par connexion broker). CBC n'est pas un mode AEAD : pas d'authentification intégrée du ciphertext, donc malléabilité possible si un attaquant obtient un accès en écriture à la base. La détection se fait indirectement via `json_decode` à la décryption.

**À faire** : migrer vers `aes-256-gcm` (AEAD natif, tag d'authentification stocké à côté du IV+ciphertext). Migration des données : décrypter avec l'ancien cipher CBC, ré-encrypter avec GCM, en utilisant un script CLI dédié (pattern similaire à la rotation de `BROKER_ENCRYPTION_KEY` mentionnée dans `docs/31-broker-auto-sync.md`).

**Repéré le** : 2026-05-07.
**Priorité** : basse (le risque concret nécessite un accès en écriture à la base = scénario déjà compromis). À planifier hors urgence, idéalement en même temps qu'une rotation de la clé.

---

### Rate-limit sur POST /broker/connections

**Contexte** : audit sécurité de la feature E-02 Ouinex (2026-05-07). La route `POST /broker/connections` (création d'une connexion broker, qui prend des credentials API en body) n'a aucun `RateLimitMiddleware` — seules `authMiddleware`, `requireSubscription` et `brokerFeatureFlag` la protègent. Pas de risque d'amplification vers l'API tierce (le JWT Ouinex/cTrader/MetaApi est négocié lazy au premier sync), mais un compte authentifié pourrait spammer la route à des fins de fuzzing/DoS interne.

**À faire** : câbler un `RateLimitMiddleware` doux (ex. 10 req/min/user) sur `POST /broker/connections` dans `api/config/routes.php`. S'applique aux 3 providers (CTRADER, METAAPI, OUINEX) — pas spécifique à Ouinex.

**Repéré le** : 2026-05-07.
**Priorité** : basse (pas de surface critique exposée).

---

## Docs

### Tracker beta : splitter "DONE" en "OK prod" / "En attente de livraison"

**Contexte** : `docs/retours-beta-tests.md` a aujourd'hui un tableau `✅ DONE` qui mélange deux états bien distincts :
- mergé sur `develop` mais pas encore sur `main` (= pas en prod) ;
- mergé sur `main` (= effectivement livré aux beta-testeurs).

**À faire** : scinder le tableau DONE en deux sous-tableaux **"En attente de livraison"** (develop) et **"Livré en prod"** (main), avec un critère clair (présence sur `main` au moment de la mise à jour). Mettre à jour la convention en tête du fichier en conséquence.

**Repéré le** : 2026-05-04 (après merge D-02).
**Priorité** : basse, à faire à la prochaine mise à jour du tracker.

---

## UX / vocabulaire

### Incohérence « symbole » vs « actif » dans grilles et modales

**Contexte** : aujourd'hui les labels i18n parlent de `positions.symbol` / `trades.symbol` (« Symbole ») dans les grilles trades/positions, les en-têtes de colonnes, et les modales (TradeForm, CloseTradeDialog, etc.), alors que la valeur affichée est en fait le **code de l'actif** (NASDAQ, BTCUSD, EURUSD, …) — c'est-à-dire le ticker / le nom commun de l'instrument, pas un « symbole » au sens graphique.

**À faire** : choisir une terminologie cohérente :
- soit renommer toutes les clés `*.symbol` → `*.asset` (et label « Actif » / « Asset »), pour refléter ce qu'on affiche réellement ;
- soit garder « Symbol/Symbole » mais clarifier dans la doc/UI ce qu'il représente.

Recommandation : **renommer en `asset`** — plus naturel pour un utilisateur non-dev. Impact : i18n (fr/en/...), composants Vue (props, columns, headers), stores éventuels. Le champ DB `positions.symbol` peut rester (interne), seul l'affichage UI bouge.

**Repéré le** : 2026-05-13 (pendant feature `feat/close-trade-actions-grid`).
**Priorité** : moyenne — pas bloquant mais c'est le genre d'incohérence qui rend la doc et le support client maladroits.

---

## Page « Robots » / « Bots de trading » dans le menu principal

**Contexte** : aujourd'hui les webhooks TradingView sont accessibles via un bouton ⚡ sur la grille des comptes (`AccountsView`) → dialog par compte → liste de webhooks → modale d'historique **par webhook**. Pour un utilisateur avec plusieurs comptes et plusieurs webhooks, voir « ce qui s'est passé récemment » oblige à cliquer compte par compte, webhook par webhook. Pas scalable.

**À faire** : nouvelle entrée de menu principal « Robots » (ou « Bots de trading ») qui pourrait héberger :
- une vue agrégée cross-comptes des événements webhook (`tradingview_alert_events`), filtres par compte / statut / webhook
- la liste des webhooks de tous les comptes, avec leur statut + compteurs en ligne (au lieu d'aller dans chaque compte)
- à terme, point d'entrée pour d'autres types de bots / automatisations si on en ajoute

Ouvre aussi la question UX : garde-t-on le bouton ⚡ sur `AccountsView` en raccourci, ou on déplace tout vers la nouvelle page ? Le raccourci reste utile dans le flow « je viens de créer un compte broker, je veux brancher un webhook ».

**Repéré le** : 2026-05-18 (discussion suite à `feat/broker-place-order`).
**Priorité** : moyenne — dépend de combien d'utilisateurs adoptent vraiment les webhooks. Tant que c'est 1 webhook par utilisateur, la vue actuelle suffit.

---

## BingX — discriminer exit_type (TP / SL / BE / MANUAL) sur partial_exits issus du sync

**Contexte** : la reconstruction fills-based (doc 67) tague tous les partial exits BingX en `MANUAL`. `/openApi/swap/v2/trade/allOrders` ne distingue pas un fill issu d'un TP, d'un SL ou d'un market close de manière fiable sur le champ `type`. L'utilisateur peut éditer après import mais c'est une perte d'info qui pollue les stats par exit_type.

**À faire** : étudier si BingX expose un champ qui désambiguïse l'origine du fill (un trigger order id séparé qu'on pourrait croiser avec une liste de TP/SL orders posés ?), sinon faire match heuristique côté code (proximité avec SL/TP price connus de la position) avec un fallback `MANUAL`.

**Repéré le** : 2026-05-20 (doc 67).
**Priorité** : moyenne — affecte la précision des stats "exit type" mais ne casse rien.

## BingX — inclure les frais (commission) dans le P&L reconstruit

**Contexte** : `/allOrders` retourne `commission` par fill mais le journal ne modélise pas les frais séparément aujourd'hui. Le P&L stocké côté trade est donc gross, pas net. Pour un trader scalp avec beaucoup de fills, l'écart peut être significatif.

**À faire** : décision produit. Options :
- Soustraire commission de `partial_exits.pnl` au moment du sync (P&L net partout, mais on perd la transparence brut/net).
- Ajouter une colonne `commission` séparée sur `partial_exits` et l'agréger côté affichage (plus propre, plus de travail UI).
- Ne rien faire et documenter dans la doc utilisateur que les frais ne sont pas comptés.

**Repéré le** : 2026-05-20 (doc 67).
**Priorité** : haute pour utilisateurs scalp, basse pour swing/position trading.

## BingX — validation sandbox du fills reconstruction sur les vrais comptes

**Contexte** : le reconstructor (doc 67) couvre théoriquement le hedge mode (`positionSide LONG/SHORT` en parallèle), le one-way mode (`positionSide BOTH`), le scaling-in et tous les patterns multi-fills. **Aucun de ces cas n'est testé contre une vraie sandbox BingX**, seulement contre des fixtures unitaires.

**À faire** : créer un compte sandbox BingX, brancher le journal dessus, exécuter une dizaine de scénarios (open + TP partiel + close, scale-in, hedge mode), comparer la base reconstruite vs ce qu'on voit sur BingX. Ajuster si drift.

**Repéré le** : 2026-05-20 (doc 67).
**Priorité** : haute — bloque l'activation prod du sync BingX en confiance.

## BingX — test d'intégration end-to-end (BingxFillSyncFlowTest)

**Contexte** : le refactor doc 67 livre un unit test du reconstructor (8 fixtures), un unit test du connector (22 tests dont 5 mis à jour), et passe la suite intégration (542 tests). Il manque un test intégration BingX-spécifique qui mocke les réponses HTTP de bout en bout et vérifie l'état DB final (positions + trades + partial_exits + symbols_seen + cursor).

**À faire** : `api/tests/Integration/Broker/BingxFillSyncFlowTest.php` avec 2-3 scénarios : (a) 1ère sync avec historique complet reconstruit, (b) sync incrémental avec curseur, (c) idempotence — 2 syncs successifs sur la même donnée produisent un état DB identique.

**Repéré le** : 2026-05-20 (doc 67).
**Priorité** : moyenne — la couverture unitaire est solide mais un test intégration BingX précis verrouille les régressions futures.

## Timezones — afficher les timestamps broker en heure locale utilisateur

**Contexte** : repéré 2026-05-19 pendant le debug BingX. La grille « Historique des synchronisations » affiche `started_at` brut (ex. `19/05/2026 10:22:48`) alors que la valeur est en **UTC** côté DB, et l'utilisateur est en `Europe/Paris` (CEST = UTC+2 en mai). Idem probablement pour `last_sync_at` dans `BrokerConnectionPanel` et pour tout autre timestamp persisté UTC consommé par le frontend sans conversion.

**À faire** : auditer le frontend pour identifier tous les timestamps broker (sync_logs, connection, etc.) et les passer par le `Intl.DateTimeFormat` côté Vue avec la TZ utilisateur (déjà disponible dans `useAuthStore().profile.timezone`). Centraliser via un composable `useFormatDateTime()` plutôt que de répéter le pattern dans chaque composant. Faire passer le check sur toute la grille trades/orders/positions au passage — la même incohérence vit probablement ailleurs.

**Repéré le** : 2026-05-19 (debug BingX 100001).
**Priorité** : moyenne — pas critique mais désorientant pour l'utilisateur, et obligatoire pour la prochaine livraison user-facing qui touche du temps.

---

## Connecteurs broker — validation sandbox avant activation prod

**Contexte** : la feature TradingView webhooks (doc 66) a livré l'implémentation de `placeOrder/cancelOrder/closePosition` sur les 4 connecteurs (cTrader, MetaApi, Ouinex, BingX) en suivant les specs publiques. **Aucun n'a été exercé contre une sandbox broker réelle** — les tests unitaires couvrent le shape du code émis, pas l'acceptation broker.

**À faire avant activation prod** : pour chaque broker, créer un compte sandbox, configurer les credentials côté journal, déclencher un webhook test, vérifier en console broker que l'ordre est bien placé avec les bons paramètres (size, side, SL/TP), puis valider cancel/close. Points d'attention :

- **MetaApi** : `actionType` mapping correct (LIMIT/STOP nécessite `openPrice`), précision volume selon le symbole (FX = 0.01 lot mini, indices = 1 contrat mini).
- **BingX** : hedge mode obligatoire pour que `positionSide=LONG/SHORT` soit accepté. Vérifier qu'un compte one-way mode renvoie une erreur claire.
- **cTrader** : volume × 100 = pas une vérité universelle (selon le `lotSize` du symbole — `ProtoOASymbolByIdReq.lotSize` donne la conversion exacte). Si un broker cTrader utilise un lotSize non-standard, ajuster.
- **Ouinex** : les noms de mutations (`place_margin_order`, `cancel_margin_order`, `close_margin_position`) sont **best-effort** depuis le read-side schema. Tester contre la vraie API et ajuster les noms/champs si le serveur renvoie « Cannot query field ».

**Repéré le** : 2026-05-18 (feature `feat/broker-place-order`).
**Priorité** : haute — bloque l'activation du flag `TRADINGVIEW_WEBHOOKS_ENABLED` en prod.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
