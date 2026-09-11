# 111 — Suspendre ou supprimer un utilisateur coupe ses sessions ouvertes

## Fonctionnalités

### Le défaut

La suspension d'un utilisateur (back-office admin) n'était vérifiée qu'à **la
connexion** (`AuthService::login`) et à l'ouverture de session par SSO
(`issueSessionForUser`). Nulle part ailleurs :

- `AdminUserService::suspend()` posait `suspended_at` sans toucher aux refresh
  tokens ;
- `AuthService::refresh()` renouvelait le token **sans relire l'utilisateur** ;
- l'`AuthMiddleware` ne valide que la signature du JWT.

Un utilisateur **déjà connecté** au moment de sa suspension gardait donc l'accès :
son refresh token (7 jours) se renouvelait à chaque rotation, et sa session avec,
**sans limite**. C'est ce qui a été observé sur l'env de test le 2026-09-11 : un
utilisateur désactivé y était encore connecté, et il a fallu passer par le
back-office pour le déconnecter.

Même trou pour la **suppression par l'admin** : `AdminUserService::delete()` ne
faisait qu'un soft delete. La suppression par l'utilisateur lui-même
(`AuthService::deleteAccount`) révoquait déjà ses tokens.

### Ce qui change

- **Suspendre** ou **supprimer** un utilisateur depuis le back-office révoque
  immédiatement **tous ses refresh tokens**, sur tous ses appareils.
- **Le renouvellement refuse** un compte suspendu (`403 auth.error.suspended`,
  le même message qu'à la connexion) ou qui n'existe plus
  (`401 REFRESH_TOKEN_INVALID`), et révoque au passage toutes ses sessions.
- Ce second garde-fou couvre les **utilisateurs suspendus avant ce correctif**,
  dont les tokens n'avaient pas été révoqués : leur prochaine tentative de
  renouvellement les déconnecte.

Côté écrans, rien à changer : le journal et le back-office traitent déjà tout
échec de renouvellement comme une fin de session — token effacé, retour au login
(`api.js`, `initSession`). À la reconnexion, le compte suspendu voit le message
de suspension.

### Délai de coupure

**Au plus 15 minutes.** Les tokens d'accès déjà émis sont des JWT sans état,
valides jusqu'à leur expiration (`access_token_ttl` = 900 s) ; c'est au
renouvellement suivant que la session tombe. Une coupure instantanée demanderait
de relire l'utilisateur à chaque requête dans l'`AuthMiddleware` — une lecture
en base par appel d'API, écartée ici.

## Choix d'implémentation

### `AuthService::revokeSessions()`

Une méthode publique, `revokeSessions(int $userId)`, porte la révocation de
toutes les sessions d'un utilisateur. `logout()` l'utilise, et `AdminUserService`
l'appelle après `setSuspendedAt()` et après `softDelete()` — il dépendait déjà
d'`AuthService`, la logique des tokens reste donc à un seul endroit.

### Relire l'utilisateur au renouvellement

`refresh()` relit l'utilisateur (`findById`, qui exclut les soft-deleted) **avant
la rotation** :

| Cas | Réponse | Effet |
|---|---|---|
| utilisateur introuvable (supprimé) | `401 REFRESH_TOKEN_INVALID` | toutes ses sessions révoquées |
| utilisateur suspendu | `403 auth.error.suspended` | toutes ses sessions révoquées |
| utilisateur actif | `200`, nouveau token | rotation habituelle |

Révoquer **toutes** les sessions, et pas seulement le token présenté : un compte
suspendu n'a aucune session légitime, quel que soit l'appareil. Et un token
refusé ne peut pas être rejoué après une réactivation — l'utilisateur réactivé se
reconnecte normalement.

C'est une lecture en base de plus **par renouvellement** (toutes les 15 minutes
au plus par session), pas par requête.

## Couverture des tests

`api/tests/Unit/Services/AuthServiceTest.php`

| Test | Scénario | Statut |
|---|---|---|
| `testRefreshRefusesASuspendedUserAndRevokesTheirSessions` | token valide d'un utilisateur suspendu → `ForbiddenException auth.error.suspended`, `deleteAllByUserId`, aucun nouveau token | ✅ |
| `testRefreshRefusesAUserThatNoLongerExistsAndRevokesTheirSessions` | utilisateur introuvable → `UnauthorizedException refresh_token_invalid`, révocation, aucun nouveau token | ✅ |
| `testRevokeSessionsDeletesEveryRefreshTokenOfTheUser` | `revokeSessions()` supprime tous les tokens de l'utilisateur | ✅ |

`api/tests/Integration/Auth/AuthFlowTest.php`

| Test | Scénario | Statut |
|---|---|---|
| `testRefreshRefusesAUserSuspendedWhileSignedIn` | inscription, `suspended_at` posé en base sans révocation (cas d'avant le correctif) → refresh `403` ; réactivé, le même token reste refusé (`401`) | ✅ |
| `testRefreshRefusesAUserDeletedWhileSignedIn` | inscription, `deleted_at` posé en base → refresh `401` | ✅ |

`api/tests/Integration/Admin/AdminUserFlowTest.php`

| Test | Scénario | Statut |
|---|---|---|
| `testSuspendingAUserEndsTheirOpenSessions` | connexion de l'utilisateur, suspension par l'admin → plus aucun refresh token, refresh `401` | ✅ |
| `testDeletingAUserEndsTheirOpenSessions` | connexion de l'utilisateur, suppression par l'admin → plus aucun refresh token, refresh `401` | ✅ |

**Vérifié sur l'API locale** (curl, deux comptes jetables) : suspension par
l'admin → 2 tokens révoqués, refresh `401` ; token ayant survécu à une suspension
→ refresh `403 auth.error.suspended` et révocation ; suppression par l'admin →
refresh `401`.

**Non couvert** : la coupure instantanée pendant la durée de vie du token
d'accès (voir Délai de coupure), par choix.

## Origine

Repéré le 2026-09-11 en cherchant comment forcer la déconnexion d'un utilisateur
désactivé resté connecté sur l'env de test.
