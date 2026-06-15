# 77 - BingX sync : pacing proactif + report sur ban de fréquence (rate-limit 100410)

Suite directe de [76 - walk jusqu'à l'origine](76-bingx-walk-to-origin.md), dont le complément 1 avait posé un premier backoff sur `100410` en notant qu'il faudrait « une cadence proactive entre requêtes » si le rate-limit persistait. Il a persisté : voici le vrai correctif.

## Symptôme

Sur le test env (lecture directe des `sync_logs`), une sync BingX échouait encore systématiquement en ~10 s, malgré le backoff du fix 76 :

```
BingX API error (code 100410): The endpoint trigger frequency limit rule is
currently in the disabled period and will be unblocked after 1781512502872
```

## Cause racine

Deux choses, mal comprises au fix 76 :

1. **Le `100410` n'est pas un simple « ralentis »** : quand on dépasse la fréquence autorisée sur un endpoint (surtout `/allOrders`, appelé une fois par symbol × fenêtre de 7 j sur un compte multi-symbols), BingX **bannit l'endpoint pour une fenêtre** et renvoie dans le message le **timestamp epoch ms de déblocage**.
2. **La fenêtre de ban est longue** : mesurée à **~5 minutes** (`1781512502872` = 2026-06-15 08:35:02 UTC, pour une sync trippée à 08:30). Le backoff exponentiel du fix 76 (4 essais, ~7,5 s max) était donc non seulement trop court, mais **inutile** : re-tenter dans la même seconde un endpoint banni 5 minutes ne pouvait que ré-échouer.

Le walk « jusqu'à l'origine » (fix 76) génère par construction beaucoup de requêtes rapprochées → il trippe la limite de fréquence avant même d'avoir fini.

## Correctif

### Piste 1 — pacing proactif (le vrai fix)

`BingxConnector::httpGetSigned()` insère désormais un **délai fixe entre chaque requête sortante** (`DEFAULT_REQUEST_PACING_MS = 300 ms`) pour ne **jamais** trip la limite de fréquence. La toute première requête d'une sync part immédiatement ; chaque suivante est espacée. Le scheduler tourne en cron CLI, donc une sync « plus lente mais fiable » est le bon compromis.

- Compteur `requestsSent` remis à zéro par `resetSyncCache()` (appelé en début de chaque sync), pour que la 1ʳᵉ requête ne soit jamais pénalisée.
- Pacing **injectable** au constructeur (`requestPacingMs`, 5ᵉ param) ; **0 désactive** (tests).

### Piste 2 — parser le timestamp de déblocage, et choisir attente vs report

Sur un `100410`, on lit le timestamp `unblocked after <ms>` du message :

- **Ban court** (déblocage ≤ `RATE_LIMIT_MAX_WAIT_MS = 120 s`) → on **attend exactement** ce qu'il faut puis on retente, pour ne pas perdre la progression d'un walk en cours.
- **Ban long** (> 120 s, typiquement les ~5 min observées) → on **échoue vite** sans retenter, en jetant une **`BrokerRateLimitException`** typée qui porte le timestamp de déblocage. Pas de `usleep` de plusieurs minutes qui chevaucherait le tick cron suivant.
- **Pas de timestamp parsable** (si BingX change le format) → repli sur le backoff exponentiel borné du fix 76, puis report.

### Le report ne doit pas tuer la connexion

C'est le point critique pour que « reprise au prochain cron » fonctionne vraiment. `BrokerSyncSchedulerService` incrémente `consecutive_failures` sur **tout** throwable et désactive la connexion (`status = ERROR`) après `max_consecutive_failures` (défaut **3** en prod). Si un report rate-limit comptait comme un échec, 3 syncs throttlées d'affilée **désactiveraient une connexion saine** → plus aucune resync.

Le scheduler catch donc `BrokerRateLimitException` **avant** le `Throwable` générique : il **n'incrémente pas** le compteur, **ne marque pas** `ERROR`, laisse la connexion `ACTIVE`, et compte un nouveau tally **`deferred`**. Le prochain tick cron relance la connexion une fois le ban levé — et le pacing évite de re-trip.

## Fichiers

**Ajoutés**
- `api/src/Exceptions/BrokerRateLimitException.php` — exception domaine (sur le modèle de `BrokerOrderException`), porte `unblockAtMs` + `endpoint`.

**Modifiés**
- `api/src/Services/Broker/BingxConnector.php`
  - Constantes `DEFAULT_REQUEST_PACING_MS = 300`, `RATE_LIMIT_MAX_WAIT_MS = 120_000`.
  - Constructeur : 5ᵉ param optionnel `?int $requestPacingMs`.
  - `httpGetSigned()` : pacing inter-requêtes + logique parse-timestamp / attente courte / report long ; jette `BrokerRateLimitException` au-delà du cap.
  - Seams `sleepMs()` / `nowMs()` (protected) + helper `parseUnblockMs()`.
  - `resetSyncCache()` remet `requestsSent` à 0. `codeHint(100410)` mis à jour.
- `api/src/Services/Broker/BrokerSyncSchedulerService.php`
  - Catch dédié `BrokerRateLimitException` (pas d'incrément, pas de `markError`), tally `deferred` ajouté au résumé (et donc au log JSON du CLI `sync-brokers.php`, qui sérialise le résumé entier).
- `api/tests/Unit/Services/Broker/BingxConnectorTest.php`
  - `createRecordingConnector()` + sous-classe `RecordingBingxConnector` (enregistre les `sleepMs` au lieu de dormir → timing assertable sans wall-clock).
  - Tests : pacing entre requêtes (pas avant la 1ʳᵉ), reset du pacing par `resetSyncCache`, attente sur ban court, report immédiat (`BrokerRateLimitException`) sur ban long, repli backoff sans timestamp.
- `api/tests/Unit/Services/Broker/BrokerSyncSchedulerServiceTest.php`
  - Test : un `BrokerRateLimitException` au seuil du breaker ne désactive pas la connexion et compte `deferred = 1`.

## Tests

`cd api && vendor/bin/phpunit --testsuite Unit` → **814 verts** (0 régression ; warnings/deprecations pré-existants, sans rapport). Suite Broker seule : **190 verts**.

## Validation end-to-end (test env Railway)

Le fix est validé en mocks uniquement. À valider contre le vrai compte BingX :

1. Merge sur `develop` → deploy auto Railway test env.
2. Reset du curseur pour rejouer le walk première-sync :
   ```sql
   USE 2ai_tools_journal;
   UPDATE broker_connections SET sync_cursor = NULL, symbols_seen = NULL WHERE provider = 'BINGX';
   ```
3. Déclencher une sync (UI ou tick cron) et vérifier dans `sync_logs` : **plus de `FAILED 100410`**, `deals_fetched > 0`, et dans le log CLI JSON un éventuel `deferred` (et non `failed`) si un ban survient malgré le pacing.

## Reste à vérifier / pistes si insuffisant

- **Tuning du pacing** : 300 ms est une valeur de départ. Si BingX trippe encore (limite de fréquence plus stricte que prévu), monter la constante — ou la rendre configurable via `platform_settings` pour ajuster sans redéploiement.
- **Piste 3 (réduction de volume)** non implémentée : restreindre le walk `/allOrders` aux seules fenêtres où chaque symbol a eu de l'activité (vues via `/user/income`) au lieu de balayer tous les chunks 7 j. À faire uniquement si pacing + report ne suffisent pas.
- **Statut UI du report** : un report rate-limit laisse aujourd'hui `last_sync_status = FAILED` sur la connexion (message explicite « deferring to next sync »). Un statut dédié (ex. `SyncStatus::PARTIAL`/`RATE_LIMITED`) distinguerait « throttlé, va reprendre » de « cassé » — noté dans `docs/evolutions.md`.
