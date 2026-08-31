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

> **Statut au 2026-08-13 — Phase 1 LIVRÉE, Phase 2 à faire.**
>
> **Phase 1 : livrée**, doc [63](63-broker-sync-ouinex.md). Vérifié dans le code :
> `OuinexConnector` enregistré dans `ConnectorRegistry`, `BrokerSyncService` et
> `TradingViewWebhookService` ; `BrokerProvider::OUINEX` dans l'enum ; quatre
> normalizers dédiés ; **31 tests unitaires** ; côté front `OuinexConnectDialog.vue`,
> le panneau de connexion, les clés fr/en et leurs tests. La livraison **dépasse**
> le scope décrit plus bas : elle couvre aussi le snapshot des positions ouvertes,
> celui des ordres en attente et les ordres récemment finalisés, là où la Phase 1
> ne prévoyait que `closed_margin_positions`.
>
> **Mais jamais validée contre un vrai compte Ouinex** — comme tout le domaine
> broker, le flag et les identifiants réels rendent la vérification impossible en
> local. Validée par les tests, pas par l'usage.
>
> **Phase 2 : rien.** Aucune trace de spot ni de pairing FIFO dans le connecteur.
> Le plan ci-dessous reste valable tel quel.
>
> La branche `feat/import-ouinex` mentionnée plus bas n'a plus lieu d'être.

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
**Priorité** : Phase 1 = **livrée** (voir le statut en tête d'entrée) ; il ne lui reste qu'une validation contre un vrai compte, qui viendra avec celle des autres connecteurs. Phase 2 = moyenne, à reprioriser sur retour d'usage — personne n'a encore demandé le spot.

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

### Raison du refus d'un plan : localiser une phrase produite en anglais

**Contexte** : `PlanEvaluator` ne renvoie pas une clé mais une **phrase anglaise**
toute faite (`entry 25648 outside BUY zones (24000-24400)`, `Mon not a trading
day`, `plan risk 5.300% (open 4.000% + signal 1.300%) exceeds plan max 5.000%`, et
cinq autres). Elle est affichée telle quelle dans le badge d'un trade, son
infobulle, l'alerte de saisie (doc 102) et le journal d'événements du webhook —
c'est le texte le plus lu de la fonctionnalité. Le lot 5 (doc 103) a repris tout
le reste du vocabulaire mais ne pouvait pas toucher celui-là.

**À faire** : renvoyer une clé i18n + des paramètres au lieu d'une phrase, et
trancher le sort des raisons **déjà écrites en base**
(`positions.plan_adherence_reason`, `VARCHAR(255)`) : le verdict étant figé
(doc 101), elles ne peuvent pas être régénérées. Deux options — les laisser telles
quelles et n'appliquer le nouveau format qu'aux nouvelles, ou stocker désormais la
clé et ses paramètres (JSON) en gardant un rendu de repli pour les anciennes.

**Repéré le** : 2026-08-17 (pendant le lot 5 du chantier plans).
**Priorité** : moyenne — pas bloquant, mais c'est la phrase que l'utilisateur lit
au moment précis où il cherche à comprendre un refus.

---

### Incohérence « symbole » vs « actif » dans grilles et modales

**Contexte** : aujourd'hui les labels i18n parlent de `positions.symbol` / `trades.symbol` (« Symbole ») dans les grilles trades/positions, les en-têtes de colonnes, et les modales (TradeForm, CloseTradeDialog, etc.), alors que la valeur affichée est en fait le **code de l'actif** (NASDAQ, BTCUSD, EURUSD, …) — c'est-à-dire le ticker / le nom commun de l'instrument, pas un « symbole » au sens graphique.

**À faire** : choisir une terminologie cohérente :
- soit renommer toutes les clés `*.symbol` → `*.asset` (et label « Actif » / « Asset »), pour refléter ce qu'on affiche réellement ;
- soit garder « Symbol/Symbole » mais clarifier dans la doc/UI ce qu'il représente.

Recommandation : **renommer en `asset`** — plus naturel pour un utilisateur non-dev. Impact : i18n (fr/en/...), composants Vue (props, columns, headers), stores éventuels. Le champ DB `positions.symbol` peut rester (interne), seul l'affichage UI bouge.

**Repéré le** : 2026-05-13 (pendant feature `feat/close-trade-actions-grid`).
**Priorité** : moyenne — pas bloquant mais c'est le genre d'incohérence qui rend la doc et le support client maladroits.

---

### Calendrier : le compteur d'une case dit des jours-trades, pas des trades

**Contexte** : chaque case du calendrier affiche un nombre de trades pour la journée. Un trade sorti en plusieurs fois, à des jours différents, encaisse sur chacun de ces jours — il apparaît donc légitimement dans plusieurs cases. Le décompte est dédoublonné **à l'intérieur** d'une journée, pas entre journées.

Résultat : additionner les cases du mois donne un total **supérieur** au nombre de trades du tableau de bord, sans que rien à l'écran ne l'explique. Un rapporteur a compté 53 là où le dashboard en annonçait 42 : 11 de ses trades étaient sortis sur deux jours. Les deux chiffres sont justes, ils ne répondent simplement pas à la même question.

C'est devenu visible avec le correctif du 2026-08-12 qui banque chaque sortie sur le jour où elle a réellement eu lieu — avant, un trade n'était rattaché qu'à un seul jour.

**À faire** : lever l'ambiguïté côté UI. Pistes, par coût croissant :
- une info-bulle sur le compteur de la case (« trades ayant encaissé ce jour-là ») ;
- un libellé explicite plutôt qu'un nombre nu ;
- un total mensuel affiché en pied de calendrier, dédoublonné celui-là, qui raccorde au tableau de bord.

**Repéré le** : 2026-08-14 (analyse du ticket support #39).
**Priorité** : moyenne — aucun chiffre n'est faux, mais c'est un générateur de tickets « il me manque des trades ».

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

**Complément (2026-08-03, `/check-i18n` de la doc 88)** : même famille pour le module support — `support.error.invalid_type`, `invalid_status` et `invalid_priority` sont levées par `SupportTicketService` mais absentes des locales admin. Différence : ces trois-là sont **inatteignables depuis l'UI** (les `Select` d'`AdminTicketDialog` et le CLI contraignent les valeurs à l'enum), donc à ajouter avec les 6 autres ou pas du tout — pas séparément, sous peine d'asymétrie.

---

## Dépendances — 2 alertes ouvertes, non exploitables en l'état

Relevé par `/audit-security` le 2026-07-31. **Aucune n'est exploitable dans ce codebase** — noté ici pour éviter d'avoir à refaire l'analyse à chaque audit :

- **`phpoffice/phpspreadsheet`** — CVE-2026-40296 et CVE-2026-35453 (medium), XSS via le **writer HTML**. On n'instancie que `Writer\Xlsx` et `Writer\Ods` (`ImportController.php:146` et `:150`) ; le writer HTML n'est utilisé nulle part. Vecteur absent.
- **`vite`** — 4 advisories high (path traversal, lecture de fichier arbitraire, bypass `server.fs.deny`). C'est une **devDependency** : les failles visent le serveur de dev, alors que la prod sert le build statique de `dist/`. Non exposé.

À faire quand même à l'occasion : `npm audit fix` et bump de PhpSpreadsheet, pour ne pas garder un audit rouge en permanence (ça finit par masquer une vraie alerte).

**Repéré le** : 2026-07-31. **Priorité** : basse.

*Note* : le reste de l'audit est vert — `fr.json` et `en.json` ont exactement les mêmes 1181 clés. Les deux « clés manquantes » côté Vue (`robot.events.status_`, `webhook.tradingview.reject_reason.`) sont des **faux positifs** : ce sont des préfixes de concaténation (`t('robot.events.status_' + data.status.toLowerCase())`, `RobotsView.vue:488` et `:493`), pas des clés littérales.

---

## cTrader — le P&L importé est brut, les frais sont ignorés

**Contexte** : relevé le 2026-08-03 en corrigeant la mise à l'échelle `moneyDigits` (cf. doc 22). Constat seulement, aucun code touché — décision reportée.

`DealNormalizer::normalizeCtraderDeal()` construit le P&L à partir du seul `closePositionDetail.grossProfit`. Or `ProtoOAClosePositionDetail` fournit aussi **`swap`, `commission` et `pnlConversionFee`**, que cTrader documente explicitement comme soumis au même `moneyDigits` (« Affects grossProfit, swap, commission, balance, pnlConversionFee »). `ProtoOADeal` porte en plus sa propre `commission`.

Chaque trade importé omet donc ses frais. L'écart est invisible trade par trade mais cumulatif : c'est un candidat sérieux à l'écart constaté entre le solde courtier et le capital recalculé depuis les trades du journal.

**À trancher avant de corriger** — ce n'est pas qu'un changement de formule :

1. **Brut ou net ?** Les autres connecteurs doivent être vérifiés pour rester cohérents (BingX reconstruit depuis les fills, Ouinex renvoie un `pnl` dont il faut vérifier s'il est net).
2. **Que faire des trades déjà importés ?** Ils gardent leur valeur brute. Soit on les laisse (historique incohérent avec les imports futurs), soit on réimporte, soit on migre — et un recalcul rétroactif du P&L touche les stats, le R:R et le capital courant.
3. Le champ `swap` est négatif ou positif selon le sens : additionner, ne pas soustraire (`grossProfit + swap + commission + pnlConversionFee`, chacun déjà signé).

**Repéré le** : 2026-08-03. **Priorité** : moyenne — fausse le rapprochement avec le solde courtier, mais sans casser de fonctionnement.

---

## ✅ TRAITÉ — Rate limiting — l'API ne voit jamais l'IP réelle du visiteur

**Traité le 2026-08-13** — voir [96-adresse-ip-reelle-du-visiteur.md](96-adresse-ip-reelle-du-visiteur.md). `App\Core\ClientIpResolver` dérive l'adresse de `CF-Connecting-IP` puis `X-Forwarded-For`, **uniquement** depuis un hop de confiance, avec parcours de droite à gauche pour ne pas gober une entrée forgée en tête de chaîne. Une seule plage déclarée — `100.64.0.0/10`, le réseau interne Railway — livrée **par défaut**, donc aucune variable à poser ; les plages Cloudflare sont volontairement absentes puisque PHP ne les voit jamais dans `REMOTE_ADDR`. Vérifié au passage qu'aucun `*.up.railway.app` n'expose l'API en contournant Cloudflare, sans quoi l'en-tête aurait été falsifiable. Entrée conservée pour l'historique.

**Signalé à traiter en priorité le 2026-08-03.**

**Contexte** : découvert en diagnostiquant le ticket support #34 (cf.
`docs/87-upload-token-refresh.md`). `RateLimitMiddleware.php:27` indexe ses
compteurs sur `Request::getClientIp()`, qui renvoie `$_SERVER['REMOTE_ADDR']`
(`Request.php:85`). Aucune gestion de `X-Forwarded-For` ni de `CF-Connecting-IP`
nulle part dans le repo.

Or la prod est derrière Cloudflare puis l'edge Railway. **Vérifié en production**
sur 1000 lignes de logs : PHP ne voit que `100.64.0.2` à `100.64.0.22`, soit 21
adresses internes Railway attribuées au hasard, jamais l'IP du visiteur.

Les quotas de `config/security.php` sont donc mutualisés entre tous les
utilisateurs au lieu d'être individuels :

| Endpoint | Quota annoncé | Quota réel |
|---|---|---|
| `/auth/login` | 10 / 15 min / IP | ~210 / 15 min, partagés |
| `/auth/refresh` | 10 / 15 min / IP | ~210 / 15 min, partagés |
| `/auth/register` | 5 / 15 min / IP | ~105 / 15 min, partagés |
| `/auth/forgot-password` | 3 / 15 min / IP | ~63 / 15 min, partagés |

**Conséquences** :
1. Un attaquant n'est pas limitable individuellement — en tombant au hasard sur
   les 21 adresses du pool, il dispose de ~21× le quota prévu.
2. Symétriquement, il peut saturer volontairement les seaux partagés : ~210
   appels à `/auth/refresh` en 15 min déconnecteraient tous les utilisateurs
   actifs. Déni de service à très bas coût.
3. `forgot_password` et `register` sont les plus fragiles : quelques dizaines de
   requêtes bloquent la fonction pour toute la plateforme.

**À décharge** : le vrai rempart anti-force-brute reste le verrouillage de compte
(5 échecs → 15 min, `AuthService.php:624-641`), correctement indexé par
utilisateur. Le limiteur par IP n'est qu'une seconde couche, aujourd'hui
dégradée. **Aucune exploitation constatée** : 0 réponse 429 sur 59 h de logs de
production.

**À faire** : dériver l'IP client de `CF-Connecting-IP` (Cloudflare est le front),
avec repli sur `X-Forwarded-For`, et **une liste blanche de proxys de confiance** —
sans elle, n'importe qui peut forger l'en-tête et contourner le limiteur, ce qui
serait pire que la situation actuelle. En TDD sur `Request::capture()`.

**Repéré le** : 2026-08-03. **Priorité** : haute (latent, non déclenché).

---

## Production — l'API tourne sur le serveur de développement de PHP

**Signalé à traiter en priorité le 2026-08-03.**

**Contexte** : `api/Dockerfile` part de `php:8.4-cli` et `api/docker/entrypoint.sh`
lance `php -S 0.0.0.0:${PORT}`. La documentation PHP qualifie ce serveur de
serveur de développement et le déconseille explicitement sur un réseau public.
`PHP_CLI_SERVER_WORKERS` n'est défini ni dans le repo ni dans les variables
Railway → **un seul worker**, traitement séquentiel.

`api/docker/nginx.conf` **n'est jamais copié** par le Dockerfile et nginx n'est
pas installé dans l'image : c'est du code mort, qui donne une fausse impression de
configuration en place. Il est par ailleurs correctement écrit (`127.0.0.1:9000`,
`${PORT}`) et directement réutilisable.

**Ce que ça coûte** :
1. **Aucune observabilité applicative** — les logs du conteneur ne contiennent que
   `Accepted`/`Closing`, sans chemin ni code retour. Le diagnostic du ticket #34 a
   dû passer par les logs de l'edge Railway, qui ne donnent ni la query string, ni
   le corps, ni l'IP vue par PHP.
2. **Une requête lente bloque toutes les autres** — `max_execution_time = 60` : une
   synchronisation broker ou un import volumineux peut monopoliser l'unique worker
   pendant une minute.
3. Un worker unique qui plante coupe le service jusqu'au redémarrage du conteneur.

**À faire, par effort croissant** :
- **A (immédiat, réversible)** : poser `PHP_CLI_SERVER_WORKERS=4` en variable
  d'environnement sur le service `api`. Zéro code, règle la sérialisation. Ne
  règle ni l'observabilité ni la robustesse.
- **B (le vrai correctif)** : basculer l'image sur `php:8.4-fpm`, installer nginx,
  lancer les deux processus depuis l'entrypoint. `nginx.conf` est déjà écrit. Rend
  les logs d'accès (chemin + code + IP source), des timeouts configurables et un
  pool de workers.

**Repéré le** : 2026-08-03. **Priorité** : haute.

---

## Auth — course entre le logout et un renouvellement de token en cours

**Contexte** : relevé par `/audit-privacy` pendant le correctif du ticket #34.
`clearTokens()` (`services/api.js`) remet le token à `null` mais n'annule pas un
`refreshPromise` en vol. Si l'utilisateur se déconnecte pendant qu'un
renouvellement est en cours (typiquement le rejeu d'un upload), la promesse se
résout **après** le logout et rappelle `setTokens()` : un token d'accès valide
réapparaît en mémoire alors que la session est censée être fermée.

Comme `isAuthenticated` se calcule sur `api.getAccessToken()` (`stores/auth.js:32`),
l'application peut reconsidérer l'utilisateur comme connecté à la navigation
suivante. Les stores Pinia sont vidés et `user` est à `null`, donc rien ne
s'affiche dans l'immédiat, mais un jeton porteur survit à une déconnexion
explicite.

**Préexistant** au correctif du ticket #34 : `refreshAccessToken()` appelait déjà
`setTokens()` de façon asynchrone. Le verrou anti-concurrence introduit par ce
correctif ne change pas la taille de la fenêtre.

**À faire** : invalider la promesse en vol dans `clearTokens()` et ignorer une
résolution tardive (compteur de session capturé à l'entrée du renouvellement).
~3 lignes + 1 test.

**Repéré le** : 2026-08-03. **Priorité** : moyenne (fenêtre étroite, exploitation
fortuite uniquement).

---

## Infra — connexion MySQL ouverte à chaque requête, y compris `/health`

**Contexte** : `config/routes.php:122` appelle `Database::getConnection()` au
chargement du fichier, donc pour **toutes** les routes — y compris `/health`, qui
ne lit aucune donnée. Visible dans les logs réseau Railway : chaque requête HTTP
déclenche une résolution DNS puis une poignée de main MySQL d'environ 7,8 Ko.

Corollaire relevé au passage : `api/docker/entrypoint.sh` rejoue `seed-demo.php` à
**chaque démarrage** du conteneur, y compris en production.

**À faire** : différer la connexion PDO jusqu'au premier usage réel (connexion
paresseuse), et conditionner le seed de démo à l'environnement.

**Repéré le** : 2026-08-03. **Priorité** : basse (gaspillage, pas de bug).

---

## Sécurité — scanner de vulnérabilités permanent sur l'API

**Contexte** : les logs HTTP de production montrent un balayage continu depuis
`20.215.189.213` (Microsoft Azure, bloc `20.192.0.0/10`, pas de reverse DNS) :
`/wp-content/plugins/hellopress/wp_filemanager.php`, `/.env`, et des dizaines de
webshells `.php`. Environ 8 requêtes/seconde par rafales.

**Rien n'est exposé** — que des 404, et `.env` est hors du `document root`. Mais
chaque requête consomme l'unique worker PHP et ouvre une connexion MySQL (cf.
entrée ci-dessus).

**À faire** : une règle Cloudflare (WAF ou rate limiting) sur les chemins
`*.php` et `/wp-*`, qui n'existent pas dans cette API.

**Repéré le** : 2026-08-03. **Priorité** : basse.

---

## API — un body malformé donne un 500 au lieu d'un 422

**Contexte** : relevé par `/audit-security` en livrant `docs/88-support-ticket-reclassify.md` (2026-08-03). Pré-existant, pas introduit par cette branche.

Les contrôleurs passent `$request->getBody('champ')` (type `mixed`) à des paramètres de service typés `?string`. Si le client envoie un tableau plutôt qu'une chaîne (`type[]=x`, `status[]=y`), PHP lève un `TypeError` **avant** toute validation métier → 500 générique au lieu du 422 attendu.

Constaté sur `AdminSupportTicketController::updateStatus/updatePriority/updateType`, mais le motif est général à tous les contrôleurs.

**Pas de fuite** : le handler global (`api/public/index.php:78`) renvoie `error.internal` sans trace hors mode debug, et ces routes sont admin-only. C'est un défaut de propreté (mauvais code HTTP), pas une faille.

**À faire** : un garde partagé — soit `Request::getBody()` qui aplatit/rejette les valeurs non scalaires, soit un `getBodyString()` dédié. Correctif transverse, à ne pas faire endpoint par endpoint.

**Repéré le** : 2026-08-03. **Priorité** : basse.

---

## `billing_grace_days` — la description admin ne correspond pas à l'usage réel

**Contexte** : relevé en livrant le ticket support #12 (2026-08-03), en vérifiant le sens de chaque réglage plateforme.

`admin/src/locales/fr.json` décrit `admin.settings.desc.billing_grace_days` comme le « Délai de grâce après échec de paiement Stripe (jours) ». Le seul usage du réglage dans le code est `AuthService::register()` (`api/src/Services/AuthService.php:94`) : il fixe `grace_period_end` **à la création du compte** (14 jours par défaut), c'est-à-dire la période d'essai du nouvel inscrit. Aucun usage côté `BillingService` / webhooks Stripe.

L'admin qui règle ce paramètre depuis le BO croit donc agir sur la tolérance après impayé alors qu'il change la durée d'essai des nouveaux comptes.

**À faire** : trancher lequel des deux est faux — soit corriger la description (fr + en) dans `admin/src/locales/`, soit implémenter réellement la grâce post-échec de paiement si c'était l'intention. Vérifier au passage l'affichage « Fin grâce » de la liste utilisateurs du BO, qui hérite de la même ambiguïté.

**Repéré le** : 2026-08-03. **Priorité** : basse (cosmétique tant que personne ne modifie le réglage), moyenne si le BO est ouvert à d'autres admins.

---

## Locales : tutoiement et vouvoiement se mélangent dans un même écran

**Contexte** : relevé en livrant le ticket support #12 (2026-08-03) sur la section « Compte et données » du profil.

Dans le même bloc UI : `account.account_data.delete_account_description` vouvoie (« Supprime définitivement **votre** accès au compte ») tandis que `account.delete_account.warning_line2` tutoie (« **tape ton** email ci-dessous »). Le mélange existe ailleurs (`billing.*` tutoie, `auth.error.*` vouvoie).

**À faire** : choisir une adresse unique (le produit penche vers le tutoiement) et passer les locales `fr.json` en revue d'un bloc. Chantier transverse à faire d'un seul coup, pas au fil des features, sinon l'incohérence se déplace.

**Repéré le** : 2026-08-03. **Priorité** : basse.

---

## `npm audit` frontend : 6 vulnérabilités sur les dépendances de build

**Contexte** : relevé par `/audit-security` en livrant le ticket support #12 (2026-08-03). Pré-existant.

`npm audit --omit=dev` dans `frontend/` remonte 6 vulnérabilités (1 low, 1 moderate, 4 high) sur la chaîne de build — notamment `vite` et `yaml` (`GHSA-48c2-rrv3-qjmp`, stack overflow sur YAML profondément imbriqué). Ce sont des outils de compilation, pas du code expédié au navigateur : la surface d'exploitation suppose déjà un accès à la machine de build.

**À faire** : passer `npm audit fix` sur une branche dédiée puis rejouer build + suite Vitest — la montée de `vite` est susceptible de casser la config. À ne pas glisser dans une branche de feature.

**Repéré le** : 2026-08-03. **Priorité** : basse.

---

## ✅ TRAITÉ — Une synchro cTrader ouvre quatre sessions WebSocket

**Traité le 2026-08-09** — voir [90-ctrader-budget-requetes.md](90-ctrader-budget-requetes.md). Une session par run partagée par les cinq appels, `ProtoOAReconcileReq` mémoïsé, tailles de lot mises en cache : 19 requêtes par cycle ramenées à 9. La priorité « basse » ci-dessous s'est révélée fausse — ce n'était pas un sujet de latence : FTMO a désactivé un compte réel le 2026-08-07 pour dépassement de son plafond de 2 000 requêtes/jour, que notre volume frôlait. Entrée conservée pour l'historique.

**Contexte** : repéré en corrigeant la fidélité des deals cTrader (2026-08-05). Pré-existant, aggravé de rien par le correctif.

`BrokerSyncService` appelle successivement `fetchDeals`, `fetchOpenPositions`, `fetchOpenOrders`, `fetchClosedOrders` et `fetchBalance`. Chacune ouvre sa propre connexion WebSocket et rejoue `ProtoOAApplicationAuthReq` + `ProtoOAAccountAuthReq` — soit cinq poignées de main et cinq authentifications pour une synchro. `ProtoOAReconcileReq` est en plus émis trois fois (deals, positions, ordres) et renvoie à chaque fois le même snapshot.

Le cache de noms de symboles introduit avec le correctif (`CtraderConnector::$symbolNameCache`) montre le chemin : une session unique portée sur toute la durée du run, purgée par `resetSyncCache()`.

**À faire** : factoriser une session par run plutôt que par méthode. Chantier de connecteur, à ne pas mêler à une correction de données.

**Fichiers** : `api/src/Services/Broker/CtraderConnector.php`, `api/src/Services/Broker/BrokerSyncService.php`.

**Repéré le** : 2026-08-05. **Priorité** : basse (correctness non affectée, seulement la latence de synchro).

---

## cTrader — la socket vit désormais un run entier, sans heartbeat sortant

**Contexte** : repéré en relisant le partage de session (2026-08-09, doc 90). Pas un défaut constaté — une hypothèse tirée du code, à vérifier.

`CtraderConnector::sendAndReceive()` **ignore** les heartbeats entrants (`payloadType 51`) mais le connecteur n'en **émet** jamais. Tant qu'une socket ne servait qu'un appel, la question ne se posait pas : elle vivait quelques centaines de millisecondes. Elle porte maintenant les cinq appels d'un run, pagination de `ProtoOADealListReq` comprise.

**À vérifier avant d'agir** : le délai d'inactivité réel côté cTrader, dans le `.proto` Spotware (pas sur help.ctrader.com, qui omet des champs). Si le serveur coupe les sockets inactives, le symptôme serait un run qui casse en son milieu — cycle perdu, pas de perte de données, et le cycle suivant rattrape.

**Fichiers** : `api/src/Services/Broker/CtraderConnector.php`.

**Repéré le** : 2026-08-09. **Priorité** : basse tant que rien n'est observé en test live.

---

## ⚠️ Les tests tournent sur MariaDB, la prod sur MySQL — divergence non couverte

**Contexte** : découvert le 2026-08-09 en corrigeant le P&L journalier, qui renvoyait un 500 en prod et en test depuis plusieurs jours avec 1721 tests verts.

`getDailyPnl` faisait `GROUP BY` sur une expression contenant une **sous-requête corrélée** (`effectiveDate()`). Sous `ONLY_FULL_GROUP_BY` — actif par défaut sur MySQL, donc en prod — le serveur n'apparie plus l'expression du `SELECT` avec celle du `GROUP BY` dès qu'une sous-requête est dedans, voit `t.closed_at` comme colonne non agrégée, et rejette la requête avec l'erreur 1055.

**Le point qui compte** : ce n'est pas un simple oubli de `sql_mode` en local. **Vérifié, pas supposé** — MariaDB 11.4.9 accepte cette requête *même avec* `ONLY_FULL_GROUP_BY` explicitement activé, là où MySQL 8.4.7 la refuse. Aucun réglage de `sql_mode` sur la base de dev n'aurait donc attrapé le bug. Toute la suite d'intégration est aveugle à cette classe de divergence.

**Palliatif en place** : un test statique (`testNoAggregateGroupsByAnExpressionCarryingASubquery`) lit le source de `StatsRepository` et refuse tout `GROUP BY` contenant `SELECT` ou l'expression `$eff`. Ça couvre ce fichier et ce motif précis, rien d'autre.

**À faire** : faire tourner la suite d'intégration contre **MySQL**, le moteur de prod — en CI a minima, idéalement en local. C'est le seul garde-fou général. Attention, l'activation révélera probablement d'autres requêtes non conformes à `ONLY_FULL_GROUP_BY` : prévoir le chantier, pas un patch.

**Aggravant, traité séparément** : le `catch (\Throwable)` de `api/public/index.php:78` ne journalise rien. L'erreur était donc invisible dans les logs Railway (prod comme test) ; il a fallu rejouer l'appel avec `APP_DEBUG=true` pour l'obtenir. Voir l'entrée dédiée.

**Fichiers** : `api/src/Repositories/StatsRepository.php`, `api/phpunit.xml`, `api/tests/Integration/`.

**Repéré le** : 2026-08-09. **Priorité** : haute — la conséquence constatée est un endpoint en 500 pendant plusieurs jours, sans qu'aucun test ni aucun log ne le signale.

---

## ✅ TRAITÉ — Les 500 de l'API ne laissent aucune trace

**Traité le 2026-08-12** — voir [92-journalisation-erreurs.md](92-journalisation-erreurs.md). `App\Core\ErrorLogger` écrit une ligne JSON sur stderr, au format de `BrokerLogger`, branchée sur `index.php` **et** sur le `catch (Throwable)` de `BrokerSyncSchedulerService` — ce second trou masquait un échec de synchro qui se répétait toutes les 20 minutes en env de test. La trace est reconstruite à la main pour ne jamais porter les arguments d'appel, et le chemin est assaini (le jeton du webhook TradingView voyage dans l'URL). **`APP_DEBUG` a été supprimé dans la foulée** : il n'existait que pour renvoyer la cause au client, ce dont on n'a plus besoin. Entrée conservée pour l'historique.

**Contexte** : découvert le 2026-08-09 pendant le diagnostic ci-dessus.

`api/public/index.php:78` attrape tout `Throwable`, renvoie `INTERNAL_ERROR` / `error.internal`, et **n'écrit rien** : ni `error_log`, ni `BrokerLogger`. En prod (`APP_DEBUG` off) l'exception est perdue définitivement. Sur 333 lignes de logs conteneur de l'env de test, zéro ligne d'erreur PHP alors que l'endpoint renvoyait bien un 500.

Seul le mode debug expose la cause, et il la renvoie **au client** — donc inutilisable en prod.

**À faire** : journaliser le `Throwable` (message, fichier, ligne, trace) sur stderr avant de renvoyer la réponse générique. La réponse client ne doit pas changer : elle reste sans détail, ce qui est la bonne propriété côté sécurité. C'est la journalisation serveur qui manque, pas la réponse.

**Fichiers** : `api/public/index.php`.

**Repéré le** : 2026-08-09. **Priorité** : haute — un défaut d'observabilité qui transforme chaque incident en enquête.

---

## `database/schema.sql` a dérivé des migrations

**Contexte** : repéré en vérifiant, avant commit, que `partial_exits.external_id` existait bien (correctif fidélité cTrader, 2026-08-05).

La colonne est ajoutée par la migration `023_partial_exits_external_id.sql` mais absente de `database/schema.sql`. Aucun script ne lit `schema.sql` — ni `migrate.php`, ni `entrypoint.sh` — c'est donc de la documentation, sans risque de déploiement. Mais elle décrit un schéma qui n'existe plus, et rien ne garantit que `023` soit la seule dérive : les migrations vont jusqu'à `034`.

**À faire** : régénérer `schema.sql` depuis une base à jour (`mysqldump --no-data`) et le comparer au fichier actuel pour recenser l'écart complet. Décider ensuite s'il reste une référence utile ou s'il vaut mieux le supprimer au profit des seules migrations.

**Fichiers** : `api/database/schema.sql`, `api/database/migrations/`.

**Repéré le** : 2026-08-05. **Priorité** : basse.

---

## Un trade sans stop loss affiche un SL au prix d'entrée

**Contexte** : repéré en corrigeant l'affichage du SL des trades synchronisés (2026-08-06). Pré-existant, indépendant de la synchro.

`PricePointsInput` calcule le prix compagnon à partir des points. Quand aucun stop n'est enregistré, les points valent 0 et le champ prix affiche donc `entrée - 0`, c'est-à-dire le **prix d'entrée** — au lieu de rester vide. Un trade sans stop paraît en avoir un, posé exactement à l'entrée.

**À faire** : distinguer « zéro point » de « pas de stop » dans le composant — sans doute en laissant le prix à `null` quand les points sont nuls ET qu'aucun prix n'est stocké. Vérifier l'impact sur le BE et les TP, qui partagent le composant.

**Fichiers** : `frontend/src/components/**/PricePointsInput.vue`, `frontend/src/components/trade/TradeForm.vue`.

**Repéré le** : 2026-08-06. **Priorité** : basse.

---

## Ordres sortants cTrader — à exercer quand les robots seront testés

**Contexte** : la conversion de volume des ordres sortants a été corrigée le 2026-08-13 (voir [94](94-ctrader-volume-des-ordres-sortants.md) et l'entrée traitée ci-dessous), mais **elle n'a jamais été exercée** : les robots sont éteints, donc `placeOrder()` et la clôture partielle n'ont jamais tourné contre un vrai compte. Validées par les tests, pas par l'usage.

**À vérifier le jour où l'on testera les robots** :

- le volume reçu par le broker correspond bien à la taille demandée — c'est tout le sujet du correctif, `lots × lotSize` ;
- **aucune ligne `lot_size_unresolved` n'apparaît** dans les logs. Si elle sort, c'est que la taille de lot n'a pas pu être résolue et que le repli `× 100` s'est appliqué : correct sur un indice CFD, cent mille fois trop petit sur une paire FX ;
- la clôture partielle envoie le bon volume, elle passe par le snapshot `Reconcile` pour trouver le symbole de la position ;
- au passage, le coût : un `placeOrder` vaut 5 requêtes et une clôture partielle 5 aussi, à confronter au budget quotidien (évolution #22) si un robot devient bavard.

**Repéré le** : 2026-08-14. **Priorité** : bloquant avant d'armer un robot sur un compte réel, nulle tant qu'ils sont inactifs.

---

## ✅ TRAITÉ — cTrader — `placeOrder` convertit encore le volume en dur

**Traité le 2026-08-13** — voir [94-ctrader-volume-des-ordres-sortants.md](94-ctrader-volume-des-ordres-sortants.md). `lotsToVolume()` calcule `lots × lotSize`, l'inverse exact de la lecture, avec un `ProtoOASymbolByIdReq` pour résoudre la taille de lot (`ProtoOALightSymbol` ne la porte pas) et un repli `× 100` journalisé. **La clôture partielle de `closePosition()` avait le même défaut** et est corrigée aussi : elle résout le symbole via le snapshot, la position étant tout ce que la requête nomme. Entrée conservée pour l'historique.

**Contexte** : repéré en corrigeant la conversion de volume côté **lecture** (2026-08-06). Le read path calcule désormais `lots = volume / lotSize`, la valeur exacte du symbole. `placeOrder` (`CtraderConnector:808`) fait toujours `size * 100` — les deux sens du connecteur ne parlent donc plus la même langue.

Le `×100` n'est juste que pour un symbole dont le lot vaut une unité (indices CFD typiquement). Sur une paire FX où `lotSize` vaut 10 000 000 cents, envoyer 0.1 lot produit un volume de `10` au lieu de `1 000 000` — soit un ordre 100 000 fois trop petit, probablement rejeté sous le volume minimum. Le risque est donc plutôt le refus que l'exécution erronée, mais c'est à vérifier.

Le point était déjà pressenti dans l'entrée « Connecteurs broker — validation sandbox avant activation prod » ; il est maintenant chiffrable et corrigeable en réutilisant `resolveSymbols()`, qui renvoie déjà le `lotSize`.

**À faire** : résoudre le `lotSize` du symbole dans `placeOrder` (l'appel `ProtoOASymbolsListReq` y est déjà fait pour trouver le `symbolId`) et convertir par `size * lotSize`. Concerne les ordres sortants — robots TradingView —, pas la synchronisation.

**Fichiers** : `api/src/Services/Broker/CtraderConnector.php`.

**Repéré le** : 2026-08-06. **Priorité** : haute avant toute activation des robots sur cTrader, nulle tant qu'ils sont inactifs.

---

## Positions — aucune contrainte d'unicité sur `external_id`

**Contexte** : repéré le 2026-08-06 en cherchant ce qui rattrape une course entre
deux synchros. Le volet applicatif est traité (réservation par connexion,
[89-broker-sync-parallelisation.md](89-broker-sync-parallelisation.md)) ; le
volet base reste ouvert.

`ImportService::importNormalizedPositions` déduplique en lisant `getExistingExternalIds()` en début de transaction. **`positions.external_id` n'a ni index ni contrainte d'unicité** : si deux imports concurrents lisent le même état — la réservation couvre les synchros broker, pas un import CSV lancé en parallèle — rien au niveau base ne rattrape la course. L'index manquant coûte aussi en lecture sur `findOpenByExternalIdPrefixInAccount`.

**À faire** : un index unique sur **`(account_id, external_id)`** — migration additive mais **à vérifier avant** : les données existantes peuvent déjà contenir des doublons (cf. l'historique du hash d'identité), il faudra les purger ou l'index échouera.

> **Corrigé le 2026-08-10** : cette entrée proposait `(user_id, external_id)`. Ce couple est désormais **faux** — la déduplication est scopée au compte depuis le correctif du 2026-08-10 (`docs/19-import-history.md`, « La portée »), et la même position broker peut légitimement exister sur deux comptes du journal. Un unique sur `(user_id, external_id)` réintroduirait au niveau base exactement le bug qui a fait perdre 14 trades en env de test.

**Fichiers** : `api/database/schema.sql`, nouvelle migration.

**Repéré le** : 2026-08-06. **Priorité** : moyenne — la fenêtre restante est étroite, mais l'index a aussi un intérêt de performance.

---

## Suivi d'une synchro broker — sondage HTTP plutôt que push

**Contexte** : issu du lot C de [89-broker-sync-parallelisation.md](89-broker-sync-parallelisation.md) (2026-08-07), qui a rendu le bouton non bloquant. L'entrée d'origine — « synchro manuelle bloquante » — est traitée ; ce qui suit est ce que la solution laisse ouvert.

Le panneau broker suit l'avancement en interrogeant `GET /broker/connections` toutes les 4 s pendant 5 minutes max. Suffisant pour un compte, mais un utilisateur qui ouvre plusieurs comptes multiplie les requêtes, et la fin du run est détectée avec jusqu'à 4 s de retard.

**À faire, le jour où ça pèse** : un canal poussé (SSE, ou websocket si un autre besoin le justifie) pour l'état de synchro, au lieu du sondage. À évaluer seulement si le nombre de comptes suivis simultanément le justifie — le sondage est volontairement le choix le plus simple qui marche.

**Fichiers** : `frontend/src/components/broker/BrokerConnectionPanel.vue`, `api/src/Controllers/BrokerSyncController.php`.

**Repéré le** : 2026-08-07. **Priorité** : basse — pas de gêne à l'échelle actuelle.

---

## Erreurs non gérées dans `account-view.spec.js`

**Contexte** : repéré en passant la suite frontend pendant l'évol #23 (identifiants brokers partagés, [91-broker-shared-credentials.md](91-broker-shared-credentials.md)). Le fichier passe ses 10 tests mais Vitest remonte 10 « unhandled errors » et **sort en code 1**, ce qui rend le résultat de `npx vitest run` inexploitable en l'état pour une CI.

L'origine est `CustomFieldsTab.vue`, dont le `onMounted` appelle `customFields.fetchDefinitions()` sans que `@/services/customFields` soit mocké dans ce spec : la promesse rejetée n'est rattrapée par personne. Rien à voir avec le broker — préexistant, vérifié en stashant les changements de l'évol #23.

**À faire** : mocker le service des champs personnalisés dans `account-view.spec.js`, ou attraper le rejet dans le store. Les deux sont à une ligne près.

**Fichiers** : `frontend/src/__tests__/account-view.spec.js`, `frontend/src/stores/customFields.js`.

**Repéré le** : 2026-08-10. **Priorité** : moyenne — aucun impact produit, mais un code de sortie non nul masque les vraies régressions le jour où il y en aura une.

---

## Clés i18n admin absentes des deux locales

**Contexte** : repéré par `/check-i18n` pendant l'évol #23. Six clés renvoyées par le back n'existent ni dans `fr.json` ni dans `en.json`. L'utilisateur voit donc la clé brute à la place du message :

- `admin.error.user_not_found`, `admin.error.cannot_self_suspend`, `admin.error.cannot_self_delete` (`AdminUserService.php`)
- `admin.settings.error.unknown_key`, `admin.settings.error.invalid_type` (`PlatformSettingsService.php`)
- `admin.settings.error.value_required` (`AdminSettingsController.php`)

Préexistant, sans rapport avec les identifiants brokers — pas corrigé sur la branche de l'évol #23 pour ne pas l'élargir.

**À faire** : ajouter les six clés dans les deux locales.

**Fichiers** : `frontend/src/locales/fr.json`, `frontend/src/locales/en.json`.

**Repéré le** : 2026-08-10. **Priorité** : moyenne — visible seulement dans l'admin, mais c'est une clé brute affichée à l'écran.

---

## `positions.symbol` et `symbol_aliases.journal_symbol` recopient un code au lieu de référencer l'actif

**Contexte** : le code d'un actif (`symbols.code`) est **recopié en chaîne**, sans clé étrangère, dans deux tables :

| Où | Colonne | Écrit par |
|---|---|---|
| positions | `positions.symbol` | chaque trade / ordre |
| alias broker | `symbol_aliases.journal_symbol` | import CSV |

`symbol_account_settings` référence pourtant l'actif proprement par `symbol_id` + FK, et `trading_plans` le fait aussi depuis la réécriture de la migration 042 (docs/99).

Le symptôme immédiat — renommer un actif depuis *Mes actifs* détachait l'historique en silence — **est corrigé** : `SymbolCodeRenamer` propage le nouveau code aux deux tables dans la transaction du renommage. Mais c'est un **emplâtre** : tant que le lien est une chaîne, tout nouveau chemin d'écriture devra penser à propager, et rien dans le schéma ne l'y oblige.

Restent d'ailleurs deux trous que la propagation ne bouche pas :

- **la suppression** : `softDelete` laisse les alias derrière lui (c'est pourquoi `SymbolResolver` doit se défendre contre un alias pointant vers un actif disparu) ;
- **les écritures hors service** : la synchro broker et l'import écrivent `positions.symbol` directement.

**À faire** : faire pointer les deux colonnes sur `symbols.id` avec une FK. Chantier réel : migration + backfill (les codes non rattachés à un actif doivent en créer un), et reprise de tout ce qui filtre ou groupe par `positions.symbol` — stats, filtres, synchro broker, import.

**Fichiers** : `api/database/schema.sql`, `api/src/Repositories/PositionRepository.php`, `api/src/Repositories/SymbolAliasRepository.php`, `api/src/Repositories/StatsRepository.php`, `api/src/Services/Import/ImportService.php`, `api/src/Services/Broker/`.

**Repéré le** : 2026-08-17. **Priorité** : moyenne — le symptôme est traité, la dette de modèle reste.

---

## Un actif ne porte qu'un seul symbole

**Contexte** : deux tables cohabitent et il faut les distinguer.

- **`symbols`** = les actifs de l'utilisateur. Une ligne = un actif, **un** symbole (`code`) et un nom. Créable à la main dans *Mes actifs*, ou à la volée depuis les sélecteurs (trade, ordre, position, et depuis le lot 1 le plan).
- **`symbol_aliases`** = des symboles **supplémentaires**, ceux d'un broker, rattachés à un actif existant (`broker_symbol` + `broker_template` → `journal_symbol`). Aucune route, aucun contrôleur, aucun écran : seul `ImportService` en écrit, en devinant le mapping pendant un import CSV.

En pratique ça ne bloque personne au quotidien : si les alertes envoient `GER40`, il suffit de saisir `GER40` comme symbole de l'actif. Le manque n'apparaît que si **le même actif est traité chez deux brokers qui le nomment différemment** : il faut alors créer deux actifs, saisir la valeur du point deux fois, et les statistiques se retrouvent coupées en deux pour un même marché.

`SymbolResolver` (docs/99) sait déjà lire les alias — il ne les trouvera simplement que chez un utilisateur passé par un import.

**À faire** : permettre de rattacher plusieurs symboles à un actif — une section « autres symboles » dans *Mes actifs*. Le repository a déjà `upsert`, `findAllByUserId` et `delete` ; il manque le service, le contrôleur, les routes et l'écran.

**Fichiers** : `api/src/Repositories/SymbolAliasRepository.php`, `api/src/Services/SymbolService.php`, `frontend/src/views/SymbolsView.vue`.

**Repéré le** : 2026-08-17. **Priorité** : basse — contournable en saisissant le bon symbole sur l'actif ; ne gêne que le multi-broker sur un même marché.

---

## Le symbole d'un trade n'est pas contrôlé contre les actifs de l'utilisateur

**Contexte** : repéré pendant le lot 4 du chantier « plans » (docs/100). `positions.symbol` est une chaîne libre : `TradeService` et `OrderService` vérifient qu'elle est non vide et ≤ 50 caractères, rien de plus. L'interface ne laisse pas passer n'importe quoi (le champ *Instrument* est un sélecteur sur Mes actifs, avec un « + » pour en créer un), mais l'API accepte un symbole inconnu.

Conséquence : une position dont le symbole n'existe pas dans Mes actifs a un risque **non chiffrable**, ce qui désactive silencieusement les plafonds de risque du plan auquel elle serait rattachée.

Une remarque au passage sur la façon dont ce point a été trouvé : la doc 83 affirmait qu'un symbole « sans valeur du point configurée » désactivait le filtre de risque. C'était **faux** — `point_value` est `NOT NULL DEFAULT 1` et ne peut pas être ≤ 0 — et l'affirmation avait déjà été recopiée dans une infobulle utilisateur. Corrigé partout au lot 4.

**À faire** : soit valider le symbole contre les actifs à la création d'un trade/ordre par l'API, soit créer l'actif à la volée comme le fait l'import.

**Fichiers** : `api/src/Services/TradeService.php`, `api/src/Services/OrderService.php`.

**Repéré le** : 2026-08-17. **Priorité** : basse — non atteignable depuis l'interface.

---

## `PlanEvaluator::evaluate()` est à sept paramètres

**Contexte** : la signature a pris `$symbol` au lot 1 puis `$openRiskPercent` au lot 4 (docs/99, docs/100). Le second est passé **en dernier**, après `$now`, pour ne pas casser les appelants ni la trentaine d'appels de test — pratique, mais l'ordre ne raconte plus rien.

**À faire** : au prochain filtre, passer un objet de signal (`PlanSignal`) plutôt qu'un huitième argument positionnel. Trois appelants (`TradeService`, `OrderService`, `TradingViewWebhookService`) et `PlanEvaluatorTest`.

**Fichiers** : `api/src/Services/PlanEvaluator.php` et ses trois appelants.

**Repéré le** : 2026-08-17. **Priorité** : basse — dette de forme, aucun effet produit.

---

## `trades.pnl` mélange deux unités selon l'origine du trade

**Contexte** : repéré en creusant le P&L faux d'une clôture broker (docs/104). Deux
sources écrivent la même colonne dans deux unités différentes :

- saisie manuelle → `(exit_price − entry_price) × size`, soit des **points bruts ×
  lots**. `point_value` n'intervient pas, c'est assumé et documenté
  (`docs/09-trades.md:72`) ;
- synchro broker → le P&L **en devise** annoncé par le broker, commissions
  comprises.

Un DAX de 0,5 lot stoppé à 66 points vaut donc 33 s'il est saisi à la main, et le
montant réel en euros s'il est synchronisé. Les deux se cumulent dans les mêmes
statistiques et la même case de calendrier. Le risque, lui, est toujours en devise
(`SignalRiskCalculator` : `size × sl_points × point_value`), donc un plan compare
un plafond en euros à un P&L qui peut être en points.

**Danger associé, corrigé depuis** (voir [105](105-jambes-broker-non-recalculees.md)) :
`TradeService::recalcRealizedMetrics()` recalculait chaque jambe avec la formule
en points bruts à chaque édition d'un trade, y compris l'ajout d'une simple note,
et écrasait donc les P&L en devise venus du broker. Les jambes portant un
`external_id` sont désormais laissées intactes. Les trades déjà écrasés avant ce
correctif ne sont pas récupérables : la valeur broker n'existe plus qu'en base
côté plateforme.

**À faire** : trancher l'unité de `trades.pnl` (devise partout, le plus probable)
et appliquer `point_value` sur le chemin manuel.

**Fichiers** : `api/src/Services/TradeService.php` (l.348, l.999),
`api/src/Services/SignalRiskCalculator.php`, `api/src/Repositories/StatsRepository.php`.

**Repéré le** : 2026-08-31. **Priorité** : haute dès que les connecteurs broker
sont ouverts aux utilisateurs — deux unités dans une même statistique.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
