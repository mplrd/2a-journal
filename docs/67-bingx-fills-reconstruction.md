# 67 - BingX sync : reconstruction des positions à partir des fills

## Objectif

Le sync BingX d'origine (doc 64) s'appuyait sur `/openApi/swap/v1/trade/positionHistory` pour récupérer les positions clôturées. Cet endpoint ne renvoie que les positions **entièrement fermées** (taille retournée à 0). Tout le reste de l'activité — TP partiels, scaling out, trades clôturés en plusieurs fills — était invisible côté journal.

Pour un utilisateur avec des années d'historique BingX, ça donnait :
- 0 trade fermé remonté
- 1 position courante avec sa taille restante mais sans aucun historique de partial exits
- Des charts de performance vides faute de P&L réalisé

Ce refactor reconstruit l'activité côté connector à partir des **fills bruts** (orders exécutés), pas des positions agrégées.

## Architecture

### Endpoint source

`/openApi/swap/v2/trade/allOrders`, filtré sur `status IN (FILLED, PARTIALLY_FILLED)`. Chaque ligne renvoyée est un fill : `orderId`, `symbol`, `positionSide` (LONG/SHORT/BOTH), `side` (BUY/SELL), `reduceOnly`, `executedQty`, `avgPrice`, `profit`, `updateTime`.

### Pipeline

```
[1] /user/positions → liste des symbols actuellement ouverts
[2] symbols_seen (col JSON persistée sur broker_connections) → symbols vus précédemment
[3] union [1] ∪ [2] → ensemble à scanner sur /allOrders
[4] pour chaque symbol, chunk-walk allOrders (7 jours par requête, cap BingX) :
      - depuis now jusqu'au curseur (sync incrémental)
      - depuis now en remontant jusqu'à l'origine du compte (1ère sync) :
        on tolère les fenêtres vides et on s'arrête après N fenêtres vides
        CONSÉCUTIVES (cf. doc 76 — corrige l'ancien « stop au 1er chunk vide »
        qui masquait tout l'historique d'un compte calme sur 7 jours)
[5] normalizeBingxFill sur chaque ligne (filtre FILLED/PARTIALLY_FILLED)
[6] BingxFillReconstructor : grouper par (symbol, positionSide) + reconstruction
      - reduce_only=false → ouverture ou scaling-in (entry weighted-avg recalculé)
      - reduce_only=true → exit (ajout à exits[], cumul closed_size)
      - cumul closed >= cumul opened → cycle terminé → bucket "closed"
      - sinon → cycle ouvert → bucket "open"
[7] fetchDeals → bucket "closed" (consommé par ImportService.importNormalizedPositions)
[7'] fetchOpenPositions → bucket "open" (consommé par BrokerOpenSyncService.apply)
[8] symbols_seen mis à jour avec l'union des symbols vus pendant le sync
```

La walk est mémoïsée par instance de connector : un seul tick cron pull `/allOrders` une seule fois même si fetchDeals et fetchOpenPositions sont appelés tous les deux.

### Side: live snapshot fallback

Une position ouverte par BingX dont l'opening fill est antérieur à la fenêtre que `/allOrders` accepte ne sera pas reconstruite par la walk. Dans ce cas le connector la surface en mode "minimal" depuis le snapshot `/user/positions` (sans `exits[]`) pour garder une trace côté journal. Dédup avec les positions reconstruites via `(symbol, direction)`.

### Curseur

`broker_connections.sync_cursor` stocke le timestamp ms du fill le plus récent vu. À la sync suivante, la walk s'arrête dès qu'elle atteint ce timestamp — le steady-state d'un cron toutes les 5 min ne pull qu'un seul chunk de 7 jours, voire 0 si aucun fill nouveau.

## Migrations

**`022_broker_connections_symbols_seen.sql`** — ajoute `symbols_seen JSON NULL` à `broker_connections`. Idempotent via check INFORMATION_SCHEMA.

**`023_partial_exits_external_id.sql`** — ajoute `external_id VARCHAR(128) NULL` + index à `partial_exits`. Idempotent. Permet la dédup des exits insérés par sync (ex: `bingx_fill_<orderId>`) face aux exits créés manuellement par l'utilisateur (`external_id NULL`).

## Fichiers

**Nouveaux**
- `api/src/Services/Broker/BingxFillReconstructor.php` — pure logic, 1 méthode `reconstruct()` qui prend la liste plate des fills et sort `{closed: array, open: array}`.
- `api/tests/Unit/Services/Broker/BingxFillReconstructorTest.php` — 8 fixtures (49 assertions) : single open→close, multi-partial, scale-in, hedge mode, orphan reduce-only, sort par time.

**Modifiés**
- `api/src/Services/Broker/BingxConnector.php` — fetchDeals + fetchOpenPositions refaits autour de runReconstruction() ; nouvelles méthodes `setKnownSymbols()` (lecture symbols_seen), `getSeenSymbols()` (écriture symbols_seen), `resetSyncCache()` (cleanup entre syncs).
- `api/src/Services/Broker/DealNormalizer.php` — nouvelle méthode `normalizeBingxFill()` (alias-tolérante sur les noms de champs).
- `api/src/Services/Broker/BrokerOpenSyncService.php` — nouvelle dépendance optionnelle `PartialExitRepository`. `insertNewOpen()` + `updateBrokerFields()` insèrent les `exits[]` du snapshot avec dédup `external_id`.
- `api/src/Services/Broker/BrokerSyncService.php` — passe `symbols_seen` au connector via `setKnownSymbols()`, persiste l'union après sync.
- `api/src/Repositories/PartialExitRepository.php` — `create()` accepte `external_id` ; nouvelle méthode `existingExternalIdsForTrade()`.
- `api/src/Repositories/BrokerConnectionRepository.php` — `symbols_seen` whitelisté dans `update()`.
- `api/config/routes.php` + `api/cli/sync-brokers.php` — injection de `$partialExitRepo` dans `BrokerOpenSyncService`.

## Opérations manuelles à exécuter une fois sur Railway

Avant que la 1ère sync post-déploiement tourne, exécuter sur la console SQL Railway :

```sql
-- 1. Supprime les 3 positions orphelines (issues des syncs pré-fix opened_at,
-- des positions TRADE sans trade associé créées par les anciens bugs)
USE 2ai_tools_journal;
DELETE FROM positions WHERE id IN (6356, 6357, 6358);

-- 2. Reset le cursor BingX pour rejouer tout l'historique disponible
UPDATE broker_connections
SET sync_cursor = NULL, symbols_seen = NULL
WHERE provider = 'BINGX';
```

À la sync suivante (≤ 5 min cron), la walk reconstruit tout l'historique BingX accessible.

## Vérification end-to-end

1. Branche : déjà sur `feat/bingx-fills-reconstruction`. Migrations 022 + 023 appliquées en local (no-op si la colonne existait déjà via les checks INFORMATION_SCHEMA).
2. Tests : `cd api && vendor/bin/phpunit` — full suite verte.
3. Re-seed démo : `php api/database/seed-demo.php`.
4. Merge sur develop quand validé, push origin develop, attendre le deploy Railway.
5. SQL manuel ci-dessus.
6. Attendre le tick cron suivant ou trigger sync via UI.
7. Vérifier en base :
   ```sql
   SELECT p.symbol, p.direction, p.size, p.external_id, t.status, t.opened_at, t.closed_at,
          COUNT(pe.id) AS n_exits
   FROM positions p
   JOIN trades t ON t.position_id = p.id
   LEFT JOIN partial_exits pe ON pe.trade_id = t.id
   WHERE p.external_id LIKE 'bingx_%'
   GROUP BY p.id, t.id
   ORDER BY t.opened_at DESC;
   ```
   Les positions BingX doivent maintenant montrer leur historique de partial exits + leur P&L final pour les cycles complets.
8. Vérifier dans l'UI : `/trades` montre les trades historiques BingX, charts de perf peuplés avec les P&L réalisés.

## Limitations & suivi

- **`exit_type` toujours `MANUAL`** : `/allOrders` ne distingue pas un fill issu d'un TP, d'un SL ou d'un market exit de manière fiable. L'utilisateur peut éditer après import. À tracer dans evolutions.md si on veut le discriminer.
- **Frais (commission)** : pas inclus dans le P&L reconstruit. BingX retourne `commission` par fill mais le journal ne modélise pas les frais séparément aujourd'hui. Décision produit à prendre.
- **Hedge vs one-way mode** : géré théoriquement (groupement par `positionSide`) mais pas testé contre une sandbox réelle. Validation sandbox restera dans evolutions.
- **`symbols_seen` croissant** : aucun cleanup. En pratique borné (rare > 100 symbols par compte). Si besoin un jour : drop si pas vu depuis N mois.
- **Autres connecteurs** (cTrader, MetaApi, Ouinex) : pas migrés. Continuent à utiliser leur ancienne logique non-fills. Quand un sandbox sera disponible pour eux, appliquer le même pattern.
- **`BingxFillSyncFlowTest` (intégration bout en bout)** : pas écrit dans ce refactor — le unit test du reconstructor + le test du connector + le passage de la suite intégration (542 tests) couvrent les surfaces critiques. À ajouter en backlog si besoin de garantir des invariants spécifiques BingX → journal.
