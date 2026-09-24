# 112 — Le quota de renouvellement de session, recalibré

## Fonctionnalités

### Le défaut

Le 2026-09-21, impossible de rester connecté au journal de production. Le login
répondait bien (8 connexions en 200 en cinq minutes), mais chaque rechargement de
page ramenait à l'écran de connexion, sans aucun message.

Les logs HTTP de l'edge Railway ont donné la séquence exacte :

| Heure (UTC) | Ce qui se passe |
|---|---|
| 08:36:37 → 08:39:22 | 10 chargements de page, journal et back-office confondus, chacun avec un appel `/auth/refresh` en 200 |
| 08:39:23 | 11e appel → `429 TOO_MANY_REQUESTS` |
| 08:39 → 08:45 | Logins en 200, mais chaque F5 relance un refresh → 429 → l'application se croit sans session → retour au login |

Deux défauts se cumulaient :

1. **Un quota calibré pour un autre monde.** `/auth/refresh` était limité à
   10 appels par 15 minutes et par IP. Tant que l'API ne voyait que les adresses
   internes de Railway, ces 10 appels étaient en réalité ~210, partagés par tout
   le monde. Depuis l'étape [96](96-adresse-ip-reelle-du-visiteur.md), ils sont
   vraiment individuels, et la valeur n'a pas été revue. Or **chaque chargement
   complet de page** appelle `/auth/refresh`, journal comme back-office, sur le
   **même compteur**. Dix F5 dans un quart d'heure suffisaient à se faire
   éjecter, et tous les utilisateurs derrière une même adresse (bureau, box
   partagée, opérateur mobile) se partageaient ces dix appels.
2. **Un échec muet.** Au démarrage, `initSession` traitait tout échec du
   renouvellement comme « pas de session » : retour au login, sans un mot. Un
   quota dépassé ressemblait donc exactement à un login cassé.

### Ce qui change

- **Le quota de `/auth/refresh` passe de 10 à 60 appels par 15 minutes et par IP.**
- **Quand le renouvellement est refusé pour cause de quota**, la page de login le
  dit : « Trop de tentatives, veuillez réessayer plus tard » (clé
  `error.rate_limit_exceeded`, renvoyée telle quelle par l'API). Le journal
  l'affiche dans l'encart d'erreur du formulaire, le back-office en toast.
- Le message disparaît dès qu'une session est obtenue (login, inscription, SSO),
  et il est remplacé par le résultat de la tentative suivante.
- Un échec « normal » (pas de cookie, jeton révoqué ou expiré) reste silencieux,
  comme avant : c'est le cas courant d'un visiteur non connecté.

### Si quelqu'un est bloqué

La fenêtre est **fixe**, pas glissante : elle démarre au premier appel et dure
15 minutes, quel que soit le nombre d'appels refusés entre-temps. Recharger en
boucle ne prolonge pas le blocage. Le compteur est visible en base :

```sql
SELECT ip, endpoint, attempts, window_start
FROM rate_limits
WHERE endpoint = '/auth/refresh'
ORDER BY window_start DESC;
```

## Choix d'implémentation

### Pourquoi 60, et pourquoi on peut relever sans risque

Le refresh token est un cookie de **256 bits aléatoires** (`bin2hex(random_bytes(32))`),
`HttpOnly` : il n'y a rien à deviner par force brute. La limite de `/auth/refresh`
ne protège que la **charge** du serveur, pas les comptes. Le rempart contre le
bourrage de mots de passe, c'est `/auth/login` (10 / 15 min) et le verrouillage
de compte après 5 échecs, auxquels ce correctif ne touche pas.

60 laisse de la marge à un usage intensif (plusieurs onglets, journal et
back-office ouverts en même temps, rechargements successifs), tout en gardant un
plafond. Le seuil reste **par IP** : une adresse très partagée peut toujours
l'atteindre. Limiter par session plutôt que par IP aurait levé cette limite,
mais au prix d'un changement de fonctionnement du middleware. Ce n'est pas le
choix retenu.

### Une erreur qui garde sa forme jusqu'au store

`refreshAccessToken()` levait une `Error('Refresh failed')` nue : le statut et le
code de l'API étaient perdus. Il lève désormais la même erreur que les autres
appels (`status`, `code`, `messageKey`), construite depuis l'enveloppe de l'API.
Le corps est lu avec `response.json().catch(() => null)`, donc une réponse HTML
de l'edge (panne, 502) retombe sur `error.internal` sans planter.

`initSession` distingue alors le cas par le **code** de l'API
(`TOO_MANY_REQUESTS`), pas par le statut HTTP, comme `recoverExpiredToken` le
fait déjà pour `TOKEN_EXPIRED`.

### `restoreErrorKey`, un état dédié

Le store `auth` avait déjà un `error`, mais `login()` et `register()` l'écrivent
à chaque échec. S'en servir aurait affiché sur la page de login l'erreur d'une
inscription ratée, par exemple. `restoreErrorKey` ne porte que la raison pour
laquelle la restauration de session a échoué. Il est remis à zéro partout où une
session est obtenue : `setAuthData()` côté journal,
`applyTokenAndDecodeRole()` côté back-office.

La page de login le lit **à son montage**. Le routeur attend `initSession` avant
toute navigation, donc la valeur est déjà là quand la page s'affiche.

En cours de session, le chemin est le même. Un jeton d'accès expiré déclenche un
refresh. S'il est refusé, `recoverExpiredToken` recharge `/login`, qui relance
`initSession`, qui tombe sur le même 429 et affiche le message.

### Le back-office

Même chemin de démarrage que le journal, même compteur côté API : il est corrigé
de la même façon. Sa traduction `error.rate_limit_exceeded` manquait. Un login
admin rejeté pour quota affichait donc la clé brute ; c'est corrigé au passage.

## Couverture des tests

| Fichier | Test | Scénario |
|---|---|---|
| `api/tests/Unit/Config/SecurityConfigTest.php` | `testRefreshAllowsSixtyCallsPerQuarterHour` | Le quota refresh vaut 60 / 900 s |
| `frontend/src/__tests__/api.spec.js` | `refreshAccessToken surfaces the status, code and message_key of a failure` | Un 429 remonte avec `status`, `code`, `messageKey`, et le jeton est effacé |
| `frontend/src/__tests__/auth-store.spec.js` | `initSession keeps the rate-limit message for the login page` | 429 au démarrage → `restoreErrorKey` posé, utilisateur déconnecté |
| | `initSession has nothing to say when there is simply no session` | 401 `REFRESH_TOKEN_INVALID` → aucun message |
| | `a successful login drops the rate-limit message` | Le login efface le message |
| `frontend/src/__tests__/login-view.spec.js` | `says why the session could not be restored` | La page affiche le message au montage |
| | `shows no message on a plain visit` | Visite normale : rien |
| | `replaces the restore message with the outcome of the next attempt` | Un login raté remplace le message par sa propre erreur |
| `admin/src/__tests__/api.spec.js` | `refreshAccessToken surfaces the status, code and message_key of a failure` | Idem journal |
| `admin/src/__tests__/auth-store.spec.js` | 3 tests `initSession` / login | Idem journal |
| `admin/src/__tests__/login-view.spec.js` | `says why the session could not be restored` | Toast d'erreur au montage |
| | `stays silent on a plain visit` | Aucun toast |

Suites complètes : **2011 tests PHPUnit**, **623 tests Vitest** côté journal, **28** côté back-office.
