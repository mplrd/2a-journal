# Spécification — Backoffice Admin v1

**Statut** : à valider (2026-04-24)
**Ref spec initiale** : `trading-journal-specs-v5.md` § 2.1.2 (Rôles — évolution future)

---

## 1. Objectif

Fournir une interface dédiée permettant à un administrateur de la plateforme de :

1. **Gérer les utilisateurs** : visibilité, suspension, reset de mot de passe, suppression.
2. **Configurer les paramètres métier de l'application** : features flags et seuils actuellement codés en env vars Railway, sans nécessiter un redeploy à chaque changement.

Le BO n'est **pas** destiné aux utilisateurs finaux : il est strictement réservé aux personnes ayant le rôle `ADMIN`.

Il remplace aujourd'hui des interventions manuelles :
- modification de vars d'env Railway + redeploy → quelques minutes + audit lourd
- requêtes SQL directes en prod pour suspendre un user → risqué, non tracé
- pas de vue d'ensemble de l'état de la plateforme

---

## 2. Architecture

### 2.1 Vue d'ensemble

```
┌────────────────────────┐      ┌────────────────────────┐
│  SPA User (existant)   │      │  SPA Admin (nouveau)   │
│  journal.2a-...com     │      │  admin.2a-...com       │
│  frontend/             │      │  admin/                │
└───────────┬────────────┘      └───────────┬────────────┘
            │                               │
            │        même JWT auth          │
            │    (role claim dans token)    │
            │                               │
            └───────────────┬───────────────┘
                            ▼
              ┌──────────────────────────┐
              │  API PHP (existant)      │
              │  api/ — api.2a-...com    │
              │                          │
              │  /api/auth/*  (commun)   │
              │  /api/admin/* (nouveau,  │
              │   middleware RequireAdmin)│
              │  /api/trades, /accounts, │
              │  etc. (inchangés)        │
              └───────────┬──────────────┘
                          ▼
                ┌──────────────────┐
                │  MariaDB Railway │
                │  users + new     │
                │  platform_settings│
                └──────────────────┘
```

### 2.2 Décisions structurelles

| Décision | Choix retenu | Justification |
|---|---|---|
| SPA dédié vs intégré | **Dédié** (`admin/`) | Séparation stricte, surface d'attaque réduite, peut être stoppé en maintenance indépendamment, design propre. |
| Auth séparée vs partagée | **Partagée** (JWT enrichi du `role`) | Un admin est un user avec un flag. Pas de système d'auth dupliqué à maintenir. |
| Endpoints admin | **`/api/admin/*`** dans l'api existant | Même codebase backend. Middleware `RequireAdmin` gate toutes ces routes. |
| Bootstrap 1er admin | **Env var `INITIAL_ADMIN_EMAIL`** | Auto-promotion au boot de l'api si l'user existe et n'est pas déjà admin. Zéro action manuelle au déploiement. Idempotent (pas de re-promotion si déjà admin). |
| Paramètres dynamiques | **Table `platform_settings` + fallback env var** | La BDD est la source de vérité quand une valeur existe. Sinon fallback sur l'env var. Permet de bootstraper un env neuf. |

### 2.3 Déploiement Railway

Deux nouveaux services (un par environnement test/prod) :

- **`admin`** : build depuis `admin/Dockerfile`, sert le SPA statique, domaine `admin.2a-trading-tools.com` (prod) / `test-admin.2a-trading-tools.com` (test)
- **Pas de nouveau service backend** — les routes admin sont ajoutées à l'api existante.

Pattern identique à celui utilisé pour `frontend/` (voir `docs/31-broker-auto-sync.md` pour la référence de setup Railway).

---

## 3. Scope fonctionnel

### 3.1 Modules du BO

| Module | Contenu | Priorité |
|---|---|---|
| **Auth** | Login (email + mot de passe), logout, refresh JWT, garde de route basée sur `role=ADMIN` | P1 (bloquant) |
| **Gestion des users** | Liste paginée, détails, suspension, reset password, suppression (soft-delete) | P1 |
| **Paramètres plateforme** | Liste des paramètres applicatifs éditables, modification avec validation | P1 |
| **Overview plateforme** | KPIs globaux (nb users actifs, nb trades, MRR, etc.) | P2 |
| **Audit log** | Trace de toutes les actions admin (qui, quoi, quand, cible) | P2 |
| **Rate limiting** | Protection contre les abus sur les routes `/api/admin/*` | P2 |

P1 = livré en v1. P2 = livré en v1.1/v2 si ça tourne bien.

### 3.2 Gestion des users — détail

Actions disponibles sur un user :

| Action | Description | Contrainte |
|---|---|---|
| Lister | DataTable paginée : email, créé le, dernier login, statut abonnement, nb trades, statut actif/suspendu | — |
| Voir détail | Fiche user avec tous les champs + historique | — |
| Suspendre | `suspended_at = NOW()`, bloque les logins jusqu'à unsuspend | Pas soi-même |
| Réactiver | `suspended_at = NULL` | — |
| Reset password | Envoie un mail de reset (même flow que "Mot de passe oublié") | — |
| Supprimer | Soft-delete (même mécanique que l'auto-delete depuis le profil user) | Pas soi-même |

Filtres de recherche sur la liste : recherche par email (texte libre), filtre statut (actif / suspendu / tous).

### 3.3 Paramètres plateforme — détail

Actuellement les comportements métier sont pilotés par des env vars Railway. Pour modifier une valeur, on édite Railway → redeploy → ~2 minutes d'indispo. On migre les variables suivantes vers une gestion BDD, avec fallback env var :

| Paramètre | Type | Défaut | Rôle |
|---|---|---|---|
| `broker_auto_sync_enabled` | bool | `false` | Active/désactive globalement l'auto-sync brokers |
| `broker_sync_interval_minutes` | int (1-1440) | `15` | Fréquence d'auto-sync d'une même connexion |
| `broker_sync_max_failures` | int (1-10) | `3` | Seuil du circuit breaker auto-sync |
| `email_verification_enabled` | bool | `false` | Oblige la vérification email à l'inscription |
| `mail_enabled` | bool | `false` | Active l'envoi d'emails (reset password, notifications) |
| `billing_grace_days` | int (0-30) | `14` | Délai de grâce après échec de paiement Stripe |

**Conservation en env var (non migrés)** :
- Secrets : `JWT_SECRET`, `BROKER_ENCRYPTION_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `RESEND_API_KEY`
- Connectivité : `DB_*`, `CORS_ORIGINS`, `FRONTEND_URL`, `APP_URL`, `METAAPI_BASE_URL`, `CTRADER_WS_*`
- Environnement : `APP_ENV`, `APP_DEBUG`
- Bootstrap : `INITIAL_ADMIN_EMAIL` (action "set-once", pas une feature)

**Pattern de résolution** (implémenté côté backend) :

```
PlatformSettings::get(key) → mixed|null :
  if (DB a une row avec cette key) → retourne la valeur DB (typée)
  else if (env var existe et non vide) → retourne la valeur env (typée)
  else → retourne null
```

Pas de défaut hardcodé dans le resolver. Si rien n'est configuré nulle part, la valeur est `null` et c'est la **responsabilité du code appelant** de décider quoi faire :
- traiter `null` comme "désactivé" (booléens : default to `false`)
- skipper l'opération avec un log d'avertissement (entiers requis)
- lever une exception si la valeur est critique
- utiliser un default au call-site, explicite

Ainsi :
- Env var absente + DB absente → `null`, le caller voit immédiatement qu'aucune source n'est configurée
- Env var présente + DB absente → env gagne (état actuel inchangé pour les déploiements existants)
- DB présente → toujours prioritaire (permet override runtime depuis le BO)

**Pourquoi pas de défaut hardcodé** : un default caché dans le resolver fait passer une mauvaise config pour un comportement normal. Avec `null`, l'absence de configuration est visible par le caller, qui doit traiter le cas explicitement. Plus honnête, plus debuggable.

### 3.4 Overview plateforme (P2)

KPIs calculés on-the-fly :

- Total users (dont nb suspendus)
- Users actifs 30j (au moins 1 trade créé/modifié dans les 30 derniers jours)
- Total positions + total trades créés (cumul historique)
- Abonnements Stripe actifs (count + MRR estimé)
- Nb de connexions broker actives
- Date du dernier run scheduler (via `last_sync_at` le plus récent)

---

## 4. Modélisation

### 4.1 Migrations

**Migration `008_user_roles_and_suspension`**

```sql
ALTER TABLE users
  ADD COLUMN role ENUM('USER','ADMIN') NOT NULL DEFAULT 'USER' AFTER email,
  ADD COLUMN suspended_at TIMESTAMP NULL DEFAULT NULL AFTER role;

CREATE INDEX idx_users_role ON users(role);
```

**Migration `009_platform_settings`**

```sql
CREATE TABLE IF NOT EXISTS platform_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    value_type ENUM('BOOL','INT','STRING') NOT NULL,
    description VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id INT UNSIGNED NULL,

    UNIQUE KEY uk_platform_settings_key (setting_key),
    CONSTRAINT fk_platform_settings_user FOREIGN KEY (updated_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Migration `010_admin_actions`** *(P2, déféré)*

```sql
CREATE TABLE IF NOT EXISTS admin_actions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(50) NOT NULL,  -- e.g. 'USER_SUSPEND', 'SETTING_UPDATE'
    target_type ENUM('USER','SETTING') NOT NULL,
    target_id VARCHAR(100) NOT NULL,  -- user id OR setting key
    payload JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_admin_actions_admin (admin_user_id),
    KEY idx_admin_actions_created (created_at),
    CONSTRAINT fk_admin_actions_user FOREIGN KEY (admin_user_id)
        REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.2 API endpoints

Toutes les routes `/api/admin/*` sont protégées par `AuthMiddleware` + `RequireAdminMiddleware`. Retour standard `{ success, data, meta }` / `{ success: false, error }`.

| Méthode | URI | Action | Body / Query |
|---|---|---|---|
| GET | `/admin/users` | Liste paginée | query: `search`, `status` (active/suspended/all), `page`, `per_page` |
| GET | `/admin/users/{id}` | Détail d'un user | — |
| POST | `/admin/users/{id}/suspend` | Suspend (set suspended_at) | — |
| POST | `/admin/users/{id}/unsuspend` | Unsuspend (clear suspended_at) | — |
| POST | `/admin/users/{id}/reset-password` | Déclenche l'email de reset | — |
| DELETE | `/admin/users/{id}` | Soft-delete | — |
| GET | `/admin/settings` | Liste de tous les paramètres | — |
| PUT | `/admin/settings/{key}` | Met à jour une valeur | body: `{ value }` |
| GET | `/admin/stats/overview` *(P2)* | KPIs plateforme | — |
| GET | `/admin/audit` *(P2)* | Log des actions admin (paginé) | query: `action_type`, `admin_user_id`, `page`, `per_page` |

### 4.3 JWT enrichi

Aujourd'hui le JWT contient `{ sub: user_id, exp, iat }`. On ajoute `role` pour éviter une requête BDD à chaque check admin.

```json
{
  "sub": 42,
  "role": "ADMIN",
  "exp": 1745530200,
  "iat": 1745526600
}
```

`RequireAdminMiddleware` lit `role` depuis le payload du token. Si `!== 'ADMIN'` → 403 Forbidden.

**Subtilité** : quand un user est promu admin via l'env var `INITIAL_ADMIN_EMAIL`, il doit se reconnecter pour obtenir un nouveau token avec le role à jour. Acceptable pour un bootstrap one-shot. Pour les promotions futures (si un admin existe déjà et en crée d'autres), l'impact est le même : déconnexion/reconnexion requise.

---

## 5. Sécurité

### 5.1 Garde-fous obligatoires

| Règle | Enforcement |
|---|---|
| Un admin ne peut pas se suspendre lui-même | Check `user.id !== auth.user_id` dans le service |
| Un admin ne peut pas se supprimer lui-même | Idem |
| Un admin ne peut pas changer son propre role | Pas d'endpoint pour éditer `role` en v1 (promotion via env var uniquement) |
| Un user suspendu ne peut pas se login | Check dans `AuthService::login` : refuse si `suspended_at IS NOT NULL` |
| Rate limit sur `/api/admin/*` | *(P2)* middleware dédié, ex. 100 req/min |

### 5.2 Bootstrap du 1er admin

Mécanique détaillée :

1. Env var `INITIAL_ADMIN_EMAIL` posée sur le service `api` (test + prod)
2. Au boot du container api (via `entrypoint.sh`), un script `api/cli/bootstrap-admin.php` est exécuté :
   - Si `INITIAL_ADMIN_EMAIL` n'est pas définie → skip silencieux
   - Si l'user n'existe pas en BDD → log warning, skip
   - Si l'user existe et a déjà `role=ADMIN` → skip idempotent
   - Sinon → `UPDATE users SET role='ADMIN' WHERE email=?` + log
3. Le script ne fait pas échouer le boot en cas d'erreur (seulement log)

Ainsi :
- Premier deploy : tu t'inscris normalement, tu poses la var, tu redeploy une fois, tu es admin
- Deploys suivants : la var reste, le script ne fait rien

### 5.3 Masquage des données sensibles

Les responses admin doivent **jamais** retourner :
- `password_hash`
- Refresh tokens
- Stripe customer_id / subscription_id non-masqué
- `BROKER_ENCRYPTION_KEY` ou credentials broker déchiffrés

Les repos/services admin filtrent explicitement les colonnes sensibles.

---

## 6. Frontend — SPA admin

### 6.1 Stack technique

Identique au frontend user : Vue 3 + Vite + Tailwind + PrimeVue + vue-i18n + Pinia.

Structure `admin/` :
```
admin/
├── Dockerfile
├── vite.config.js
├── src/
│   ├── main.js
│   ├── router/       # routes protégées
│   ├── stores/       # auth, users, settings
│   ├── services/     # api/*
│   ├── views/        # LoginView, UsersView, SettingsView, OverviewView
│   ├── components/   # communs (DataTable wrappers, dialogs)
│   ├── locales/      # fr.json + en.json
│   └── constants/
```

### 6.2 Pages

| Route | Component | Description |
|---|---|---|
| `/login` | `LoginView` | Formulaire email/password, redirige vers `/users` si ok et admin |
| `/` → redirect `/users` | — | Landing par défaut |
| `/users` | `UsersView` | Liste + actions |
| `/users/:id` | `UserDetailView` | Détail + historique |
| `/settings` | `SettingsView` | Liste + édition des paramètres |
| `/overview` *(P2)* | `OverviewView` | KPIs plateforme |

Sidebar minimale : lien vers chaque page + bouton logout en bas.

### 6.3 Gestion de l'auth

- Même endpoint `/api/auth/login` que le SPA user
- Après login, le SPA admin vérifie `role === 'ADMIN'` dans le JWT décodé
- Si `role !== 'ADMIN'` → logout immédiat + message "Accès réservé aux administrateurs"
- Refresh token géré via cookie, même mécanique que le SPA user

### 6.4 Partage de code entre les 2 SPAs

**Choix pour v1 : pas de package partagé, duplication acceptée pour démarrer rapidement.**

Les utilitaires potentiellement dupliqués : client API (axios/fetch), helpers JWT, composants UI génériques. Volume estimé : ~200 LOC redondantes.

**Refacto futur** : extraire un package `shared/` dans une itération dédiée si le coût de maintenance devient visible. Pas maintenant.

---

## 7. Phasage

### Branche 1 : `feature/admin-bo-foundations-and-users` (v1.0, ~1 jour)

- Migrations 008 (roles + suspension)
- Backend : JWT enrichi, RequireAdminMiddleware, bootstrap script, routes /admin/users + actions
- SPA admin : setup Vite + auth + UsersView
- Dockerfile + doc déploiement Railway
- Garde-fous self-action
- Tests unit + intégration

### Branche 2 : `feature/admin-bo-platform-settings` (v1.0 bis, ~0.5 jour)

- Migration 009 (platform_settings)
- Backend : PlatformSettingsRepository, Service avec pattern fallback, routes /admin/settings
- Migration des 6 paramètres env-var-only listés en § 3.3
- SPA admin : SettingsView avec liste + édition typée (checkbox pour bool, input number pour int, etc.)
- Tests

### Branche 3 : `feature/admin-bo-hardening` (v1.1, ~0.5 jour, optionnel)

- Migration 010 (admin_actions)
- Wiring de l'audit sur chaque action admin
- Rate limiting middleware
- Overview plateforme (KPIs)
- SPA admin : AuditView + OverviewView

---

## 8. Scope exclu

- **Création manuelle d'users par un admin** : pas prévu. Les users s'inscrivent eux-mêmes. Besoin hypothétique à réévaluer plus tard.
- **Promotion d'un user en admin depuis le BO** : pas en v1 (risque de compromission par escalade). Possible en v1.1 avec audit strict. En attendant, l'env var `INITIAL_ADMIN_EMAIL` reste le seul chemin.
- **Modification de comptes de trading d'un autre user** : hors scope. L'admin voit les users mais ne touche pas à leurs données métier.
- **Impersonation** (se logger en tant qu'un autre user pour debug) : hors scope. Si besoin future, ticket dédié.
- **Gestion des "packs" / offres Stripe** : hors scope v1. À repenser quand on aura plusieurs tiers (aujourd'hui un seul prix).

---

## 9. Décisions retenues (2026-04-27)

| Question | Décision |
|---|---|
| Domaines Railway | `admin.2a-trading-tools.com` (prod) / `test-admin.2a-trading-tools.com` (test) |
| Bootstrap admin | Env var `INITIAL_ADMIN_EMAIL` auto-promotion au boot. Valeur initiale : `maxime.plrd@gmail.com` |
| Découpage branches | Une seule branche `feature/admin-bo-v1` regroupant Foundations + Users + Settings (Branches 1 + 2 du § 7) |
| Hardening (audit log + rate limit) | Reporté en v1.1 (branche dédiée plus tard). La règle "user suspendu → login refusé" reste en v1 (essentielle au fonctionnement de la suspension). |
| i18n | FR + EN dès le début, cohérent avec le frontend user existant |

---

## 10. Références

- Spec initiale : `docs/specs/trading-journal-specs-v5.md`
- Pattern déploiement Railway (service séparé) : `docs/31-broker-auto-sync.md`
- Convention folder/service Railway : `scheduler/README.md`
