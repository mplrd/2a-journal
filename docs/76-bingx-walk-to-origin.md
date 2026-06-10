# 76 - BingX sync : walk jusqu'à l'origine du compte (fix « 0 remonté sur compte plein »)

## Symptôme

Une sync BingX terminait en `SUCCESS` mais avec **0 partout** (0 récupéré, 0 importé, 0 ignoré) sur un compte pourtant **plein** d'historique. Aucune erreur, donc l'auth et la signature passaient — les appels atteignaient bien BingX. Le problème était en aval, dans la **découverte de l'activité**.

Symptôme insidieux : **les tests unitaires étaient verts** alors que le live ne remontait rien. C'est le piège qui avait déjà coûté deux réécritures du connecteur.

## Cause racine

Les trois walks d'historique de `BingxConnector` (découverte des symbols via `/user/income`, walk des fills `/allOrders`, et `fetchClosedOrders`) contenaient tous la même règle d'arrêt sur une première sync :

```php
if (empty($list) && $cursorMs === null) {
    break; // ⚠️ première fenêtre de 7 jours vide → on arrête TOUT
}
```

Sur une première sync, la walk s'arrêtait à la **première** fenêtre de 7 jours vide. Donc dès qu'un compte n'avait **aucune activité dans les 7 derniers jours** (semaine calme, pas de position ouverte, pas de funding récent), la découverte stoppait au tout premier chunk → **0 symbol découvert → 0 fill → 0 deal → 0 position → rien**. Tout l'historique, juste derrière cette fenêtre vide, restait invisible.

### Pourquoi les tests ne l'attrapaient pas

Chaque test simulait un walk en fournissant la séquence `[chunk avec données] → [chunk vide]` pour stopper. Le chunk vide arrivait toujours **après** les données, jamais **avant**. Le scénario « fenêtre récente vide, historique plus loin » n'était jamais exercé → vert en test, vide en prod.

## Correctif

Règle d'arrêt première-sync remplacée : au lieu de s'arrêter au **premier** chunk vide, on **tolère les trous** et on continue de remonter jusqu'à `maxEmptyChunks` fenêtres vides **consécutives** (signal qu'on a atteint l'origine du compte / l'horizon de rétention BingX), borné par un plancher de sécurité absolu (5 ans).

- **Défaut : 12 fenêtres** (~84 jours de silence continu toléré). Une dormance de trading plus longue que `maxEmptyChunks × 7 jours` tronquerait l'historique au-delà — d'où une valeur volontairement généreuse, **injectable** au constructeur (`new BingxConnector($client, $baseUrl, $maxEmptyChunks)`).
- **Sync incrémentale inchangée** : quand `sync_cursor` est posé, on descend jusqu'au curseur sans jamais s'arrêter sur un trou (comportement déjà correct, préservé).

### Refactor

Les trois boucles dupliquées (income / fills / closed orders) sont remplacées par un helper unique `BingxConnector::walkChunks()` qui centralise le chunking 7 jours, le plancher, et la nouvelle règle d'arrêt. Un `callable $handle(mixed $data): int` traite chaque chunk et retourne le nombre d'items vus (0 = fenêtre vide, alimente le compteur d'empties consécutifs).

## Fichiers

**Modifiés**
- `api/src/Services/Broker/BingxConnector.php`
  - Nouvelle constante `DEFAULT_MAX_EMPTY_CHUNKS = 12` + `FIRST_SYNC_MAX_LOOKBACK_SECONDS` (backstop 5 ans).
  - Constructeur : 3e param optionnel `?int $maxEmptyChunks`.
  - Nouvelle méthode privée `walkChunks()`.
  - `runReconstruction()` (walk des fills), `discoverSymbolsFromIncome()`, `fetchClosedOrders()` réécrits autour de `walkChunks()`.
- `api/tests/Unit/Services/Broker/BingxConnectorTest.php`
  - `createConnector()` accepte `maxEmptyChunks` (défaut **1** → reproduit la cadence « stop au 1er vide », les tests existants gardent leurs séquences serrées).
  - Nouveau test de régression `testFetchDealsWalksPastEmptyWindowsToReachOlderHistory` : compte plat, fenêtre récente vide, historique 2 fenêtres plus loin → 1 deal reconstruit. RED sur l'ancien code, GREEN après fix.

## Tests

`cd api && vendor/bin/phpunit tests/Unit/Services/Broker` → **152 verts** (0 régression ; les 2 deprecations/warning proviennent du vendor `textalk/websocket`, sans rapport).

## Validation end-to-end (test env Railway)

Le fix est validé en mocks uniquement. La validation contre le vrai compte BingX passe par l'env de test Railway :

1. Merge sur `develop` → deploy auto Railway test env.
2. **Reset du curseur** pour rejouer l'historique depuis l'origine (sinon la sync repart du curseur et ne teste pas le walk première-sync) :
   ```sql
   USE 2ai_tools_journal;
   UPDATE broker_connections SET sync_cursor = NULL, symbols_seen = NULL WHERE provider = 'BINGX';
   ```
3. Déclencher une sync (UI ou tick cron) et vérifier que récupérés/importés > 0 et que l'historique apparaît dans `/trades` + charts de perf.

## Reste à vérifier hors de ce fix

- **Solde du compte** (`/user/balance` v3) : chemin indépendant du walk. S'il remonte toujours vide après ce fix, c'est une question de **forme de réponse** à diagnostiquer séparément (voir `evolutions.md`).
- **Rétention réelle BingX** sur `/user/income` et `/allOrders` : si BingX renvoie une **erreur** (et non une réponse vide) au-delà d'un certain horizon, le walk pourrait la propager. À confirmer en conditions réelles ; la règle « N empties consécutifs » s'arrête normalement avant d'atteindre cet horizon.
