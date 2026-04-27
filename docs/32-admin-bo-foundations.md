# Étape 32 — Backoffice admin : fondations + users + paramètres

## Résumé

Première version du backoffice d'administration de la plateforme :

- SPA dédiée `admin/` (séparée du frontend user) servie sur son propre domaine
- Auth partagée avec le SPA user via JWT enrichi du claim `role`
- Gestion des utilisateurs : liste, suspension, reset password, suppression
- Paramètres plateforme runtime-éditables avec fallback env var

Spec de référence : `docs/specs/admin-backoffice-v1.md`.

## Architecture

```
SPA User (frontend/)              SPA Admin (admin/)
journal.../com                    admin.../com
   │                                  │
   └─────────── JWT auth ─────────────┘
                  ▼
          API PHP (api/)
          /api/auth/*  (commun)
          /api/admin/* (RequireAdmin)
                  ▼
              MariaDB
```

L'admin SPA n'a pas son propre backend : elle consomme les routes `/api/admin/*` de l'api existante, gatées par un middleware `RequireAdminMiddleware` qui lit le claim `role` du JWT.

## Modèle BDD

### Migration 008 — `users.role` + `users.suspended_at`

Idempotent (INFORMATION_SCHEMA checks). Ajoute :
- `role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER'`
- `suspended_at TIMESTAMP NULL DEFAULT NULL`
- Index `idx_users_role`

### Migration 009 — Table `platform_settings`

```sql
CREATE TABLE platform_settings (
    id, setting_key, setting_value, value_type ENUM('BOOL','INT','STRING'),
    description, updated_at, updated_by_user_id
);
```

Backe les paramètres éditables via le BO. Le service ne stocke **que** les keys whitelistées par `PlatformSettingsService::knownSettings()`.

## Bootstrap du premier admin

Aucun user n'a `role=ADMIN` après une fresh install. Mécanique d'auto-promotion :

1. Définir l'env var `INITIAL_ADMIN_EMAIL=ton@email.com` sur le service `api` Railway (test + prod)
2. Au boot, `entrypoint.sh` lance `cli/bootstrap-admin.php` après les migrations
3. Si un user existe avec cet email et qu'il n'est **pas** déjà ADMIN → il est promu
4. Idempotent : aucun effet si déjà ADMIN, ou si l'user n'existe pas (log warning)

Le script ne fait jamais échouer le boot — un email mal saisi laisse passer le démarrage normalement.

**Première utilisation** : tu t'inscris d'abord normalement via le SPA user, tu poses l'env var, tu redéploies une fois, tu es admin. Reconnecte-toi pour obtenir un nouveau JWT avec le claim `role=ADMIN`.

## API endpoints

Toutes les routes `/api/admin/*` sont protégées par `[AuthMiddleware, RequireAdminMiddleware]`.

### Users
| Method | URI | Description |
|---|---|---|
| GET | `/admin/users` | Liste paginée (query: `search`, `status` ∈ {active, suspended, all}, `page`, `per_page`) |
| GET | `/admin/users/{id}` | Détail |
| POST | `/admin/users/{id}/suspend` | Suspend |
| POST | `/admin/users/{id}/unsuspend` | Réactive |
| POST | `/admin/users/{id}/reset-password` | Email de reset password |
| DELETE | `/admin/users/{id}` | Soft-delete |

**Garde-fous** : un admin ne peut ni se suspendre, ni se supprimer lui-même (`admin.error.cannot_self_*`).

**Sanitization** : la réponse exclut systématiquement `password_hash`, `refresh_tokens`, `stripe_customer_id` via une whitelist explicite dans `AdminUserService::sanitize()`.

### Settings
| Method | URI | Description |
|---|---|---|
| GET | `/admin/settings` | Liste des paramètres whitelistés avec leur valeur effective + source (`db`/`env`/`default`) |
| PUT | `/admin/settings/{key}` | Met à jour la valeur (validée selon le `SettingType`) |

**Pattern de résolution** (DB → env → null) :
```
PlatformSettingsService::resolve(key) :
  1. Lit DB → si valeur, retourne (typée)
  2. Sinon lit env var → si valeur, retourne (typée)
  3. Sinon retourne null
```

Pas de défaut hardcodé dans le resolver. Les callers gèrent `null` explicitement (le scheduler skip avec `status: "unconfigured"` si une valeur requise est `null`).

### Settings whitelistés (v1)

| Key | Type | Env var fallback | Consommé par | Effet |
|---|---|---|---|---|
| `broker_auto_sync_enabled` | BOOL | `BROKER_AUTO_SYNC_ENABLED` | `cli/sync-brokers.php` (chaque tick) | Active/désactive l'auto-sync brokers |
| `broker_sync_interval_minutes` | INT | `BROKER_SYNC_INTERVAL_MINUTES` | id | Min minutes entre 2 syncs d'une connexion |
| `broker_sync_max_failures` | INT | `BROKER_SYNC_MAX_FAILURES` | id | Seuil circuit breaker auto-sync |
| `email_verification_enabled` | BOOL | `EMAIL_VERIFICATION_ENABLED` | `AuthService::register` | Force la vérification email à l'inscription |
| `mail_enabled` | BOOL | `MAIL_ENABLED` | `EmailService::send` | Active l'envoi d'emails (sinon log seulement) |
| `billing_grace_days` | INT | `BILLING_GRACE_DAYS` | `AuthService::register` | Délai de grâce après échec paiement (jours) |

Modifier une valeur depuis le BO prend effet **au prochain appel** du consommateur (pas de redeploy). Le scheduler relit la DB à chaque tick (max ~1 min de délai). Les services HTTP (auth, email) relisent à chaque requête concernée.

## SPA admin (`admin/`)

```
admin/
├── Dockerfile, docker/nginx.conf, .dockerignore
├── package.json, vite.config.js, index.html
├── src/
│   ├── main.js, App.vue
│   ├── router/index.js (garde route ADMIN)
│   ├── stores/ (auth, users, settings)
│   ├── services/ (api, auth, users, settings)
│   ├── views/ (LoginView, UsersView, SettingsView)
│   ├── components/AdminLayout.vue
│   ├── locales/ (fr.json, en.json — bilingue)
│   ├── utils/jwt.js (decode role pour UX uniquement)
│   └── __tests__/ (vitest)
```

**JWT côté client** : `utils/jwt.js` décode le payload sans vérifier la signature, **uniquement** pour la garde de route (UX). L'autorisation effective est server-side via `RequireAdminMiddleware` qui lit le claim depuis le JWT vérifié. Un user qui forgerait un JWT côté client ne passerait jamais la vérification serveur.

## Déploiement Railway

### Pré-requis sur l'api existante

**À mettre à jour avant de merger en prod** :

1. Env var `INITIAL_ADMIN_EMAIL` sur `api` test et `api` prod (valeur : ton email)
2. Env var `CORS_ORIGINS` sur `api` test et prod : **ajouter le domaine admin** :

   **Test** :
   ```
   CORS_ORIGINS=https://test-journal.2a-trading-tools.com,https://test-admin.2a-trading-tools.com
   ```

   **Prod** :
   ```
   CORS_ORIGINS=https://journal.2a-trading-tools.com,https://admin.2a-trading-tools.com
   ```

   Sans cette mise à jour, le SPA admin reçoit un erreur CORS dès le premier appel API.

### Création des 2 services Railway `admin`

Pattern identique au `frontend/` user. À faire **une fois par environnement** (test puis prod) :

1. Railway → Project canvas → "+ New" → Deploy from GitHub repo
2. Settings → Source :
   - Root Directory : `/admin` (ou `admin` selon convention)
   - Dockerfile Path : `Dockerfile`
   - Branch : `develop` (test) ou `main` (prod)
3. Settings → Variables :
   - `VITE_API_URL=/api` (l'admin SPA et l'api seront sur le même Railway domain group, voir frontend pour le pattern)
4. Settings → Networking :
   - Custom Domain : `admin.2a-trading-tools.com` (prod) ou `test-admin.2a-trading-tools.com` (test)
5. Settings → Replicas : 1 (suffisant pour un SPA statique)

### Premier déploiement

1. Push sur `develop` → service `admin` test rebuild → SPA disponible sur `test-admin.2a-trading-tools.com`
2. Tu vas sur `test-admin...` → tu te connectes avec ton compte (déjà admin grâce à `INITIAL_ADMIN_EMAIL`)
3. Si erreur CORS au login → tu n'as pas mis à jour `CORS_ORIGINS` sur l'api test. Étape 2 du pré-requis.
4. Si erreur "Accès réservé aux administrateurs" → ton email ne matche pas `INITIAL_ADMIN_EMAIL`, ou tu es loggé avec un compte non-admin
5. Si tout OK → tu vois la liste des users, tu peux les gérer

Une fois validé en test, même procédure pour le prod (branch `main`).

## Couverture de tests

| Test | Type | Fichier |
|---|---|---|
| Migration 008 idempotente | manuel | `database/migrate.php` |
| Migration 009 idempotente | manuel | id |
| User repo : role default, suspended_at shape, promote, suspend, unsuspend | Integration | `UserRepositoryTest` |
| AuthService login refused if suspended | Integration | `AuthFlowTest` |
| AuthService JWT contient `role` (USER + ADMIN) | Integration | id |
| Bootstrap CLI : promote, idempotent, missing user, missing env | Integration | `BootstrapAdminTest` |
| RequireAdminMiddleware : ADMIN allowed, USER 403, no role 403 | Unit | `RequireAdminMiddlewareTest` |
| AdminUserService : list, get, suspend, unsuspend, reset, delete | Integration | `AdminUserFlowTest` |
| AdminUserService : self-action guards | Integration | id |
| AdminUserService : password jamais leaké | Integration | id |
| PlatformSettingsRepository : CRUD basique | Integration | `PlatformSettingsRepositoryTest` |
| PlatformSettingsService : resolver DB > env > null, type coercion | Unit | `PlatformSettingsServiceTest` |
| Admin settings flow : list, update, validation type, unknown key | Integration | `AdminSettingsFlowTest` |
| JWT decode util | Unit (Vitest) | `admin/src/__tests__/jwt.spec.js` |
| Auth store : login admin OK, login non-admin rejected, logout | Unit (Vitest) | `auth-store.spec.js` |
| Users store : fetch, suspend update, remove | Unit (Vitest) | `users-store.spec.js` |
| Settings store : fetch, update | Unit (Vitest) | `settings-store.spec.js` |

**Suite complète backend** : 1015 tests verts. **Suite complète admin SPA** : 11 tests verts.

## Choix d'implémentation

### Pourquoi un SPA séparé et pas une route `/admin` du frontend user ?

- **Surface d'attaque réduite** : aucun code admin shippé aux users non-admin
- **Indépendance opérationnelle** : on peut stopper le SPA admin pendant maintenance sans affecter les users
- **Headers HTTP plus stricts** (X-Frame-Options DENY, X-Robots-Tag noindex)
- **Domaine dédié** propre : `admin.2a-trading-tools.com`

### Pourquoi pas de défaut hardcodé dans `PlatformSettingsService::resolve()` ?

Avoir un default caché dans le resolver fait passer une mauvaise config pour un comportement normal. Avec `null`, l'absence de configuration est visible par le caller, qui doit traiter le cas explicitement (skip+log, throw, default au call-site). Plus honnête, plus debuggable.

### Pourquoi 3 settings seulement en v1 ?

Wirer un setting demande de toucher le code qui le consomme (par ex. `mail_enabled` impliquerait de refactorer `EmailService`). On limite la v1 aux settings utilisés par le scheduler broker (déjà touché récemment) pour rester en scope. Les autres viennent quand on touchera leurs services respectifs.

### Pourquoi `INITIAL_ADMIN_EMAIL` env var et pas un CLI ?

Zéro friction au déploiement. Tu poses la var une fois, tu redéploies, c'est fait. Le script est idempotent donc aucun effet aux deploys suivants. Un CLI explicite serait plus "propre" mais demande de se souvenir de le lancer après chaque setup d'env vierge.

## Scope exclu (v1.1+)

- **Audit log des actions admin** (table `admin_actions`) — déféré
- **Rate limiting sur `/admin/*`** — déféré (surface d'attaque limitée pour un BO interne)
- **Promotion d'un user en admin depuis le BO** — pas en v1 (risque escalade), promotion via env var uniquement
- **Wiring des 3 settings restants** (mail_enabled, email_verification_enabled, billing_grace_days) — quand on touchera leurs services
- **Overview plateforme (KPIs)** — déféré
