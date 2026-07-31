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

## Saisie / formats

### Nombres au format local utilisateur (séparateurs décimal / milliers)

**Contexte** : retour Robin du 30/05/2026 (`docs/retours-beta-tests.md#e-10`). Les nombres sont saisis et affichés en **format international** (`23,924.4` : virgule = millier, point = décimale). Pour un utilisateur FR, c'est désorientant : il attend `23 924,4` (espace = millier, virgule = décimale). Le besoin est de **saisir ET afficher** dans le format du pays de l'utilisateur.

**À distinguer du bug B-05** : la remarque d'origine mélangeait deux sujets. La **corruption** d'un prix collé depuis l'Excel FTMO (`23924,6` → `239246`, virgule mangée comme séparateur de milliers) est un **bug** (`#b-05`, repro à cadrer : chemin import fichier vs collage dans `InputNumber`). Le **confort de format** (cette évolution) est séparé : même si la valeur est correcte, l'affichage international gêne.

**À faire** :
- Centraliser le formatage via un composable `useFormatNumber()` (à l'image de `useFormatDateTime()` proposé pour les timezones plus haut), branché sur la locale utilisateur (`useAuthStore().profile` — vérifier s'il y a déjà une préférence `locale`/`timezone`, sinon dériver de la langue i18n active).
- Côté **affichage** : passer les montants/prix/points par `Intl.NumberFormat(locale)` plutôt que des `toFixed`/concaténations brutes. Auditer grilles trades/positions/orders, tuiles stats, détails de trade.
- Côté **saisie** : configurer les `InputNumber` PrimeVue avec `locale` (et `minFractionDigits`/`maxFractionDigits` adéquats) pour que la virgule soit acceptée comme décimale en FR. ⚠️ croiser avec le quirk connu `InputNumber` + `:min` (cf. memory `feedback_primevue_inputnumber_negative`) — tester en navigateur, pas seulement en stub Vitest.
- Vérifier la cohérence aller-retour : ce qui est saisi au format FR doit être persisté en numérique « propre » côté API (le back attend des décimaux point, pas de séparateur de milliers).

**Repéré le** : 2026-05-30 (retour Robin, remarque 2).
**Priorité** : moyenne — pas bloquant (la valeur reste correcte si on évite le chemin qui corrompt), mais c'est un irritant UX récurrent pour les utilisateurs non-anglophones.

---

## Support (tickets)

### Email auteur absent du payload détail admin

**Contexte** : `GET /admin/support/tickets` (liste) expose `user_email` via un JOIN, mais `GET /admin/support/tickets/{id}` (détail, `assembleDetail()`) renvoie la ligne brute du ticket — sans l'email du demandeur (seulement `user_id`). Le `support-cli show <id>` affiche donc `author=—`.

**À faire** : enrichir `assembleDetail()` (ou le repo) avec `user_email` pour cohérence liste/détail.

**Repéré le** : 2026-06-05 (mise en place support-cli, doc 74).
**Priorité** : basse (cosmétique ; la liste porte déjà l'email).

### Nettoyage physique des pièces jointes

**Contexte** : les PJ des tickets sont stockées sur disque dans `api/storage/uploads/tickets/`. Les lignes `support_ticket_attachments` sont supprimées en cascade (FK `ON DELETE CASCADE`) si un ticket ou un user est hard-deleted, mais **les fichiers sur disque ne sont pas supprimés** (orphelins). En flux normal ce n'est pas critique (la suppression de compte est un soft-delete, les tickets restent), mais à prévoir si on ajoute une purge RGPD / un hard-delete de tickets.

**À faire** : brancher un nettoyage disque (`FileUploadService::delete()`) sur la suppression d'un ticket / la purge d'un compte, ou un job de GC qui supprime les fichiers sans ligne associée.

**Repéré le** : 2026-05-30 (audit privacy feat/support).
**Priorité** : basse (pas de hard-delete de ticket aujourd'hui).

### Rate limiting création de tickets

**Contexte** : `POST /support/tickets` n'a pas de `RateLimitMiddleware` (seuls les endpoints auth en ont). Un user authentifié pourrait spammer la création de tickets / l'envoi de mails admin.

**À faire** : ajouter un `RateLimitMiddleware` dédié sur la création de ticket et la réponse (ex. 10/h) ; éventuellement throttler les notifications admin.

**Repéré le** : 2026-05-30.
**Priorité** : basse à moyenne.

### Confort PJ : preview inline + PDF

**Contexte** : v1 limitée aux images (JPEG/PNG/WebP), affichées via un lien « ouvrir » (fetch blob authentifié → nouvel onglet), pas de vignette inline dans le fil. PDF non supporté.

**À faire** : vignettes inline (charger les blobs en `objectURL` et les afficher en `<img>` dans le thread), support `application/pdf` (whitelist `FileUploadService` + icône), lightbox.

**Repéré le** : 2026-05-30.
**Priorité** : basse.

### Reclassement du type d'un ticket (SUPPORT / BUG / FEATURE)

**Contexte** : le `type` est choisi par le demandeur à la création et n'est plus modifiable ensuite. Côté admin il existe `PATCH /admin/support/tickets/{id}/status` et `/priority`, mais **aucun endpoint pour le type**. Or les utilisateurs se trompent régulièrement de catégorie (ticket #32 déposé en FEATURE alors que c'est un bug confirmé), ce qui fausse le tri et les stats support.

**À faire** : `PATCH /admin/support/tickets/{id}/type` (admin only, valeurs de l'enum `TicketType`, sans notification e-mail — c'est un reclassement interne), + commande `set-type` dans `support-cli`.

**Repéré le** : 2026-07-29 (ticket #32).
**Priorité** : basse à moyenne (confort admin ; contournable en base).

### Réponse + changement de statut en un seul e-mail

**Contexte** : côté admin, répondre (`POST /admin/support/tickets/{id}/messages` → `replyAsAdmin()`) et changer le statut (`PATCH /admin/support/tickets/{id}/status` → `changeStatus()`) sont deux appels distincts, chacun notifiant l'auteur de son côté (`sendTicketReplyEmail` / `sendTicketStatusChangedEmail`). Clore un ticket en répondant envoie donc **deux e-mails** au demandeur à quelques secondes d'intervalle — constaté sur le ticket #32 (réponse puis passage en RESOLVED).

**À faire** : accepter un `status` optionnel dans le corps de la réponse admin, l'appliquer dans le même appel et n'envoyer qu'un seul e-mail combiné (« nouvelle réponse — ticket passé à X »). Ajouter l'option correspondante à `support-cli` (`reply <id> --body="…" --status=RESOLVED`).

**Repéré le** : 2026-07-29 (ticket #32).
**Priorité** : basse (confort ; le double e-mail reste acceptable).

### Refacto upload avatar sur FileUploadService

**Contexte** : `AuthService::uploadProfilePicture()` duplique la logique de validation/stockage désormais factorisée dans `FileUploadService`. Non refactorée pour rester hors-scope.

**À faire** : faire passer l'upload avatar par `FileUploadService` (sous-dossier public `avatars`), supprimer le code dupliqué.

**Repéré le** : 2026-05-30.
**Priorité** : basse (dette technique mineure).

---

## Auth / sessions

### Bandeau de vérification email — sync cross-onglet incomplète

**Contexte** : suite au fix du bandeau de vérification email (commit `a690cb5`, livré prod `aecc26e` le 2026-05-30, cf. `EmailVerificationBanner.vue` / `VerifyEmailView.vue` / `stores/auth.js`). Le fix combine (1) refetch du profil dans l'onglet qui vérifie, et (2) un `BroadcastChannel('auth')` pour que les **autres onglets déjà ouverts** rafraîchissent leur profil et masquent le bandeau sans reload.

Le point (2) **ne fonctionne pas de façon fiable** : testé en prod le 2026-05-30 avec deux onglets du **même navigateur** et le nouveau code, l'ancien onglet (dashboard, bandeau affiché) **ne s'est pas régularisé** — il a fallu recharger. Le point (1) marche.

**Diag (lecture seule, non concluant)** :
- Le token d'accès est **en mémoire par onglet** (`services/api.js:3`, pas en localStorage). La garde `api.getAccessToken()` du listener (`startCrossTabSync`) devrait pourtant être vraie dans l'onglet A (il est loggé).
- Par élimination : bandeau qui **reste** ⇒ `fetchProfile()` n'a pas tourné dans l'onglet A (sinon `/auth/me` renverrait `email_verified: true` → bandeau masqué). Donc soit le message `BroadcastChannel` n'est pas reçu, soit `startCrossTabSync` n'a pas posé le `onmessage` dans l'onglet A.
- Aucune cause évidente trouvée en lecture seule pour un scénario même-navigateur/même-origine. **Repro locale (2 onglets, console) nécessaire** pour trancher : vérifier que `onmessage` se déclenche côté A et que `postMessage` part côté B.

**À faire** :
- Reproduire en local (2 onglets) pour identifier la cause exacte du non-déclenchement.
- Piste de correctif robuste indépendante du mystère BroadcastChannel : **option 1 — refetch du profil au retour de focus/visibilité de l'onglet** (`visibilitychange`/`focus`), scopé à `email_verified === false` pour ne pas taper l'API inutilement une fois vérifié. Couvre l'onglet A quel que soit le contexte où la vérif a eu lieu (y compris cross-navigateur, que BroadcastChannel ne peut structurellement pas franchir). ~10 lignes, en TDD.

**Repéré le** : 2026-05-30.
**Priorité** : basse — pire cas = comportement d'avant le fix (reload nécessaire dans le vieil onglet), aucune régression. Le flux principal (onglet de vérif + reload) fonctionne.

---

## Carnet de notes — pistes différées (hors périmètre v1)

**Contexte** : livraison du module carnet de notes (`docs/73-carnet-notes.md`, migration `030_notebook.sql`). v1 = CRUD notes + catégories perso + multi-images + épingle dashboard. Pistes laissées de côté volontairement :

- **Recherche plein-texte** sur le contenu des notes (titre + corps), utile dès qu'un utilisateur accumule beaucoup de notes.
- **Réorganisation / drag&drop du dashboard** : aujourd'hui le widget « Notes épinglées » est en bas, position fixe.
- **Partage** d'une note (lien public en lecture seule, comme les positions partagées).
- **Rappels / échéances** sur une note (date d'échéance + notification).
- **Suppression physique des fichiers image** à la suppression d'une note : aujourd'hui la note est soft-deleted, donc les fichiers de `api/storage/uploads/notes/` restent (accessibles au seul propriétaire via endpoint authentifié). Prévoir un nettoyage (cron ou hard-delete cascade) si le volume devient un sujet.

**Repéré le** : 2026-06-05.
**Priorité** : basse — la v1 couvre le besoin exprimé. À reprioriser selon les retours d'usage.

---

## BingX rate-limit — pistes différées (suite du fix pacing)

**Contexte** : fix pacing proactif + report sur ban de fréquence `100410` (`docs/77-bingx-rate-limit-pacing.md`). Le pacing (300 ms) + report typé `BrokerRateLimitException` doivent suffire ; pistes laissées de côté tant que la validation test env ne montre pas le contraire :

- **Pacing configurable** : `DEFAULT_REQUEST_PACING_MS = 300` est une constante en dur. L'exposer via `platform_settings` permettrait d'ajuster la cadence sans redéploiement si BingX trippe encore.
- **Piste 3 — réduction de volume** : restreindre le walk `/allOrders` aux seules fenêtres où chaque symbol a eu de l'activité (timestamps des records `/user/income`) au lieu de balayer tous les chunks de 7 j jusqu'à l'origine. Réduit fortement le nombre de requêtes sur un compte multi-symbols. À implémenter seulement si pacing + report sont insuffisants.
- **Statut UI dédié au report** : un report rate-limit laisse `last_sync_status = FAILED` (avec message « deferring to next sync »). Un statut distinct (ex. `SyncStatus::PARTIAL` ou un nouveau `RATE_LIMITED`) distinguerait côté UI « throttlé, va reprendre tout seul » de « connexion cassée ». Implique un ajustement enum + colonne (additif).

**Repéré le** : 2026-06-15.
**Priorité** : moyenne (pacing configurable + piste 3 si la validation test env remonte encore des trips) / basse (statut UI, cosmétique).

---

## Plans de trading — pistes différées (hors périmètre v1)

**Contexte** : livraison des plans de trading (`docs/83-trading-plans.md`, migration `033_trading_plans.sql`, branche `feat/trading-plans`). Pistes laissées de côté volontairement :

- **Fenêtres chevauchant minuit** : v1 impose `start < end` sur le même jour (documenté doc 83) ; une session 22:00→02:00 demande deux fenêtres. Supporter le wrap (`start > end` = chevauche minuit) si le besoin remonte.
- **Liste de timezones complète** : `PlansView.vue` propose une liste IANA curée (`BASE_TZ`, 9 zones + celle du plan). `Intl.supportedValuesOf('timeZone')` donnerait la liste exhaustive avec filtre.

**Repéré le** : 2026-07-24.
**Priorité** : basse — la v1 couvre le besoin exprimé.

---

## Plans de trading — analytics d'adhérence (au-delà du badge/filtre)

**Contexte** : livraison de l'adhérence sur trade manuel (`docs/83`, migration `034_trade_plan_adherence.sql`). La v1 pose `positions.plan_id` + verdict figé `plan_adherence`, un badge dans la liste des trades et un filtre `Dans le plan / Hors plan / Sans plan`. Le besoin exprimé (« identifier les trades hors plan dans les stats ») est couvert au niveau *repérage*.

**À faire (si le besoin monte)** : une vraie brique analytics — comparer la **performance in-plan vs out-of-plan** (win rate, R:R moyen, P&L) dans la vue Performance, un compteur « X % de tes trades ont été pris hors plan », éventuellement une ventilation par plan. Réutiliser le filtre `plan_adherence` déjà exposé par l'API trades. Croiser avec le cadrage perf existant (memory `feedback_perf_charts` : R:R / win rate, pas de P&L brut, pas de graphe par compte).

**Question ouverte (à trancher) — comment matérialiser l'adhérence dans les stats** : quel support UI ? Options à peser :
- un **segment/toggle** sur la vue Performance existante (in-plan / out-of-plan / tout), qui rejoue les mêmes graphes filtrés ;
- une **tuile dédiée** « discipline » (% dans le plan, delta de win rate in vs out) façon KPI, sans nouvel écran ;
- une **ventilation par plan** (petit tableau win rate / R:R par plan).
Enjeu : ne pas alourdir le dashboard (memory `feedback_dashboard_simple`) — probablement côté Performance, pas Dashboard. Décider AVANT de coder pour ne pas empiler des graphes.

**Repéré le** : 2026-07-26.
**Priorité** : moyenne — forte valeur pédagogique (discipline de trading), mais la v1 badge+filtre suffit à repérer. À reprioriser selon l'usage.

---

## Plans de trading — raison d'adhérence localisable (i18n)

**Contexte** : `PlanEvaluator::evaluate()` renvoie une **raison en anglais** en prose (`entry 18200 outside BUY zones`, `direction BUY not allowed`, `outside trading windows`, `risk X% exceeds plan max Y%`). Elle est stockée telle quelle dans `positions.plan_adherence_reason` ET `tradingview_alert_events.error_message` (audit robots). En v1, le tooltip du badge FR ne l'affiche donc PAS (juste le statut « Dans le plan » / « Hors plan » localisé), pour ne pas montrer d'anglais côté user.

**À faire** : faire renvoyer par `PlanEvaluator` une **raison structurée** (code + params, ex. `{code:'ENTRY_OUTSIDE_ZONES', entry:18200, direction:'BUY'}`) au lieu de la prose. Stocker le code (ou code+params JSON), traduire côté front (clés `plan.reason.*`) pour afficher la raison en français dans le tooltip. Attention : la prose est aussi consommée par l'audit robots (log JSON) — garder une projection lisible là-bas, ou traduire aussi le back-office. Migration douce (les anciennes valeurs prose restent lisibles en fallback).

**Repéré le** : 2026-07-26.
**Priorité** : moyenne — le badge + statut localisé suffisent au repérage ; la raison détaillée FR est un plus (surtout si on pousse l'analytics d'adhérence).

---

## Dépendances — advisories connues (audits composer + npm)

**Contexte** : relevé pendant l'audit sécurité de `feat/trading-plans` (2026-07-24), rien d'introduit par la feature.

- **Backend (`php composer.phar audit`)** : `firebase/php-jwt` < 7 (low, CVE-2025-45769 « weak encryption ») + 7 advisories **medium** sur `guzzlehttp/guzzle` (cookies, Referer, proxy downgrade…). Guzzle ne sert qu'aux connecteurs broker sortants (surface limitée), mais à bumper.
- **Frontend (`npm audit`)** : 6 advisories (4 high) toutes dans la **toolchain de build** (vite, rollup, postcss, esbuild, picomatch, yaml) — dev-server/build uniquement, rien dans le bundle livré. `npm audit fix` les couvre.

**À faire** : branche chore dédiée — bump `firebase/php-jwt` v7 (vérifier l'API `encode/decode`), `guzzlehttp/guzzle` dernière 7.x, `npm audit fix` ; re-passer les deux suites complètes.

**Repéré le** : 2026-07-24.
**Priorité** : moyenne (guzzle/php-jwt) / basse (toolchain front, non exposée en prod).

---

## Tests frontend — pollution inter-suites + flaky en run complet

**Contexte** : relevé le 2026-07-24 en re-passant la suite Vitest complète (`feat/trading-plans` — non lié à la feature, les fichiers en cause ne sont pas touchés par la branche).

- **10 « Unhandled Rejection » `api.get is not a function`** dans `src/services/customFields.js:5`, uniquement en run complet (chaque spec passée isolément est propre) : une suite mocke partiellement `@/services/api` et un `customFieldsService.list()` asynchrone d'un composant monté ailleurs résout après coup sur le mock incomplet. Les 422 tests passent mais Vitest sort en exit 1.
- **Flaky** : `share-dialog.spec.js > does not render content when not visible` timeout 5 s par intermittence (passe en isolation et sur d'autres runs complets).

**À faire** : identifier la/les suites au mock `api` partiel (chercher `vi.mock('@/services/api'` sans `get`), compléter le mock ou mocker `customFieldsService` directement ; stabiliser le test ShareDialog (attente explicite plutôt que timeout implicite).

**Repéré le** : 2026-07-24.
**Priorité** : moyenne — masque de vraies erreurs (exit 1 permanent du run complet) et fait échouer toute CI stricte.

**Mise à jour 2026-07-31** (relevé sur `fix/ctrader-account-picker-details`, fichiers en cause non touchés par la branche) — le diagnostic « pollution inter-suites » est **faux, ou au moins incomplet** :

- Les 10 erreurs se reproduisent en lançant **`src/__tests__/account-view.spec.js` seul** (exit 255). Ce n'est donc pas seulement un effet de run complet : ce spec est cassé en isolation.
- La pile pointe `src/stores/customFields.js:20` (le **store**, pas le service comme noté au 2026-07-24) : `fetchDefinitions()` → `await customFieldsService.list()` échoue, et le `catch` **relance** (`throw err`, l.25) sans que le `onMounted` de `CustomFieldsTab.vue:23` n'attrape quoi que ce soit → rejet non géré.
- Volumétrie au 2026-07-31 : 52 fichiers, 466 tests passés, 10 erreurs, exit 1.

→ Chercher le mock manquant dans `account-view.spec.js` en premier (piste la plus courte), avant de traquer une pollution croisée. Question de fond derrière : `fetchDefinitions` doit-il relancer alors qu'il stocke déjà `error.value` ? Un appelant `onMounted` non-`await`é ne peut pas l'attraper.

---

## cTrader — suites de la lecture live (branche `feat/ctrader-live-read`)

**Contexte** : relevé pendant le câblage de la lecture cTrader (2026-07-26). Le connecteur lit maintenant positions/ordres ouverts, ordres clos et balance, et le format JSON wire est corrigé — ces points restent à traiter derrière.

- **Confirmer la sérialisation JSON des enums au premier run live.** Les normalizers cTrader tolèrent `tradeSide`/`orderStatus` en nom (`'BUY'`) **et** en code numérique (`1`), faute de certitude sur le format réel. Au premier run réel (log de la frame brute, cf. plan), figer le format et, si c'est du numérique, **appliquer `normalizeCtraderTradeSide()` aussi à `normalizeCtraderDeal()`** (aujourd'hui laissé tel quel, path clos hors scope, testé avec des strings).
- **Session WebSocket unique par sync.** `BrokerSyncService::sync()` appelle `fetchDeals` + `fetchOpenPositions` + `fetchOpenOrders` + `fetchClosedOrders` + `fetchBalance` séparément ; chacune ouvre sa propre session cTrader (app-auth + account-auth). Soit ~4 handshakes/refresh par sync. Optimisable en une session partagée (un `ProtoOAReconcileReq` couvre déjà positions+ordres). Chatty mais correct en l'état.
- **Échelle de volume incohérente (pré-existant).** `placeOrder` convertit `size × 100`, alors que `normalizeCtraderDeal`/`OpenPosition` font `volume / 100000`. Les deux ne peuvent pas être justes pour le même symbole — à réconcilier (dépend probablement du contract size par symbole).

**Repéré le** : 2026-07-26. **Priorité** : le point enum est **bloquant à valider** avant d'activer cTrader en test ; les deux autres sont basse priorité.

---

## BrokerOpenSyncService — `Undefined array key "id"` (pré-existant)

**Contexte** : warning PHPUnit relevé le 2026-07-26 en passant la suite Broker (2 tests BingX open-sync le déclenchent). Non lié à cTrader.

- `BrokerOpenSyncService.php:141` (`insertNewOpen` → `insertPartialExits((int) $trade['id'], …)`) lit `$trade['id']` alors que `tradeRepo->create()` peut renvoyer la clé sous un autre nom dans le contexte testé. À vérifier : la clé exacte retournée par `TradeRepository::create()` et aligner (probablement `$trade['id']` vs absence).

**Repéré le** : 2026-07-26. **Priorité** : basse (warning, pas d'échec de test).

---

## Broker — suites de la reconfiguration de connexion

**Contexte** : relevé en livrant `docs/85-broker-connection-reconfigure.md` (2026-07-29).

- **`BrokerSyncService` doit adopter `ConnectorRegistry`.** La résolution provider → connecteur existe maintenant en deux exemplaires : le `match()` privé de `BrokerSyncService::getConnector()` et le nouveau `ConnectorRegistry`. Le registre n'a volontairement pas été injecté dans `BrokerSyncService` pour ne pas changer sa signature de constructeur (4 connecteurs) ni son test. À unifier.
- **Divulgation d'existence sur les connexions d'autrui.** `BrokerConnectionService::requireOwnedConnection()` lève `ForbiddenException` (403) quand la connexion appartient à un autre utilisateur, et `ValidationException` (422 `connection_not_found`) quand elle n'existe pas — un attaquant peut donc énumérer les ids existants. C'est le comportement déjà en place dans `BrokerSyncService::sync()`, conservé par cohérence. À trancher globalement : soit tout en `not_found`, soit assumer la distinction.
- **`GET /broker/connections/{id}/logs` renvoie encore les erreurs brutes.** Les messages de connecteur sont désormais expurgés (signatures HMAC, tokens, clés) avant de sortir vers le client sur `connection_test.error` et sur `last_sync_error` — mais `sync_logs.error_message`, servi par la route `/logs` et affiché dans `SyncHistoryDialog`, passe toujours en clair. Même classe de fuite, même sanitizer à appliquer (`BrokerConnectionService::sanitizeTestError`). Concerne surtout BingX, qui signe en query string : un `GuzzleException` embarque l'URI complète.
- **Changement de provider sur un compte existant.** Hors périmètre volontairement : la reconfiguration ne touche pas au provider. Basculer un compte de cTrader vers BingX impose toujours supprimer/recréer, car les données importées sont préfixées par provider (`ctrader_`, `bingx_`…) et le curseur n'a plus de sens. À traiter si le besoin se présente.

**Repéré le** : 2026-07-29. **Priorité** : basse pour les trois.

---

## Admin — 6 `message_key` sans traduction

**Contexte** : relevé par `/check-i18n` en livrant la suite de `docs/86-ctrader-account-discovery.md` (2026-07-31). Hors périmètre de la branche cTrader, pas corrigé pour ne pas mélanger deux sujets dans un même diff.

Six clés sont levées par le back mais absentes de `fr.json` **et** de `en.json` — l'admin voit donc la clé brute (`admin.error.user_not_found`) au lieu d'un message :

| Clé | Levée dans |
|---|---|
| `admin.error.user_not_found` | `AdminUserService.php:94,106` |
| `admin.error.cannot_self_suspend` | `AdminUserService.php:102` |
| `admin.error.cannot_self_delete` | `AdminUserService.php:142` |
| `admin.settings.error.unknown_key` | `PlatformSettingsService.php:203` |
| `admin.settings.error.invalid_type` | `PlatformSettingsService.php:227,235` |
| `admin.settings.error.value_required` | `AdminSettingsController.php:25` |

Correction : ajouter les 6 clés dans les deux locales. Aucun changement de code.

**Repéré le** : 2026-07-31. **Priorité** : basse (chemins d'erreur admin uniquement), mais le correctif est trivial.

---

## Dépendances — 2 alertes ouvertes, non exploitables en l'état

Relevé par `/audit-security` le 2026-07-31. **Aucune n'est exploitable dans ce codebase** — noté ici pour éviter d'avoir à refaire l'analyse à chaque audit :

- **`phpoffice/phpspreadsheet`** — CVE-2026-40296 et CVE-2026-35453 (medium), XSS via le **writer HTML**. On n'instancie que `Writer\Xlsx` et `Writer\Ods` (`ImportController.php:146` et `:150`) ; le writer HTML n'est utilisé nulle part. Vecteur absent.
- **`vite`** — 4 advisories high (path traversal, lecture de fichier arbitraire, bypass `server.fs.deny`). C'est une **devDependency** : les failles visent le serveur de dev, alors que la prod sert le build statique de `dist/`. Non exposé.

À faire quand même à l'occasion : `npm audit fix` et bump de PhpSpreadsheet, pour ne pas garder un audit rouge en permanence (ça finit par masquer une vraie alerte).

**Repéré le** : 2026-07-31. **Priorité** : basse.

*Note* : le reste de l'audit est vert — `fr.json` et `en.json` ont exactement les mêmes 1181 clés. Les deux « clés manquantes » côté Vue (`robot.events.status_`, `webhook.tradingview.reject_reason.`) sont des **faux positifs** : ce sont des préfixes de concaténation (`t('robot.events.status_' + data.status.toLowerCase())`, `RobotsView.vue:488` et `:493`), pas des clés littérales.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
