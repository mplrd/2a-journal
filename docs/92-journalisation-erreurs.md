# Étape 92 — Journalisation des erreurs non gérées

## Résumé

Toute exception non rattrapée laisse désormais **une ligne JSON sur stderr**, captée par le flux du conteneur (Railway en test comme en production). Deux endroits étaient aveugles :

1. `api/public/index.php` — le `catch (\Throwable)` renvoyait `INTERNAL_ERROR` et **n'écrivait rien**.
2. `BrokerSyncSchedulerService` — le `catch (Throwable)` de la boucle de synchro incrémentait un compteur et perdait la cause.

Dans la foulée, **le mode `APP_DEBUG` est supprimé** : c'est lui qui renvoyait le message, le fichier et la ligne de l'exception **au client**. La réponse d'erreur est désormais générique dans tous les environnements, sans réglage susceptible de rester ouvert par mégarde.

## Pourquoi

Le 2026-08-09, l'endpoint du P&L journalier a répondu 500 pendant plusieurs jours, en production et en environnement de test, **avec 1721 tests verts**. La cause (un `GROUP BY` refusé par MySQL sous `ONLY_FULL_GROUP_BY`) était introuvable : sur 333 lignes de logs conteneur, zéro ligne d'erreur PHP. Il a fallu rejouer l'appel avec `APP_DEBUG=true` — un mode qui renvoie la cause **au client**, donc inutilisable en production.

Le même trou masquait un défaut de synchro : en environnement de test, la connexion cTrader 20 enregistrait un `FAILED` (« cTrader token refresh failed ») **à chaque passe, toutes les 20 minutes**. La seule façon de le savoir était d'interroger `sync_logs` en base.

## `App\Core\ErrorLogger`

Une classe, une méthode :

```php
ErrorLogger::logThrowable(string $job, string $event, Throwable $e, array $context = []): void
```

Le format et le puits sont ceux de `BrokerLogger` — une seule recette de grep couvre les deux. `error_log()` est le puits portable : en CLI il part sur STDERR, sous un SAPI HTTP il atterrit dans le log d'erreur du SAPI, que Railway capte. (`STDERR`, la constante, n'existe qu'en CLI et provoquerait une erreur fatale en HTTP.)

Exemple de ligne :

```json
{"job":"api","event":"unhandled_exception","ts":"2026-08-12T12:55:20Z",
 "class":"PDOException","message":"SQLSTATE[42000]...","file":"/app/api/src/Repositories/StatsRepository.php","line":214,
 "trace":["/app/api/src/Controllers/StatsController.php:88 App\\Controllers\\StatsController->daily"],
 "previous":[{"class":"PDOException","message":"...","file":"...","line":1}],
 "method":"GET","path":"/stats/daily"}
```

### Deux garanties de confidentialité

**La trace est reconstruite à la main.** `getTraceAsString()` affiche les arguments d'appel : un mot de passe passé à une méthode se retrouve dans le log dès qu'une exception traverse cette frame. PHP les masque par défaut (`zend.exception_ignore_args=1`), mais le réglage est modifiable au runtime — un conteneur qui le bascule transformerait le log en dépotoir de secrets. On ne lit donc que `file`, `line`, `class` et `function`, **jamais `args`**. La garantie est la nôtre, pas celle de la configuration. Un test le vérifie en forçant `zend.exception_ignore_args=0`.

**Le chemin est assaini avant d'être écrit**, par `ErrorLogger::redactPath()` :

- la **query string est retirée** — clés de partage, jetons de vérification d'e-mail et codes de réinitialisation y circulent ;
- le **jeton du webhook TradingView est masqué** : `/webhooks/tradingview/***`. C'est le seul paramètre de route du routeur qui soit un secret et non un identifiant — il autorise à passer des ordres, et il voyage **dans le chemin**, donc retirer la query string ne suffisait pas. Repéré par l'audit de sécurité pendant cette livraison.

Le masquage se déclenche où que le préfixe apparaisse dans l'URI, et pas seulement en tête : Apache sert l'API derrière un alias `/api` en local, que le routeur retire mais que `REQUEST_URI` porte encore.

Tous les autres paramètres de route sont des identifiants numériques, laissés intacts : ce sont eux qui rendent une ligne de log exploitable.

### Bornes

| Constante | Valeur | Raison |
|---|---|---|
| `MAX_TRACE_FRAMES` | 20 | Une récursion folle produit des milliers de frames. Une ligne trop longue est tronquée par les collecteurs, et un JSON tronqué ne se parse plus du tout. Le champ `trace_dropped` dit combien ont été écartées |
| `MAX_PREVIOUS` | 3 | La cause réelle se cache presque toujours dans la première `previous` — une `PDOException` emballée dans une exception métier |

Un message porteur d'UTF-8 invalide ne fait pas perdre la ligne : `JSON_INVALID_UTF8_SUBSTITUTE` substitue plutôt que d'échouer.

## Les deux branchements

### `api/public/index.php`

Contexte joint : `method` et `path`.

## Suppression de `APP_DEBUG`

Le réglage ne pilotait qu'une chose : ajouter `message`, `file` et `line` à la réponse 500 **envoyée au client**. C'était la seule façon de savoir pourquoi une 500 se produisait — autrement dit, diagnostiquer la production se payait en divulgation. Maintenant que l'exception est enregistrée côté serveur, l'arbitrage disparaît, et un interrupteur qu'on peut oublier en position ouverte vaut mieux supprimé que documenté.

Ce qui a été retiré :

| Fichier | Retrait |
|---|---|
| `api/public/index.php` | Les deux blocs `if ($appConfig['debug'])`, et le `require config/app.php` devenu sans objet — `$appConfig` n'y servait qu'à ça |
| `api/config/app.php` | La clé `debug` (un commentaire explique pourquoi elle ne revient pas) |
| `api/.env.example` | `APP_DEBUG=true` |
| `scheduler/entrypoint.sh` | `APP_DEBUG` du bloc `/etc/container-env` et de sa ligne `export` — le scheduler le propageait sans que rien ne le lise |

Docs alignées : `docs/28-stripe-billing.md`, `docs/specs/admin-backoffice-v1.md`, `docs/specs/trading-journal-specs-v5.md`.

Une variable `APP_DEBUG` encore présente sur un service Railway est désormais inerte : rien ne la lit. Elle peut être retirée à l'occasion, sans urgence.

### `BrokerSyncSchedulerService`

`job=broker-sync`, `event=connection_sync_failed`. Contexte joint :

| Champ | Intérêt |
|---|---|
| `connection_id` | Quelle connexion |
| `consecutive_failures` | La série en cours ; le disjoncteur se déclenche au seuil configuré |
| `deactivated` | `true` au déclenchement — **c'est ce qu'il faut grepper**, c'est le moment où un utilisateur cesse silencieusement d'être synchronisé |

**Un report pour cause de rate limit n'est pas journalisé comme un échec.** Un bannissement temporaire du broker est un cadencement attendu, pas un défaut ; le journaliser à chaque passe apprendrait au lecteur à ignorer le canal.

**Une passe nominale n'écrit rien.** Le job tourne toutes les minutes.

## Comment lire

```bash
npx --yes @railway/cli logs -p <project-id> -e <env> -s api    --since 3h
npx --yes @railway/cli logs -p <project-id> -e <env> -s scheduler --since 3h
```

Les erreurs HTTP sortent sur le service **api**, les échecs de synchro sur **scheduler**.

## Tests

| Fichier | Portée |
|---|---|
| `tests/Unit/Core/ErrorLoggerTest.php` | 14 tests : identité de l'exception, contexte, trace, **absence d'arguments**, plafonds, chaînage des causes, **assainissement du chemin**, silence sur stdout |
| `tests/Unit/Services/Broker/BrokerSyncSchedulerServiceTest.php` | 4 tests ajoutés : cause journalisée, disjoncteur signalé, silence sur une passe nominale, report de rate limit non journalisé |

Suites complètes au vert : **1089 unitaires**, **738 intégration**.

## Limite connue

`index.php` reste un script procédural, non couvert par un test : le branchement y est un appel d'une ligne, c'est `ErrorLogger` qui porte le comportement testé.
