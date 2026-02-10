# Etape 1 — Setup Backend

## Fonctionnalités

Mise en place du socle technique backend : point d'entrée unique, routeur, gestion des requêtes/réponses, connexion BDD, CORS, gestion d'erreurs et enums métier.

### Point d'entrée unique (Front Controller)

- Toutes les requêtes HTTP passent par `api/public/index.php`
- Chargement des variables d'environnement (`.env`) via un parseur custom (pas de dépendance externe)
- Middleware CORS intégré avec vérification de l'origine contre une whitelist
- Gestion centralisée des erreurs : `HttpException` → réponse JSON, exceptions génériques → détails en mode debug uniquement

### Routeur

- Enregistrement de routes par méthode HTTP (`GET`, `POST`, `PUT`, `DELETE`)
- Extraction de paramètres dynamiques : `/users/{id}` → `$request->getRouteParam('id')`
- Support multi-paramètres : `/users/{userId}/posts/{postId}`
- Handlers en closure ou en tableau `[Controller::class, 'method']`
- 404 automatique si aucune route ne correspond

### Request / Response

- **Request** : encapsulation de la requête HTTP, parsing du body JSON, headers normalisés (insensible à la casse), paramètres de query string
- **Response** : format JSON standardisé avec deux factories statiques (`success()` / `error()`)
- Toutes les réponses suivent le contrat API du projet (cf. CLAUDE.md)

### Connexion BDD

- Singleton PDO avec lazy initialization
- Prepared statements natifs activés (`ATTR_EMULATE_PREPARES = false`)
- Mode exception pour les erreurs PDO
- Méthode `reset()` pour l'isolation des tests

### Enums métier

7 enums PHP string-backed couvrant tous les statuts du domaine :
- `OrderStatus` : PENDING, EXECUTED, CANCELLED, EXPIRED
- `TradeStatus` : OPEN, SECURED, CLOSED
- `ExitType` : BE, TP, SL, MANUAL
- `Direction` : BUY, SELL
- `AccountType` : BROKER, PROPFIRM
- `AccountMode` : DEMO, LIVE, CHALLENGE, FUNDED
- `TriggerType` : MANUAL, SYSTEM, WEBHOOK, BROKER_API

### Health Check

- `GET /health` : retourne statut, timestamp et version de l'API

## Choix d'implémentation

### Framework custom (pas de framework tiers)

Choix délibéré d'un MVC custom sans Symfony ni Laravel. Avantages : contrôle total, légèreté, compréhension complète du code, pas de dépendances inutiles. Les seules dépendances sont `firebase/php-jwt` (auth JWT) et `respect/validation` (validation).

### Parseur .env custom

Plutôt que d'utiliser `vlucas/phpdotenv`, un parseur intégré dans `index.php` gère les variables d'environnement. Il supporte les valeurs quotées/non-quotées et ne surcharge pas les variables déjà définies dans l'environnement système.

### Gestion CORS dans le point d'entrée

Le CORS est traité directement dans `index.php` avant le routage. Les requêtes preflight (`OPTIONS`) sont interceptées et retournent un 204 sans passer par le routeur. Les origines autorisées sont configurables via `CORS_ORIGINS` (séparées par virgules).

### Strip du préfixe /api dans Request

L'Apache VirtualHost utilise un `Alias /api → api/public/`. Le `Request::capture()` supprime le préfixe `/api` de l'URI pour que les routes soient définies sans ce préfixe (ex: `/health` et non `/api/health`).

### Réponses i18n par clés de traduction

Les réponses d'erreur contiennent un `message_key` (clé de traduction) et non du texte humain. Le frontend se charge de la traduction via `vue-i18n`. Cela découple complètement le backend de la langue d'affichage.

### Enums string-backed PHP 8.1+

Les enums PHP natifs garantissent la type-safety dans le code tout en restant sérialisables en JSON et compatibles avec les `ENUM` MariaDB. Les méthodes `from()` et `tryFrom()` permettent la conversion sûre depuis les valeurs BDD.

### Configuration par fichiers PHP

Chaque aspect (app, database, cors, routes) a son propre fichier de config retournant un tableau. Ce pattern permet un chargement simple via `require` et une testabilité facile.

## Couverture des tests

**41 tests, 96 assertions — tout au vert**

### Unit — Response (6 tests, 13 assertions)

| Test | Scénario | Statut |
|------|----------|--------|
| `testSuccessReturnsCorrectFormat` | Format success standard avec data | Pass |
| `testSuccessWithMeta` | Champ meta optionnel présent | Pass |
| `testSuccessWithCustomStatusCode` | Code HTTP personnalisé (ex: 201) | Pass |
| `testErrorReturnsCorrectFormat` | Format error avec code, message_key, field | Pass |
| `testErrorWithoutField` | Champ field optionnel absent | Pass |
| `testErrorDefaultsTo400` | Code HTTP par défaut = 400 | Pass |

### Unit — Request (11 tests, 20 assertions)

| Test | Scénario | Statut |
|------|----------|--------|
| `testCreateSetsMethodAndUri` | Création manuelle avec méthode et URI | Pass |
| `testGetBodyReturnsAllData` | Accès au body complet | Pass |
| `testGetBodyReturnsSingleKey` | Accès à une clé spécifique du body | Pass |
| `testGetBodyReturnsDefaultForMissingKey` | Valeur par défaut si clé absente | Pass |
| `testGetQueryReturnsAllParams` | Accès aux query params complets | Pass |
| `testGetQueryReturnsSingleParam` | Accès à un param spécifique | Pass |
| `testGetQueryReturnsDefaultForMissingParam` | Valeur par défaut si param absent | Pass |
| `testGetHeaderNormalizesName` | Normalisation casse et underscores des headers | Pass |
| `testGetHeaderReturnsNullForMissing` | Header absent retourne null | Pass |
| `testRouteParams` | Paramètres de route injectés par le routeur | Pass |

### Unit — Router (8 tests, 14 assertions)

| Test | Scénario | Statut |
|------|----------|--------|
| `testDispatchClosureHandler` | Dispatch d'un handler closure | Pass |
| `testDispatchControllerHandler` | Dispatch d'un handler contrôleur | Pass |
| `testThrows404WhenNoRouteMatches` | 404 si aucune route ne correspond | Pass |
| `testThrows404WhenMethodDoesNotMatch` | 404 si la méthode HTTP ne correspond pas | Pass |
| `testRouteWithParams` | Extraction d'un paramètre dynamique | Pass |
| `testRouteWithMultipleParams` | Extraction de paramètres multiples | Pass |
| `testPostRoute` | Enregistrement et dispatch route POST | Pass |
| `testPutRoute` / `testDeleteRoute` | Routes PUT et DELETE | Pass |

### Unit — Enums (14 tests, 28 assertions)

| Test | Scénario | Statut |
|------|----------|--------|
| `testOrderStatusCases` | 4 cases OrderStatus présentes | Pass |
| `testOrderStatusFromAndTryFrom` | Conversion from/tryFrom | Pass |
| `testTradeStatusCases` | 3 cases TradeStatus présentes | Pass |
| `testTradeStatusFromAndTryFrom` | Conversion from/tryFrom | Pass |
| `testExitTypeCases` | 4 cases ExitType présentes | Pass |
| `testExitTypeFromAndTryFrom` | Conversion from/tryFrom | Pass |
| `testDirectionCases` | 2 cases Direction présentes | Pass |
| `testDirectionFromAndTryFrom` | Conversion from/tryFrom | Pass |
| `testAccountTypeCases` | 2 cases AccountType présentes | Pass |
| `testAccountTypeFromAndTryFrom` | Conversion from/tryFrom | Pass |
| `testAccountModeCases` | 4 cases AccountMode présentes | Pass |
| `testAccountModeFromAndTryFrom` | Conversion from/tryFrom | Pass |
| `testTriggerTypeCases` | 4 cases TriggerType présentes | Pass |
| `testTriggerTypeFromAndTryFrom` | Conversion from/tryFrom | Pass |

### Integration — HealthCheck (2 tests, 6 assertions)

| Test | Scénario | Statut |
|------|----------|--------|
| `testHealthCheckReturnsSuccess` | GET /health → 200, success, status ok, timestamp, version | Pass |
| `testPostHealthReturns404` | POST /health → NotFoundException | Pass |

### Non testé

- **Database** : le singleton PDO sera testé implicitement par les tests d'intégration des étapes suivantes (CRUD, auth)
- **index.php** : le point d'entrée est testé manuellement via `curl` sur le health check

## Fichiers

| Fichier | Rôle |
|---------|------|
| `api/public/index.php` | Point d'entrée, .env, CORS, error handling |
| `api/public/.htaccess` | Réécriture Apache front controller |
| `api/config/app.php` | Config applicative (env, debug, jwt) |
| `api/config/database.php` | Config connexion MariaDB |
| `api/config/cors.php` | Config CORS (origines, méthodes, headers) |
| `api/config/routes.php` | Définition des routes API |
| `api/src/Core/Router.php` | Routeur HTTP avec paramètres dynamiques |
| `api/src/Core/Request.php` | Encapsulation requête HTTP |
| `api/src/Core/Response.php` | Réponses JSON standardisées |
| `api/src/Core/Database.php` | Singleton PDO |
| `api/src/Core/Controller.php` | Classe de base contrôleurs |
| `api/src/Exceptions/HttpException.php` | Exception HTTP générique |
| `api/src/Exceptions/NotFoundException.php` | Exception 404 |
| `api/src/Enums/*.php` | 7 enums métier |
| `composer.json` | Dépendances et autoloading PSR-4 |
| `api/phpunit.xml` | Configuration PHPUnit (suites Unit + Integration) |
| `.env.example` | Template variables d'environnement |
