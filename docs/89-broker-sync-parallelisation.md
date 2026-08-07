# Étape 89 — Parallélisation des synchronisations broker

> Cette doc couvre la refonte de l'ordonnancement des synchros broker.
> **Lot A — réservation atomique** et **lot B — parallélisation du cron** sont
> livrés ; le lot C (bouton non bloquant) viendra s'ajouter ici.
>
> - [Lot A — la réservation](#lot-a--la-réservation)
> - [Lot B — le superviseur et ses workers](#lot-b--le-superviseur-et-ses-workers)

## Lot A — la réservation

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

---

## Lot B — le superviseur et ses workers

### Le problème

Un tick de cron traitait **toutes** les connexions dues, tous utilisateurs
confondus, **une par une, dans un seul processus**. La durée d'un tour est donc
la somme des durées de chaque synchro. Une synchro cTrader ouvre cinq sessions
WebSocket successives : compter dix à trente secondes par connexion. À trois
utilisateurs la plateforme devient une file d'attente, et le tour finit par
déborder l'intervalle qu'il est censé respecter.

### La forme retenue

`cli/sync-brokers.php` porte désormais deux rôles :

```
supercronic (*/1 min)
    │
    ▼
php cli/sync-brokers.php                     ← SUPERVISEUR
    ├─ flock (un seul tour à la fois)
    ├─ compte les connexions dues
    ├─ 0 due → sort, sans lancer un seul processus
    └─ sinon : min(BROKER_SYNC_WORKERS, dues, 16) enfants
            │
            ├─ php cli/sync-brokers.php --worker --worker-index=0
            ├─ php cli/sync-brokers.php --worker --worker-index=1
            └─ …
                    │
                    └─ chacun = le scheduler d'avant, à l'identique
```

**`proc_open` et non `fork`** : l'image du scheduler n'embarque pas `ext-pcntl`
(elle a pdo_mysql, gd, zip, intl). Ce n'est pas qu'un contournement — un `fork`
duplique la socket PDO du parent, deux processus qui parlent au même socket MySQL
se corrompent mutuellement. Un vrai processus enfant ouvre sa propre connexion.

**Aucune répartition n'est calculée.** Chaque worker lit la même liste de
connexions dues et réserve ce qu'il peut : **c'est la réservation du lot A qui
fait le partage**. Une répartition statique laisserait les workers ayant tiré les
connexions rapides à ne rien faire, et devrait être recalculée dès qu'une
connexion est ajoutée, supprimée, ou déjà tenue par une synchro manuelle.

### Le décalage par worker

Tous les workers lisent la liste dans le même ordre. Sans rien de plus, ils se
jettent tous sur la connexion n°1 : les perdants brûlent une réservation refusée
sur chaque entrée avant d'atteindre du travail libre. Chaque worker fait donc
tourner la liste de son propre index (`worker_index`) avant de la parcourir.

Faire tourner n'est pas sauter : un worker qui trouve tout pris parcourt quand
même la liste entière. Le décalage optimise le cas courant sans changer la
garantie.

### Ce qui n'a pas été supprimé : le `flock`

Le plan initial était de le retirer. **Révisé après coup** : le `flock` ne
bridait pas le parallélisme, il empêchait deux *tours* de se superposer. Sans
lui, un tour qui déborde son intervalle empile un second pool d'enfants sur le
premier, puis un troisième — le nombre de processus PHP part en vrille
précisément quand la machine est déjà en difficulté.

Il est donc conservé, mais **pris par le seul superviseur**. Un worker qui le
prendrait bloquerait contre son propre parent : c'est la raison du `if
(!$isWorker)`, et la raison pour laquelle `scheduler/crontab` ne doit jamais
contenir de ligne `--worker`.

### Résumé de tour

```json
{"job":"broker-sync","status":"ok","role":"supervisor","skipped":false,"workers":4,
 "total_active":15,"processed":12,"success":11,"failed":1,"deferred":0,
 "already_syncing":8,"deactivated":0,"worker_errors":0,"interval_minutes":15,"duration_ms":4210}
```

Deux champs demandent une lecture attentive :

- **`processed`** est la taille de la liste due, **pas** la somme des vues des
  workers. Ils voient tous la même liste : sommer rapporterait six connexions
  comme douze.
- **`already_syncing`** mesure de la **contention**, pas des connexions : N-1
  workers sautent la même connexion réservée. C'est attendu par construction ;
  ce chiffre ne sert qu'à repérer un pool surdimensionné pour la charge.

`duration_ms` est le chiffre à surveiller : c'est lui qui alerte **avant** qu'un
tour déborde l'intervalle, plutôt qu'après. Un worker qui meurt ou n'imprime pas
de JSON exploitable est compté dans `worker_errors` et ne fait pas échouer le
tour.

**Contrepartie à connaître** : la sortie d'erreur d'un enfant ne coule plus
directement dans `/var/log/cron.log`, le pool la capture. Une ligne
`worker_failed` la réémet via `BrokerLogger`, tronquée à 2000 caractères — assez
pour la ligne d'erreur fatale et les premières frames, pas pour une trace
complète. Si un diagnostic l'exige, lancer un worker à la main dans le conteneur
(`php cli/sync-brokers.php --worker`) donne la sortie entière.

### Réglage

`BROKER_SYNC_WORKERS` (défaut **4**), exposé aussi en réglage admin
(`broker_sync_workers`, priorité BDD > env). Borné à `[1, 16]` et jamais
supérieur au nombre de connexions dues. Un `0` mal saisi retombe sur 1 : une
valeur absurde ne doit pas désactiver silencieusement l'auto-sync.

**Ce que ça ne change pas** : le nombre de requêtes envoyées à un broker sur la
journée. Le nombre de cycles par connexion est fixé par
`BROKER_SYNC_INTERVAL_MINUTES`, pas par le nombre de workers — seule leur
simultanéité change. Point à garder en tête vu les budgets de requêtes côté prop
firms (cf. l'entrée FTMO de `docs/specs/trading-journal-evolutions.md`).

### Fichiers touchés (lot B)

| Fichier | Rôle |
|---|---|
| `api/src/Services/Process/ProcessPoolInterface.php` | Contrat : lance N commandes, rend les N résultats dans l'ordre d'entrée. |
| `api/src/Services/Process/ProcOpenProcessPool.php` | Implémentation `proc_open`, pipes non bloquants drainés en boucle. |
| `api/src/Services/Broker/BrokerSyncSupervisorService.php` | Dimensionne le pool, agrège les rapports. |
| `api/src/Services/Broker/BrokerSyncSchedulerService.php` | Rotation de la liste due par `worker_index`. |
| `api/src/Repositories/BrokerConnectionRepository.php` | `countDueForAutoSync()`. |
| `api/cli/sync-brokers.php` | Deux rôles, `flock` réservé au superviseur, câblage lourd déplacé côté worker. |
| `api/src/Services/PlatformSettingsService.php` | Réglage `broker_sync_workers`. |
| `admin/src/locales/{fr,en}.json`, `api/.env.example`, `scheduler/{Dockerfile,crontab}` | Réglage + documentation d'exploitation. |

### Tests (lot B)

`ProcOpenProcessPoolTest` teste le vrai `proc_open` sur des one-liners PHP —
ordre des résultats indépendant de l'ordre d'arrivée, code de sortie non nul,
stderr, et surtout un enfant qui écrit 200 Ko : sans drainage des pipes en
boucle, il bloquerait indéfiniment et le pool ne rendrait jamais la main.

`BrokerSyncSupervisorServiceTest` (pool factice) : rien lancé quand le drapeau
est à false ou qu'il n'y a rien à faire, dimensionnement et bornes, index passé à
chaque enfant, agrégation, worker mort ou bavard.

`BrokerSyncSchedulerServiceTest` : la rotation démarre bien plus loin dans la
liste, boucle quand l'index dépasse la taille, et ne change rien sans index.

`SyncBrokersCliTest` : l'invocation par défaut est le superviseur, `--worker`
lance bien le scheduler complet — c'est le seul test qui monte le graphe de
dépendances lourd, puisque le superviseur ne le monte plus.

## Limite connue

La parallélisation raccourcit le tour ; elle ne rend pas la synchro **manuelle**
non bloquante. Un clic reste une requête HTTP qui attend les quatre à cinq
sessions WebSocket — c'est l'objet du lot C, que `sync_requested_at` attend déjà.
