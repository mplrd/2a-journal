# 87 — Renouvellement du token sur les envois de fichiers (upload / téléchargement)

**Ticket support** : #34 — « Temps avant déconnexion »
**Branche** : `fix/upload-token-refresh`

## Contexte

Un utilisateur signale être « déconnecté au bout de 5 minutes », notamment en
rédigeant une demande de support. Les logs de production du 1er août confirment
l'incident :

```
08:08:24  POST /support/tickets/33/messages  401   (2924 octets envoyés, rejetés)
08:08:46  GET /auth/me + /billing/status + /accounts + /support/tickets
08:08:58  POST /support/tickets/33/messages  201
```

L'utilisateur n'a jamais été déconnecté : sa session était valide, le rechargement
l'a remis dedans en 22 secondes sans mot de passe. Seul cet envoi a échoué.

**Cause** : le token d'accès vit 15 minutes et n'est renouvelé qu'en réaction à un
401. `request()` gérait ce cas (rafraîchir puis rejouer la requête), mais
`upload()` et `getBlob()` ne le géraient pas et remontaient le 401 tel quel. Tout
formulaire avec pièce jointe échouait donc dès que le token expirait pendant la
saisie — et le texte était perdu.

Le délai perçu de 5 minutes s'explique par le fait que le compte à rebours des 15
minutes démarre à l'émission du token, pas à l'ouverture du formulaire.

## Fonctionnalités

- Un envoi de fichier ou de formulaire multipart dont le token a expiré pendant la
  saisie **n'échoue plus** : le token est renouvelé silencieusement et la requête
  est rejouée. L'utilisateur ne voit rien.
- Idem pour le téléchargement d'une pièce jointe (`getBlob`).
- Plusieurs appels qui expirent en même temps ne déclenchent **qu'un seul**
  renouvellement, au lieu de se concurrencer.

Surfaces concernées, toutes corrigées d'un coup puisqu'elles passent par les mêmes
fonctions :

| Service | Appel | Usage |
|---|---|---|
| `support.js:46` | `upload` | Création d'un ticket |
| `support.js:53` | `upload` | Réponse à un ticket |
| `support.js:58` | `getBlob` | Téléchargement d'une pièce jointe |
| `notebook.js:52,67` | `upload` | Note et pièces jointes |
| `notebook.js:76` | `getBlob` | Téléchargement d'une pièce jointe |
| `imports.js:11,29,49` | `upload` | Import de trades (3 étapes) |
| `auth.js:31` | `upload` | Photo de profil |

L'import de trades était le plus exposé : trois étapes successives, avec du temps
de lecture entre chacune.

## Choix d'implémentation

### Mutualisation plutôt que duplication

Trois briques extraites dans `services/api.js`, partagées par `request()`,
`upload()` et `getBlob()` :

- `recoverExpiredToken(errorData)` — renvoie un token frais quand l'appelant doit
  rejouer sa requête, `null` quand le 401 n'est pas récupérable et doit être
  remonté tel quel. En cas d'échec du renouvellement : purge de la session et
  redirection vers `/login`.
- `readJson(response)` — lecture défensive du corps de réponse. Un proxy qui
  refuse un envoi trop gros (413) répond en HTML, pas avec notre enveloppe JSON ;
  un `response.json()` direct planterait et remonterait une « erreur interne »
  trompeuse.
- `buildError(status, data, fallbackKey)` — construction homogène de l'erreur
  (`status`, `code`, `field`, `messageKey`).

### Seul `TOKEN_EXPIRED` déclenche un renouvellement

Un 401 `TOKEN_MISSING` ou `TOKEN_INVALID` est remonté sans tentative de
renouvellement : il ne s'agit pas d'une expiration, réessayer ne servirait à rien
et masquerait la vraie cause.

### Verrou anti-concurrence sur le renouvellement

Le serveur fait tourner le refresh token : `AuthService::refresh()` supprime
l'ancien avant d'en créer un nouveau, sans délai de grâce. Deux renouvellements
concurrents laissent donc le perdant avec un token révoqué — c'est-à-dire une
déconnexion. `refreshAccessToken()` partage désormais sa promesse en cours entre
tous les appelants, et ne la libère qu'une fois résolue.

### Le corps de réponse n'est lu qu'une fois

Un corps HTTP ne se consomme qu'une seule fois. Dans `getBlob()`, un 401 non
récupérable doit réutiliser ce qui a déjà été analysé : d'où la sentinelle
`errorBody` laissée à `undefined` tant qu'aucune lecture n'a eu lieu, plutôt qu'un
`null` qui serait ambigu avec un corps vide.

### Rejeu du `FormData`

Un objet `FormData` est réutilisable : `fetch` le re-sérialise à chaque appel. Le
rejeu n'a donc pas besoin de reconstruire le formulaire.

## Couverture des tests

`frontend/src/__tests__/api.spec.js` — 21 tests, dont 7 ajoutés.

| Test | Scénario | Statut |
|---|---|---|
| `upload refreshes the token and replays the request` | 401 TOKEN_EXPIRED → renouvellement → rejeu avec le nouveau token, résultat rendu à l'appelant | ✅ |
| `upload leaves a 401 that is not TOKEN_EXPIRED untouched` | 401 TOKEN_INVALID → erreur remontée, aucun appel à `/auth/refresh` | ✅ |
| `upload still reports an oversized body as upload.error.too_large` | 413 en HTML → `upload.error.too_large` (non-régression) | ✅ |
| `upload clears the session and redirects to /login when the refresh fails` | Renouvellement refusé → session purgée, redirection `/login` | ✅ |
| `getBlob refreshes the token and replays the request` | 401 TOKEN_EXPIRED → renouvellement → blob rendu | ✅ |
| `getBlob leaves a 404 untouched` | 404 → erreur remontée, aucun renouvellement | ✅ |
| `coalesces concurrent refreshes into a single /auth/refresh call` | 3 appels simultanés expirés → **un seul** `/auth/refresh` | ✅ |

Tests préexistants conservés sans modification (token en mémoire, en-têtes,
`credentials`, erreurs `getBlob`, 401 sans double lecture du corps).

Suite frontend complète : **482 tests, 52 fichiers, tous verts**. Les 10
« Unhandled Rejection » du rapport sont antérieures à ce correctif (vérifié en
rejouant la suite sur la version précédente d'`api.js`) et proviennent de specs de
composants qui simulent partiellement le module `api`.

Backend non modifié.

## Points relevés hors périmètre

Constatés pendant le diagnostic, non traités ici. Consignés en détail dans
`docs/evolutions.md` :

- L'API ne lit jamais l'IP réelle du visiteur (`Request.php:85` utilise
  `REMOTE_ADDR`, sans `X-Forwarded-For` ni `CF-Connecting-IP`). Derrière Cloudflare
  puis Railway, PHP ne voit que `100.64.0.2` à `100.64.0.22`. Les quotas de
  `config/security.php` sont donc mutualisés entre tous les utilisateurs au lieu
  d'être individuels. Non déclenché à ce jour (0 réponse 429 sur 59 h de logs),
  mais un attaquant ne peut pas être limité individuellement.
- La production tourne sur `php -S`, le serveur web intégré de PHP
  (`api/Dockerfile`, `api/docker/entrypoint.sh`), déconseillé en production et
  limité à un seul worker. `api/docker/nginx.conf` n'est pas utilisé.
- Un scanner de vulnérabilités interroge l'API en continu (`/wp-content/...`,
  `/.env`, webshells `.php`). Uniquement des 404, mais une règle Cloudflare serait
  utile.
