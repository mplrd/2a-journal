# Étape 34 — SSO cross-SPA via code à usage unique

## Résumé

Quand un user authentifié sur le SPA principal clique le lien "Aller à l'admin", il était jusqu'ici renvoyé sur la page de login du SPA admin et devait re-saisir ses identifiants. Le SSO supprime ce friction :

1. Le SPA user demande un **code à usage unique** à l'API (`/auth/sso/code`)
2. Il ouvre le SPA admin avec `?code=xxx` dans l'URL
3. Le SPA admin échange le code contre des tokens (`/auth/sso/exchange`) et bypass la page de login

Conçu pour ne **pas** affaiblir la sécurité : codes hashés en DB, TTL 30s, single-use, suspended check préservé.

## Architecture

```
SPA User                    API                    SPA Admin
   │                          │                       │
   │ click "Aller à l'admin"  │                       │
   │ POST /auth/sso/code     →│                       │
   │←─ {code: "ab12...", expires_in: 30}              │
   │                          │                       │
   │ window.open(admin?code=xxx, _blank)              │
   │─────────────────────────────────────────────────→│
   │                          │← onMounted: ?code     │
   │                          │  POST /auth/sso/exchange
   │                          │  → access_token + refresh cookie
   │                          │  → /users
```

Les 2 SPAs gardent des sessions JWT **distinctes** (cookies différents par domaine, états mémoire séparés). Le SSO ne fait que franchir le saut initial.

## Décisions

- **Code à usage unique > token partagé** : ne pas transmettre le JWT du SPA user dans une URL (référent leaks, historique navigateur). Le code est un secret à courte durée de vie, sans valeur intrinsèque hors d'un échange.
- **TTL 30s** : large enough pour le round-trip click → load admin → exchange (~5s typique en local), assez court pour que tout code fuité soit déjà mort.
- **Hash SHA-256 en DB** : un dump de la table `sso_codes` ne donne aucun ticket de session réutilisable.
- **Single-use** : `used_at` est setté avant l'émission des tokens pour bloquer une race de double-redemption.
- **Suspended check préservé** : l'échange passe par `AuthService::issueSessionForUser()` qui refuse un user suspendu, exactement comme le login mot de passe.
- **Endpoint d'émission ouvert à tout authentifié** (pas seulement les admins) : la garde admin se fait dans le SPA admin (`role !== 'ADMIN'` → logout immédiat). Plus simple côté API, et un non-admin qui exchange un code ne gagne rien (l'endpoint admin reste inaccessible).
- **Cleanup opportuniste** : un `DELETE WHERE expires_at < NOW() OR used_at IS NOT NULL` au début de chaque exchange. Pas besoin de cron pour une table qui ne grossit que de quelques lignes par jour.

## Choix d'implémentation

### Backend

**Migration `011_sso_codes.sql`** — table dédiée :

```sql
CREATE TABLE sso_codes (
    code_hash CHAR(64) UNIQUE,         -- SHA-256(code)
    user_id INT,
    expires_at DATETIME,                -- NOW() + 30s
    used_at DATETIME NULL                -- set on first redemption
);
```

`expires_at` est calculé en SQL (`DATE_ADD(NOW(), INTERVAL :ttl SECOND)`) pour éviter un mismatch timezone PHP↔MySQL — typique en docker (PHP en UTC, MySQL en system tz).

**`SsoCodeRepository`** — 3 méthodes :
- `create($hash, $userId, $ttlSeconds)` : insert avec `expires_at = DATE_ADD(NOW(), INTERVAL :ttl SECOND)`
- `redeem($hash)` : **UPDATE atomique** `SET used_at = NOW() WHERE code_hash = :hash AND used_at IS NULL AND expires_at > NOW()` + check rowCount. Le UPDATE lui-même est la garde — pas de fenêtre de course entre lookup et mark-used.
- `deleteExpiredOrUsed()` : cleanup opportuniste

**`SsoService`** — orchestre :
- `issueCode(userId)` : 32 random bytes hex → code, hash SHA-256, persist, return plaintext
- `exchange(code)` : validate, mark used, **délègue** `AuthService::issueSessionForUser($userId)` pour la création de session

**`AuthService::issueSessionForUser($userId)`** — nouveau helper public extrait de `login()`. Vérifie le user existe + non suspendu, reset login attempts, touch last_login, génère access+refresh tokens, retourne le payload enrichi (`public_settings`). Réutilisé par `login()` et par `exchange()`, pas de duplication.

**Routes** :
- `POST /auth/sso/code` (auth requise + rate-limit `sso_issue` = 30/IP/5min) → `ssoIssueCode`
- `POST /auth/sso/exchange` (no auth + rate-limit `sso_exchange` = 30/IP/5min) → `ssoExchange`

Le rate-limit sur `/code` empêche un user authentifié de spam-créer des codes pour gonfler la table. Sur `/exchange`, c'est de la défense en profondeur — le brute-force d'un code (256 bits d'entropie, 30s window) est mathématiquement infaisable, mais une limite par IP coupe court à toute tentative bruyante.

### Frontend user SPA

`AppLayout.vue` — le bouton "Aller à l'admin" est maintenant un `<button>` (au lieu de `<a href>`) qui :
1. Appelle `authService.ssoIssueCode()`
2. Construit l'URL `${ADMIN_URL}?code=xxx` via `URL` API (URL-safe)
3. `window.open(url, '_blank', 'noopener,noreferrer')`

En cas d'erreur (réseau, 401, etc.), un toast s'affiche.

### Frontend admin SPA

`LoginView.vue` — `onMounted` lit `route.query.code`. Si présent :
1. Affiche un loader (`ssoLoading = true`) pour masquer le formulaire
2. **Strip le code de l'URL immédiatement** (`router.replace`) pour qu'un reload ne tente pas de le re-utiliser
3. Appelle `auth.loginWithSsoCode(code)` qui exchange + applique les tokens + check role ADMIN
4. Redirige vers `/users` en cas de succès

`auth` store : nouvelle méthode `loginWithSsoCode` qui suit le même pattern que `login` (admin-gate après réception du token).

## Variables d'environnement

Aucune nouvelle variable. La feature est implicitement activée dès que les 2 SPAs sont déployés. La présence du lien dans le SPA user reste conditionnée par `VITE_ADMIN_URL` (déjà existante depuis l'étape 32-bis).

## Couverture des tests

| Test | Type | Scenario | Statut |
|---|---|---|---|
| `testIssueCodeReturnsRandomString` | Integration | POST /auth/sso/code → code hex 64-chars + expires_in=30 | ✅ |
| `testIssueCodePersistsHashOnly` | Integration | DB stocke le hash SHA-256, jamais le plaintext | ✅ |
| `testIssueCodeRequiresAuth` | Integration | Sans auth → 401 | ✅ |
| `testExchangeReturnsTokens` | Integration | POST /auth/sso/exchange → access_token + refresh cookie + user | ✅ |
| `testExchangeMarksCodeUsed` | Integration | Après exchange, used_at est non-null en DB | ✅ |
| `testExchangeRejectsReplay` | Integration | Re-exchange du même code → 401 sso_code_invalid | ✅ |
| `testExchangeRejectsExpiredCode` | Integration | Code expiré (forced) → 401 | ✅ |
| `testExchangeRejectsUnknownCode` | Integration | Code inconnu → 401 | ✅ |
| `testExchangeRequiresCode` | Integration | Body sans code → 422 | ✅ |
| `testExchangeRefusesSuspendedUser` | Integration | Code émis avant suspension → exchange 403 suspended | ✅ |

Total : 10 tests d'intégration backend. Pas de tests unitaires séparés (Service + Repository entièrement couverts par les flux d'intégration).

## Notes sécurité

- **Plaintext du code n'existe nulle part en persistance** : ni en DB, ni en log API. Il vit dans la HTTP response du SPA user → URL → HTTP request du SPA admin → mémoire navigateur jusqu'au strip de l'URL.
- **URL stripping** côté admin SPA évite que le code reste dans `history.pushState` ou ne soit copié-collé par le user.
- **Référent header** : `noopener,noreferrer` est passé à `window.open`, le SPA admin n'envoie pas le `Referer` à des tiers même si le user clique des liens.
- **Suspended user** : un user suspendu après émission ne peut pas redeem son code (vérification dans `issueSessionForUser`).
- **Pas de role check à l'émission** : un user lambda peut générer un code et tenter de l'exchanger. Il obtiendra des tokens valides pour le SPA user, mais le SPA admin le déconnectera immédiatement (role !== ADMIN). L'endpoint `/admin/*` reste verrouillé par `RequireAdminMiddleware`.
- **Race condition** : `redeem()` fait un UPDATE atomique avec WHERE clause sur `used_at IS NULL AND expires_at > NOW()`. `rowCount === 1` ⇔ on a gagné la course. Une 2e requête concurrente sur le même code reçoit `rowCount === 0` → 401. Pas de fenêtre check-then-act entre lookup et mark-used.
- **Risque résiduel — code dans logs** : le plaintext code transite via le query string `?code=xxx` sur le SPA admin. L'URL strip côté front (`router.replace`) le retire de l'historique navigateur dès l'exchange réussi, mais il peut apparaître dans (a) les logs nginx/apache du domaine admin (request line loggée avant exécution JS), (b) le `Referer` de toute sub-resource fetched avant le strip. Combiné au TTL 30s + single-use, l'attaque consisterait à lire les logs en temps-réel et exchanger en moins de 30s — peu probable mais à surveiller. Activer `log_format` minimal sur la route `/login` du domaine admin si le risque est jugé non-acceptable.
