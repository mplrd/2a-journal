# Étape 93 — La course des deux workers sur le refresh token partagé

## Résumé

Deux synchros du même utilisateur démarrées dans la même seconde consommaient **le même refresh token cTrader**. Le perdant levait `cTrader token refresh failed`. La réservation temporelle posée aux migrations 036/037 devient une **réservation atomique** (migration **040**, colonne `broker_credentials.refreshing_since`) : un seul gagnant renouvelle, l'autre poursuit avec son access token.

## Le défaut, tel qu'observé

En environnement de test, chaque passe de synchro laissait exactement **trois lignes** dans `sync_logs` : un `FAILED` puis deux `SUCCESS`.

```
19412  conn 19  SUCCESS  08:22:02
19411  conn 19  FAILED   08:22:02   cTrader token refresh failed
19410  conn 20  SUCCESS  08:22:02
```

Et **la connexion qui échoue alterne** — la 19 à 04:20, la 20 à 06:21, la 19 à 08:22. C'est la signature d'une course, pas d'une connexion défaillante.

### Pourquoi

Depuis la [doc 91](91-broker-shared-credentials.md), `refresh_token` est déclaré `shared` : les connexions cTrader d'un même utilisateur partagent le même. Or **cTrader fait tourner ce token à chaque usage**.

Le garde-fou existant, `sharedRenewedWithin(user, provider, 300s)`, lit `refreshed_at` — c'est une **lecture d'horloge**. Elle ne départage que des synchros **décalées** :

1. Les deux workers démarrent dans la même seconde et demandent chacun « quelqu'un a-t-il renouvelé récemment ? ». **Non** pour les deux : aucun n'a encore fini.
2. Les deux appellent cTrader. Le token tourne. Le second présente un token déjà consommé → exception.
3. Le perdant relâche sa connexion ; l'autre worker la reprend, voit `refreshed_at` désormais frais, **saute le renouvellement** et réussit.

D'où le motif à trois lignes. C'est un TOCTOU classique : le contrôle et l'usage ne sont pas dans la même opération atomique.

### Ce que ça coûtait

Les données restaient justes — le système converge à l'étape 3. Le coût était ailleurs : une ligne `FAILED` parasite à chaque passe, un `incrementFailures` à chaque passe, et un appel de renouvellement gaspillé. Le disjoncteur ne se déclenchait pas **parce que** le succès suivait l'échec et remettait le compteur à zéro — un ordre que rien ne garantit.

## La correction

`refreshing_since` transforme la lecture d'horloge en réservation. C'est le motif déjà employé pour `broker_connections.syncing_since` (migration 035) : **la clause `WHERE` est le verrou**, un `UPDATE` conditionnel, donc deux appelants concurrents ne peuvent pas tous les deux recevoir `true`.

```
BrokerCredentialRepository::claimRefresh(userId, provider, staleAfterSeconds): bool
BrokerCredentialRepository::releaseRefresh(userId, provider): void
```

Dans `BrokerSyncService`, le renouvellement n'a lieu que si la réservation est obtenue, et elle est **rendue dans un `finally`** — un `client_secret` réellement invalide ne doit pas empêcher les autres connexions de renouveler jusqu'à péremption de la réservation.

### Pourquoi le perdant n'a pas besoin d'attendre

Un renouvellement **ne fait tourner que le refresh token**. L'access token que le perdant détient reste valide jusqu'à sa propre expiration. Il poursuit donc sa synchronisation normalement, sans attente ni relecture. C'est ce qui permet de traiter la course sans verrou bloquant pendant un appel réseau.

### Le cas « rien n'est partagé »

Ouinex et BingX ne stockent aucune ligne partagée, pas plus que la toute première connexion d'un utilisateur. `claimSharedRefresh()` renvoie alors **`true`** : il n'y a aucun token que deux synchros pourraient se disputer, et réserver ne doit jamais devenir une raison de ne pas renouveler — ce serait troquer une course contre une expiration silencieuse.

### Durées

| Constante | Valeur | Raison |
|---|---|---|
| `REFRESH_CLAIM_TTL_SECONDS` | 60 s | Un renouvellement est un unique appel HTTP. Assez court pour qu'un worker tué ne gèle pas les renouvellements du provider plus d'un tour de cron. La réservation étant rendue explicitement, ce délai n'est qu'un filet |
| `SHARED_CREDENTIAL_FRESHNESS_SECONDS` | 300 s | Inchangé. Toujours utile : il évite la réservation elle-même quand un renouvellement vient d'aboutir |

## Migration 040

Additive, une colonne nullable `refreshing_since DATETIME NULL` sur `broker_credentials`. `NULL` = aucune réservation en cours. Jouée au démarrage. `database/schema.sql` a été aligné dans la foulée.

## Tests

| Fichier | Portée |
|---|---|
| `tests/Integration/Broker/BrokerSharedCredentialsTest.php` | 5 tests : un seul gagnant sur deux réservations concurrentes, restitution, reprise d'une réservation périmée, cloisonnement par utilisateur, et un provider sans ligne partagée qui gagne toujours |
| `tests/Unit/Services/Broker/BrokerSyncServiceTest.php` | 4 tests : le perdant ne renouvelle pas **et réussit quand même**, le gagnant renouvelle et rend la réservation, la restitution survit à une exception, et l'absence de ligne partagée ne réserve rien |

Le test de cloisonnement crée une **vraie** ligne cTrader pour le second utilisateur : sans elle, la réservation lui serait accordée pour la raison sans rapport qu'il n'y a rien à réserver, et le test passerait sans rien prouver.

Suites complètes : **1093 unitaires**, **743 intégration**.

## Limite connue

Le perdant poursuit avec l'access token qu'il a lu **avant** le renouvellement du gagnant. Si cet access token était déjà expiré, sa synchronisation échoue — et il faut attendre la passe suivante, où il lira le token renouvelé sur la ligne partagée.

C'est un cas étroit : il suppose un access token expiré **et** deux workers démarrés dans la même seconde. Le symptôme serait le même qu'aujourd'hui (un `FAILED` sur une passe) mais bien plus rare, et il se résout tout seul au tour suivant. Faire attendre le perdant pour relire les identifiants coûterait plus cher que ce que ça rapporte.

## Ce qu'on doit voir après déploiement

Une passe de synchro doit laisser **deux lignes `SUCCESS` et aucune `FAILED`** dans `sync_logs`, et `consecutive_failures` doit rester à 0 sur les deux connexions. Bonus attendu : **un seul appel de renouvellement par passe** au lieu de deux, ce qui va dans le sens du budget de requêtes de l'évolution #22.

Si un `FAILED` subsiste, la cause est désormais lisible dans les logs du service `scheduler` avec sa trace complète — voir [92](92-journalisation-erreurs.md).
