# Étape 3 — Authentification JWT

## Résumé

Implémentation complète du système d'authentification JWT avec register, login, refresh token (rotation), logout et profil utilisateur.

## Endpoints

| Méthode | Route            | Auth | Description                              |
|---------|------------------|------|------------------------------------------|
| POST    | /auth/register   | Non  | Inscription d'un nouvel utilisateur      |
| POST    | /auth/login      | Non  | Connexion → access_token + refresh_token |
| POST    | /auth/refresh    | Non  | Rotation du refresh_token                |
| POST    | /auth/logout     | Oui  | Supprime tous les refresh tokens du user |
| GET     | /auth/me         | Oui  | Retourne le profil de l'utilisateur      |

## Architecture

```
Routes → AuthMiddleware (routes protégées) → AuthController → AuthService → Repositories
```

- **AuthController** : contrôleur mince, délègue tout à AuthService
- **AuthService** : logique métier (validation, hashing, génération de tokens)
- **UserRepository** : accès aux données utilisateur (create, findByEmail, findById, existsByEmail)
- **RefreshTokenRepository** : gestion des refresh tokens (create, findByToken, deleteByToken, deleteAllByUserId)
- **AuthMiddleware** : extrait le Bearer token, décode le JWT, attache `user_id` à la Request

## Configuration (`api/config/auth.php`)

| Paramètre           | Valeur   | Description                       |
|----------------------|----------|-----------------------------------|
| jwt_secret           | .env     | Clé secrète pour signer les JWT   |
| jwt_algo             | HS256    | Algorithme de signature           |
| access_token_ttl     | 900s     | Durée de vie du access token (15 min) |
| refresh_token_ttl    | 604800s  | Durée de vie du refresh token (7 jours) |
| password_min_length  | 8        | Longueur minimale du mot de passe |

## Règles de validation du mot de passe

- Minimum 8 caractères
- Au moins 1 majuscule
- Au moins 1 minuscule
- Au moins 1 chiffre

## Tokens

### Access Token (JWT)
- Signé avec HS256 via `firebase/php-jwt`
- Payload : `sub` (user_id), `iat`, `exp`
- Durée de vie : 15 minutes
- Transmis dans le header `Authorization: Bearer <token>`

### Refresh Token
- Token opaque (hex, 64 caractères = 32 bytes)
- Stocké en base dans la table `refresh_tokens`
- Durée de vie : 7 jours
- **Rotation** : à chaque refresh, l'ancien token est supprimé et un nouveau est créé

## Codes d'erreur

| Scénario             | HTTP | Code                   | message_key                      | field        |
|----------------------|------|------------------------|----------------------------------|--------------|
| Champ manquant       | 422  | VALIDATION_ERROR       | auth.error.field_required        | email/password/... |
| Email invalide       | 422  | VALIDATION_ERROR       | auth.error.invalid_email         | email        |
| Password faible      | 422  | VALIDATION_ERROR       | auth.error.password_too_weak     | password     |
| Email déjà pris      | 409  | EMAIL_TAKEN            | auth.error.email_taken           | email        |
| Mauvais credentials  | 401  | INVALID_CREDENTIALS    | auth.error.invalid_credentials   | —            |
| Token manquant       | 401  | TOKEN_MISSING          | auth.error.token_missing         | —            |
| Token expiré         | 401  | TOKEN_EXPIRED          | auth.error.token_expired         | —            |
| Token invalide       | 401  | TOKEN_INVALID          | auth.error.token_invalid         | —            |
| Refresh token invalide | 401 | REFRESH_TOKEN_INVALID | auth.error.refresh_token_invalid | —            |

## Fichiers créés/modifiés

### Nouveaux fichiers de production (8)
- `api/config/auth.php` — configuration JWT et règles de mot de passe
- `api/src/Core/MiddlewareInterface.php` — interface pour les middlewares
- `api/src/Exceptions/ValidationException.php` — exception de validation (422)
- `api/src/Exceptions/UnauthorizedException.php` — exception d'authentification (401)
- `api/src/Repositories/UserRepository.php` — accès aux données utilisateur
- `api/src/Repositories/RefreshTokenRepository.php` — gestion des refresh tokens en BDD
- `api/src/Services/AuthService.php` — logique métier d'authentification
- `api/src/Controllers/AuthController.php` — contrôleur REST
- `api/src/Middlewares/AuthMiddleware.php` — middleware JWT

### Fichiers modifiés (3)
- `api/src/Core/Request.php` — ajout de `setAttribute()`/`getAttribute()` (bag d'attributs)
- `api/src/Core/Router.php` — support middleware en 3e paramètre, support instances d'objet en handler
- `api/config/routes.php` — ajout des 5 routes auth

### Fichiers de tests (8)
- `api/tests/Unit/Core/RequestAttributeTest.php` — 3 tests
- `api/tests/Unit/Core/RouterMiddlewareTest.php` — 5 tests
- `api/tests/Unit/Exceptions/AuthExceptionsTest.php` — 4 tests
- `api/tests/Unit/Services/AuthServiceTest.php` — 23 tests
- `api/tests/Unit/Middlewares/AuthMiddlewareTest.php` — 6 tests
- `api/tests/Integration/Repositories/UserRepositoryTest.php` — 8 tests
- `api/tests/Integration/Repositories/RefreshTokenRepositoryTest.php` — 4 tests
- `api/tests/Integration/Auth/AuthFlowTest.php` — 18 tests

## Tests

**71 nouveaux tests**, total : **112 tests, 250 assertions**, tous au vert.

### Couverture par sous-étape

| Sous-étape | Tests | Description |
|------------|-------|-------------|
| 3.1 | 8 | Request attributes + Router middleware |
| 3.2 | 4 | Exceptions auth (Validation, Unauthorized) |
| 3.3 | 12 | Repositories (User + RefreshToken) |
| 3.4 | 23 | AuthService (validation, register, login, refresh, logout, profile, tokens) |
| 3.5 | 6 | AuthMiddleware (JWT decode, erreurs) |
| 3.6 | 18 | Intégration complète (flux register → login → refresh → me → logout) |

## Notes techniques

- Le mot de passe est hashé avec `PASSWORD_BCRYPT` via `password_hash()`
- Le `findById` ne retourne jamais le champ `password` (sécurité)
- Le `findByEmail` retourne le `password` (nécessaire pour la vérification lors du login)
- La rotation de refresh token supprime l'ancien avant de créer le nouveau
- Le logout supprime **tous** les refresh tokens du user (déconnexion globale)
- La base MariaDB tourne sur le port **3307** (configuration WAMP locale)
