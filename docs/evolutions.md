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

## Performance / dashboards

### Refonte Performance — layout, agrégats cycliques, splits par catégorie

**Contexte** : le dashboard Performance actuel mélange des widgets utiles avec un widget peu actionnable, et certains agrégats temporels ne sont pas exposés sous l'angle qui aide vraiment à décider.

**À faire** :
- **P&L par période à côté du P&L par symbole** dans le layout, pour que la lecture "par symbole / par moment" se fasse en un seul coup d'œil. Et surtout : passer le P&L par période à des agrégats **cycliques** plutôt que linéaires — "le lundi", "en semaine 52", "en juin" — beaucoup plus actionnables que "le 17 mars" pour spotter une saisonnalité ou un biais de jour. Côté SQL c'est juste un `DAYOFWEEK / WEEK / MONTH` au lieu de `DATE` dans `getStatsByPeriod` (l'effective date est déjà calculée par `effectiveDate()`), et un toggle UI "linéaire / cyclique" sur le widget.
- **Splits WR/RR par catégorie de setup** : maintenant que les setups portent une `category` (timeframe / pattern / context, cf. doc 48), on peut splitter le graphe WR/RR par setup en sous-graphes par catégorie — ou a minima sortir un **graphe dédié WR/RR par timeframe**, qui est probablement le découpage le plus parlant. Donnée déjà dispo via `StatsRepository::getStatsBySetup` + jointure `setups.category`.
- **Virer le widget WR/RR par type de compte** du dashboard pour gagner la place. Le code (`StatsRepository::getStatsByAccountType` + le composant frontend) reste en repo — il pourra resservir dans des widgets configurables plus tard.

**Repéré le** : 2026-05-06.
**Priorité** : moyenne. Pas bloquant, mais améliore franchement la lisibilité du dashboard.

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

## Docs

### Tracker beta : splitter "DONE" en "OK prod" / "En attente de livraison"

**Contexte** : `docs/retours-beta-tests.md` a aujourd'hui un tableau `✅ DONE` qui mélange deux états bien distincts :
- mergé sur `develop` mais pas encore sur `main` (= pas en prod) ;
- mergé sur `main` (= effectivement livré aux beta-testeurs).

**À faire** : scinder le tableau DONE en deux sous-tableaux **"En attente de livraison"** (develop) et **"Livré en prod"** (main), avec un critère clair (présence sur `main` au moment de la mise à jour). Mettre à jour la convention en tête du fichier en conséquence.

**Repéré le** : 2026-05-04 (après merge D-02).
**Priorité** : basse, à faire à la prochaine mise à jour du tracker.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
