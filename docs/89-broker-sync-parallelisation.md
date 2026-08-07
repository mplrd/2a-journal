# Étape 89 — Réservation atomique des synchronisations broker

> Cette doc couvre la refonte de l'ordonnancement des synchros broker.
> **Lot A — réservation atomique** est livré ; les lots B (parallélisation du
> cron) et C (bouton non bloquant) viendront s'ajouter ici.

## Le problème

Deux défauts distincts, la même cause : rien ne dit *qui* travaille sur une
connexion à un instant donné.

**1. Course entre la synchro manuelle et la synchro planifiée.**
`POST /broker/connections/{id}/sync` n'a jamais posé le moindre verrou. Le CLI
`cli/sync-brokers.php` pose bien un `flock`, mais il ne protège que d'un second
tour de cron dans le **même conteneur** — il ignore complètement le chemin HTTP.
Un clic pendant que le cron traite la même connexion lance donc deux runs
simultanés sur les mêmes deals. La déduplication d'import est faite par batch,
pas entre batches : deux runs concurrents peuvent insérer deux fois la même
position.

**2. Impossible de paralléliser.**
Le scheduler traite les connexions **une par une**, tous utilisateurs confondus.
Avec N utilisateurs, la durée d'un tour est la somme des durées de chaque
synchro : la plateforme devient une file d'attente. On ne peut pas y répartir le
travail sur plusieurs workers tant qu'aucun mécanisme n'empêche deux workers de
prendre la même connexion.

La réservation règle les deux : elle sérialise par connexion, quel que soit
l'appelant (HTTP, cron, worker), et c'est le prérequis du lot B.

## Le mécanisme

Une colonne `broker_connections.syncing_since` porte la réservation.

```sql
UPDATE broker_connections
SET syncing_since = UTC_TIMESTAMP()
WHERE id = :id
  AND (syncing_since IS NULL
       OR syncing_since < UTC_TIMESTAMP() - INTERVAL 900 SECOND)
```

**Le `WHERE` est le verrou.** Un seul UPDATE conditionnel : deux appelants qui
courent sur la même connexion ne peuvent pas revenir tous les deux avec `true`,
InnoDB sérialise l'écriture sur la ligne. Pas de `SELECT` puis `UPDATE`, qui
laisserait une fenêtre entre la lecture et l'écriture — exactement le bug qu'on
répare.

`rowCount() === 1` répond « j'ai la réservation ». PDO n'étant pas configuré avec
`MYSQL_ATTR_FOUND_ROWS`, `rowCount()` compte les lignes **réellement modifiées**,
pas les lignes trouvées : le résultat est bien celui de la prise du verrou.

### Expiration

`SYNC_CLAIM_TTL_SECONDS = 900` (15 min). Au-delà, une réservation est considérée
abandonnée et reprise par l'appelant suivant : un worker tué en plein vol ne doit
pas verrouiller sa connexion à vie. La fenêtre est volontairement large — la
doubler serait pire que la retarder, donc on ne reprend jamais une synchro
seulement lente.

### Libération

`releaseSync()` est appelé dans un **`finally`**, jamais en fin de chemin
nominal. Une exception qui laisserait la réservation en place bloquerait toute
synchro de ce compte pendant les 15 minutes du TTL.

### Pourquoi DATETIME et pas TIMESTAMP

Une colonne `TIMESTAMP` est convertie depuis/vers le fuseau de session MySQL à
chaque lecture/écriture, alors que `UTC_TIMESTAMP()` rend de l'UTC : sur un
serveur dont la session n'est pas en UTC, les deux côtés de la comparaison ne
parlent pas de la même heure. `DATETIME` est stocké verbatim — la valeur écrite
par `UTC_TIMESTAMP()` est comparée telle quelle à `UTC_TIMESTAMP()`.

## Statut `SKIPPED`

Une synchro refusée n'est **pas un échec** :

- aucune ligne `sync_logs` n'est créée (la réservation est prise avant),
- `consecutive_failures` n'est pas incrémenté — le disjoncteur ne doit pas
  déconnecter un compte simplement parce qu'il était occupé,
- `last_sync_at` / `last_sync_status` ne sont pas touchés : l'état appartient au
  run qui tient la réservation.

`SyncStatus::SKIPPED` est une **issue de run**, jamais persistée. L'ENUM SQL de
`sync_logs.status` n'a donc pas été étendu ; c'est signalé dans le docblock de
l'enum pour qui voudrait un jour l'écrire en base.

Le scheduler compte ces cas à part dans son résumé de tour :
`already_syncing`, distinct de `success`, `failed` et `deferred` (ban de
fréquence broker).

Côté UI, un clic refusé affiche un toast **info** « Synchronisation déjà en
cours » plutôt qu'un « Succès — 0 position importée », qui se lirait comme « le
broker n'avait rien de neuf » : deux situations très différentes pour
l'utilisateur.

## Fichiers touchés

| Fichier | Rôle |
|---|---|
| `api/database/migrations/035_broker_sync_claim.sql` | `syncing_since` + `sync_requested_at` + index. Additive, idempotent. |
| `api/database/schema.sql` | Reflet des nouvelles colonnes. |
| `api/src/Repositories/BrokerConnectionRepository.php` | `claimForSync()` / `releaseSync()`. |
| `api/src/Services/Broker/BrokerSyncService.php` | Prise de la réservation, libération en `finally`, résultat `SKIPPED`. |
| `api/src/Services/Broker/BrokerSyncSchedulerService.php` | Compteur `already_syncing`. |
| `api/src/Enums/SyncStatus.php` | Cas `SKIPPED`. |
| `frontend/src/components/broker/BrokerConnectionPanel.vue` | Toast info sur synchro refusée. |
| `frontend/src/locales/{fr,en}.json` | `broker.sync_already_running{,_detail}`. |

`sync_requested_at` est créée dès cette migration mais n'est pas encore
utilisée : elle porte le lot C (bouton de synchro non bloquant). La créer
maintenant évite une seconde migration sur la même table.

## Tests

**Backend** — `BrokerConnectionRepositoryTest` (intégration, vraie base) :
réservation sur connexion libre, refus quand déjà prise, reprise d'une
réservation périmée, rafraîchissement du timestamp à la reprise, libération,
portée limitée à la connexion visée, connexion inexistante.

`BrokerSyncServiceTest` : la réservation est prise avant tout appel broker,
un refus ne crée aucun log et ne touche pas la connexion, la libération a lieu
aussi bien sur succès que sur exception.

`BrokerSyncSchedulerServiceTest` : une connexion déjà réservée est comptée à part
et ne touche ni `resetFailures` ni `incrementFailures`.

**Frontend** — `BrokerConnectionPanel.spec.js` : un `SKIPPED` produit un toast
info sans récap d'import ; une synchro réelle garde son toast de succès.

## Limite connue

La réservation sérialise les synchros **d'une même connexion**. Elle ne réduit
pas la durée d'un tour de cron, qui reste séquentiel — c'est l'objet du lot B.
