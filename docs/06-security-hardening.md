# Étape 6 : Renforcement sécurité & privacy

## Contexte

Avant de démarrer l'étape Positions, les audits `/audit-security` et `/audit-privacy` ont identifié plusieurs axes d'amélioration :
- 2 WARN sécurité : absence de rate limiting et de security headers
- 1 WARN privacy : localStorage (mitigé par CSP)
- Améliorations mineures : SELECT *, bcrypt cost par défaut, validations de longueur, validation des paramètres de route

## Corrections apportées

### 1. Colonnes explicites dans UserRepository

**Fichier** : `api/src/Repositories/UserRepository.php`

`findByEmail()` utilisait `SELECT *`, exposant potentiellement des colonnes non nécessaires (comme `deleted_at`). Remplacé par une liste explicite de colonnes, incluant `password` (nécessaire pour la vérification lors du login).

### 2. Coût bcrypt explicite

**Fichiers** : `api/config/auth.php`, `api/src/Services/AuthService.php`

Le coût bcrypt était implicite (valeur par défaut PHP = 10). Ajout d'une configuration explicite `bcrypt_cost => 12` et utilisation dans `password_hash()`. Un coût de 12 offre un meilleur compromis sécurité/performance.

### 3. Validations de longueur

**Fichier** : `api/src/Services/AuthService.php`

Nouvelles validations dans `validateRegisterData()` et `validatePassword()` :
- Email : max 255 caractères (`auth.error.email_too_long`)
- Prénom / Nom : max 100 caractères chacun (`auth.error.field_too_long`)
- Mot de passe : max 72 bytes (`auth.error.password_too_long`) — bcrypt tronque silencieusement au-delà

### 4. Validation des paramètres de route

**Fichier** : `api/src/Services/AccountService.php`

Ajout d'un helper privé `validateId(int $id)` qui rejette les ID ≤ 0 avec le code `error.invalid_id`. Appelé dans `get()`, `update()` et `delete()`.

### 5. Security headers

**Fichiers** : `api/config/security.php` (nouveau), `api/public/index.php`

Headers de sécurité ajoutés sur toutes les réponses API :

| Header | Valeur | Rôle |
|--------|--------|------|
| `X-Content-Type-Options` | `nosniff` | Empêche le MIME sniffing |
| `X-Frame-Options` | `DENY` | Empêche l'embedding en iframe |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Limite les informations de referrer |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | CSP restrictive pour l'API |

### 6. Rate limiting (brute-force protection)

Protection contre les attaques par force brute sur les endpoints d'authentification.

#### Architecture

```
Request → RateLimitMiddleware → Controller
              ↓
    RateLimitRepository (DB)
```

#### Table `rate_limits`

| Colonne | Type | Description |
|---------|------|-------------|
| `ip` | VARCHAR(45) | Adresse IP (IPv4/IPv6) |
| `endpoint` | VARCHAR(100) | Route protégée |
| `attempts` | INT UNSIGNED | Nombre de tentatives |
| `window_start` | TIMESTAMP | Début de la fenêtre |

Clé unique sur `(ip, endpoint)` avec UPSERT pour les incréments.

#### Limites configurées

| Endpoint | Max tentatives | Fenêtre |
|----------|---------------|---------|
| `/auth/login` | 10 | 15 minutes |
| `/auth/register` | 5 | 15 minutes |
| `/auth/refresh` | 10 | 15 minutes |

#### Composants

- **`RateLimitRepository`** : increment (UPSERT), getAttempts, cleanup
- **`RateLimitMiddleware`** : implements MiddlewareInterface, vérifie et incrémente à chaque requête
- **`TooManyRequestsException`** : HTTP 429 avec code `TOO_MANY_REQUESTS`
- **`Request::getClientIp()`** : lit `REMOTE_ADDR` en production, `127.0.0.1` par défaut en tests

## Fichiers créés

| Fichier | Rôle |
|---------|------|
| `api/config/security.php` | Configuration headers + rate limits |
| `api/src/Repositories/RateLimitRepository.php` | CRUD rate limits |
| `api/src/Middlewares/RateLimitMiddleware.php` | Middleware anti brute-force |
| `api/src/Exceptions/TooManyRequestsException.php` | Exception HTTP 429 |
| `api/tests/Unit/Config/SecurityConfigTest.php` | Tests config sécurité |
| `api/tests/Unit/Middlewares/RateLimitMiddlewareTest.php` | Tests unitaires middleware |
| `api/tests/Integration/Repositories/RateLimitRepositoryTest.php` | Tests intégration repository |
| `api/tests/Integration/Auth/RateLimitFlowTest.php` | Tests intégration flow complet |

## Fichiers modifiés

| Fichier | Modification |
|---------|-------------|
| `api/public/index.php` | Ajout security headers |
| `api/config/auth.php` | Ajout `bcrypt_cost => 12` |
| `api/config/routes.php` | Câblage rate limit middleware sur routes auth |
| `api/database/schema.sql` | Ajout table `rate_limits` |
| `api/src/Core/Request.php` | Ajout `clientIp` + `getClientIp()` |
| `api/src/Repositories/UserRepository.php` | Colonnes explicites dans `findByEmail()` |
| `api/src/Services/AuthService.php` | Bcrypt cost config + validations longueur |
| `api/src/Services/AccountService.php` | Helper `validateId()` |
| `frontend/src/locales/fr.json` | 5 nouvelles clés i18n |
| `frontend/src/locales/en.json` | 5 nouvelles clés i18n |

## Tests

- **Backend** : 180 tests, 438 assertions (avant : 150 tests, +30 nouveaux)
- **Frontend** : 28 tests (inchangé, i18n keys ajoutées)
- Tous les tests passent ✓

### Nouveaux tests (24)

| Fichier | Tests | Description |
|---------|-------|-------------|
| `UserRepositoryTest` | +1 | Colonnes explicites findByEmail |
| `AuthServiceTest` | +5 | Bcrypt cost, email trop long, noms trop longs, password trop long |
| `AccountServiceTest` | +3 | validateId pour get/update/delete avec id=0 |
| `RequestTest` | +1 | getClientIp() par défaut |
| `SecurityConfigTest` | +6 | Validation config sécurité |
| `RateLimitMiddlewareTest` | +6 | Tests unitaires middleware avec mocks |
| `RateLimitRepositoryTest` | +6 | Tests intégration CRUD rate limits |
| `RateLimitFlowTest` | +2 | Tests intégration 429 login/register |
