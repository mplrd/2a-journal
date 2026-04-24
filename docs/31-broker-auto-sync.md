# Étape 31 — Auto-sync périodique des connexions broker

## Résumé

Scheduler backend qui synchronise automatiquement, à intervalle régulier et pour tous les utilisateurs, les connexions broker (cTrader, MetaApi) passées en `status=ACTIVE`. Fait le pendant natif du bouton "Synchroniser maintenant" existant côté UI (`POST /broker/connections/{id}/sync`).

Avant cette feature, les connexions étaient fonctionnellement inertes : l'utilisateur devait cliquer pour déclencher un pull. Le scheduler rend la sync réellement **autonome**.

Déploiement : un service Railway dédié `scheduler` qui exécute **supercronic** et appelle le CLI PHP `api/cli/sync-brokers.php`. Code métier partagé avec l'API (pas de duplication).

## Architecture

```
┌────────────────────────┐       ┌─────────────────────────┐
│  Service Railway "api" │       │  Service Railway        │
│                        │       │  "scheduler" (nouveau)  │
│  - api/Dockerfile      │       │                         │
│  - Sert /api HTTP      │       │  - scheduler/Dockerfile │
│  - Exécute migrations  │       │  - supercronic          │
│                        │       │  - crontab versionné    │
└───────────┬────────────┘       └────────────┬────────────┘
            │                                 │
            │      Même code PHP              │
            │      COPY api/ dans les         │
            │      deux images                │
            │                                 │
            └────────────┬────────────────────┘
                         ▼
                ┌────────────────────┐
                │  MariaDB Railway   │
                │  (partagée)        │
                └────────────────────┘
```

**Pourquoi un service séparé et non un cron dans le container api ?**

1. **Single-instance garantie** : si un jour l'api scale horizontalement, un cron embarqué s'exécuterait sur chaque réplica → doublons. Le scheduler isolé reste à 1 instance sans impacter le web.
2. **Découplage des cycles de vie** : un redémarrage api (deploy, OOM) ne coupe pas les jobs planifiés. Et inversement.
3. **Responsabilités nettes** : l'api sert des requêtes HTTP, le scheduler exécute des tâches planifiées. Monitoring et logs séparés.

**Pourquoi supercronic et pas cron Unix ?**

supercronic est conçu pour les containers : gestion propre des signaux (SIGTERM → arrêt gracieux), logs sur stdout (pas besoin de redirection manuelle), pas de fork daemon. Standard de facto pour la planification dans Docker.

## Fichiers impactés

### Code métier — dans `api/`

- `api/src/Repositories/BrokerConnectionRepository.php` — 4 nouvelles méthodes :
  - `findDueForAutoSync(int $intervalMinutes)` — SELECT les connexions ACTIVE dues à un pull
  - `incrementFailures(int $id)` — incrément du compteur d'échecs consécutifs
  - `resetFailures(int $id)` — reset à 0 sur succès
  - `markError(int $id, string $error)` — passage en `status=ERROR` après N échecs
- `api/src/Services/Broker/BrokerSyncSchedulerService.php` — **nouveau** — orchestration du run
- `api/cli/sync-brokers.php` — **nouveau** — entry point CLI exécuté par supercronic
- `api/config/broker.php` — 2 nouvelles clés de config (interval + max failures)
- `api/database/migrations/006_broker_consecutive_failures.sql` — ajout colonne

### Runtime — dans `scheduler/`

- `scheduler/Dockerfile` — image PHP + supercronic
- `scheduler/crontab` — jobs planifiés
- `scheduler/README.md` — lève l'ambiguïté "où vit le code"

## Variables d'environnement

Toutes à déclarer sur **les deux services Railway** (`api` et `scheduler`) pour rester cohérent. Le service `api` les lit pour exposer le feature flag aux frontends ; le scheduler les lit pour piloter son comportement.

| Variable | Type | Défaut | Rôle |
|---|---|---|---|
| `BROKER_ENCRYPTION_KEY` | base64 32-byte | **aucun** — fail fast au boot si absente | Clé symétrique qui chiffre les credentials broker stockés en BDD (`broker_connections.credentials_encrypted`). **Doit être identique sur les services `api` et `scheduler`** sinon impossible de déchiffrer. Générer avec `openssl rand -base64 32`. |
| `BROKER_AUTO_SYNC_ENABLED` | bool | `false` | Kill switch global. Si `false`, le scheduler tourne mais ne fait rien (`skipped:true` dans la sortie JSON). Utilisé aussi par l'api pour gater les endpoints `/broker/*`. |
| `BROKER_SYNC_INTERVAL_MINUTES` | int | `15` | **La fréquence effective d'auto-sync**. Cron déclenche le script PHP toutes les minutes (tick fin), mais le script ne synchronise une connexion que si son `last_sync_at` est plus ancien que cette valeur. Changer cette var = changer effectivement la fréquence, sans redeploy du scheduler. |
| `BROKER_SYNC_MAX_FAILURES` | int | `3` | Circuit breaker : après N échecs consécutifs, la connexion passe en `status=ERROR` et n'est plus pickée tant que l'utilisateur ne la réactive pas. |

### Note sur `BROKER_ENCRYPTION_KEY`

Avant 2026-04, `config/broker.php` avait un fallback silencieux sur une clé 32-zéros si la var n'était pas définie — les credentials étaient donc "chiffrés" avec une clé publique (présente en clair dans le code), neutralisant la protection au repos. Ce fallback a été retiré : l'absence de la var déclenche maintenant un `RuntimeException` au boot.

**Procédure de rotation future** (si la clé est compromise ou pour rotation périodique) :
1. Générer la nouvelle clé
2. Écrire un script CLI type `api/cli/rotate-broker-key.php` qui prend deux env vars (`BROKER_ENCRYPTION_KEY_OLD` + `BROKER_ENCRYPTION_KEY`), instancie deux `CredentialEncryptionService`, et loop sur `broker_connections` avec `decrypt(OLD) → encrypt(NEW) → update`
3. Exécuter le script une fois en prod
4. Retirer `BROKER_ENCRYPTION_KEY_OLD` des env vars

Le script n'a pas été livré avec cette itération car il n'y avait pas de données à migrer au moment du durcissement (feature jamais activée en prod, 0 connexions broker réelles). À écrire au premier besoin réel.

**Note d'évolution** : ces valeurs sont pour l'instant câblées via env vars Railway (changement = redeploy). Un BO admin futur pourra les override via une table `platform_settings`, avec précédence : settings BDD > env var > défaut.

## Cycle d'un run

Un run part toutes les **5 minutes** (fréquence du cron dans `scheduler/crontab`). Cette valeur est la **granularité maximale** ; l'intervalle effectif par connexion est piloté par `BROKER_SYNC_INTERVAL_MINUTES` via le filtre SQL.

```
supercronic tick (*/5 min)
    │
    ▼
php api/cli/sync-brokers.php
    │
    ├─ flock /tmp/broker-sync.lock  (skip silent si un run précédent tourne encore)
    │
    ├─ Bootstrap PDO + config + DI
    │
    ▼
BrokerSyncSchedulerService::runDueConnections()
    │
    ├─ Si BROKER_AUTO_SYNC_ENABLED=false → return {skipped:true, ...}
    │
    ├─ SELECT * FROM broker_connections
    │   WHERE status='ACTIVE'
    │     AND (last_sync_at IS NULL OR last_sync_at < UTC_TIMESTAMP() - INTERVAL X MINUTE)
    │
    ├─ Pour chaque connexion, en try/catch isolé :
    │     ├─ Succès  → BrokerSyncService::sync() + resetFailures(id)
    │     └─ Échec   → incrementFailures(id)
    │                 Si consecutive_failures atteint max → markError(id) + status=ERROR
    │
    └─ Émet un résumé JSON sur stdout (logs Railway)
```

### Exemple de sortie JSON

```json
{"status":"ok","skipped":false,"processed":12,"success":11,"failed":1,"deactivated":0}
```

### Circuit breaker

La colonne `consecutive_failures` (INT UNSIGNED, default 0) compte les échecs consécutifs :
- Chaque succès → reset à 0.
- Chaque échec → `+1`. Si la nouvelle valeur ≥ `BROKER_SYNC_MAX_FAILURES`, la connexion bascule en `status=ERROR` et le message de l'exception est stocké dans `last_sync_error`.
- Une fois en ERROR, la connexion n'est plus pickée par `findDueForAutoSync()`. Seule une action utilisateur (reconnexion / changement de statut via l'UI) peut la remettre en ACTIVE.

**Justification du seuil à 3** : suffisamment petit pour attraper une panne durable rapidement (≤ 45 min à 15 min d'intervalle), suffisamment grand pour absorber un blip réseau isolé.

## Gestion des timezones

**Point d'attention important.** La requête `findDueForAutoSync` utilise `UTC_TIMESTAMP()` (pas `NOW()`). Raison : MariaDB `NOW()` dépend de la session timezone du serveur, qui peut différer du TZ utilisé par PHP. Pour rester cohérent quelle que soit la conf MySQL, on compare en UTC :

```sql
WHERE last_sync_at < UTC_TIMESTAMP() - INTERVAL X MINUTE
```

Ça suppose que `last_sync_at` est **écrit en UTC** côté PHP. Le service existant `BrokerSyncService` utilise `date('Y-m-d H:i:s')` (timezone courant du process). En prod, les containers Railway PHP tournent en UTC par défaut → cohérent. En dev local, il faut vérifier que `date_default_timezone_get()` renvoie `UTC` (c'est le cas par défaut pour PHP CLI sans `.ini` custom).

## Déploiement Railway — procédure à faire une fois

Toute la conf est versionnée dans le repo ; Railway n'a besoin que du pointeur.

1. **New Service** → "Deploy from GitHub repo" → ce repo.
2. **Settings → Source** :
   - Root Directory : `.` (racine du repo)
   - Dockerfile Path : `scheduler/Dockerfile`
3. **Settings → Variables** :
   - Copier les env vars du service `api` (bouton "Copy from service") : DB creds, `BROKER_ENCRYPTION_KEY`, `BROKER_AUTO_SYNC_ENABLED`, etc.
   - Ajouter `BROKER_SYNC_INTERVAL_MINUTES` et `BROKER_SYNC_MAX_FAILURES` (sur les deux services pour cohérence).
4. **Settings → Scaling** : `1 replica` **exactement**. Deux instances = jobs exécutés en double.
5. **Settings → Networking** : ne pas exposer de port public. Le scheduler n'a pas d'endpoint HTTP.

Une fois déployé, les logs Railway du service `scheduler` affichent une ligne JSON toutes les 5 min — pratique pour debug.

## Ajouter un nouveau job planifié

Workflow standard :

1. Coder le service dans `api/src/Services/...`.
2. Coder l'entry point CLI dans `api/cli/<nom>.php` (bootstrap léger + appel du service).
3. Ajouter une ligne dans `scheduler/crontab` :
   ```
   */10 * * * * php /var/www/api/cli/mon-job.php
   ```
4. Commit + redeploy du service `scheduler`.

**Pattern à respecter pour le CLI** (voir `api/cli/sync-brokers.php`) :
- Autoload + `.env` comme dans les seeders
- `flock()` sur un fichier dans `sys_get_temp_dir()` pour éviter les runs parallèles
- Try/catch global avec exit code 0/1
- Sortie JSON sur stdout (logs structurés Railway)

## Couverture de tests

| Test | Type | Fichier |
|---|---|---|
| `findDueForAutoSync` — connexion jamais synchronisée | Integration | `BrokerConnectionRepositoryTest` |
| `findDueForAutoSync` — older than interval | Integration | id |
| `findDueForAutoSync` — recently synced (exclue) | Integration | id |
| `findDueForAutoSync` — status non-ACTIVE (exclue) | Integration | id |
| `findDueForAutoSync` — multiple due | Integration | id |
| `findDueForAutoSync` — clamp interval négatif | Integration | id |
| `incrementFailures` | Integration | id |
| `resetFailures` | Integration | id |
| `markError` | Integration | id |
| Scheduler no-op si `auto_sync_enabled=false` | Unit (mocks) | `BrokerSyncSchedulerServiceTest` |
| Scheduler happy path (toutes les connexions réussissent) | Unit | id |
| Scheduler mix succès/échec non-fatal | Unit | id |
| Scheduler atteinte du max → markError + deactivated | Unit | id |
| Scheduler rien de dû | Unit | id |
| CLI smoke — exit 0 + JSON structuré avec 0 connexion | Integration | `SyncBrokersCliTest` |
| CLI smoke — flag disabled → `skipped:true` | Integration | id |

**16 tests ajoutés**, tous verts. Suite complète : 952 tests verts (pas de régression).

## Choix d'implémentation

### Pourquoi pas de colonne `sync_interval_minutes` par connexion

Demande utilisateur : fréquence globale, pas de choix par user. Ça simplifie le MVP. Si le besoin apparaît (un broker lent VS un broker rapide), on ajoutera une colonne override nullable avec fallback sur la conf globale.

### Pourquoi `UTC_TIMESTAMP()` inline plutôt que `NOW()` bindé

MariaDB ne supporte pas les paramètres liés dans les expressions `INTERVAL` — il faut interpoler la valeur numériquement. Pour rester safe contre l'injection SQL, l'intervalle est `int`-typé puis clampé à `[1, 1440]` avant l'interpolation. Pattern documenté dans le docblock de `findDueForAutoSync`.

### Pourquoi un seul `flock` global et pas un lock par connexion

Simplicité et suffisance : un run dure quelques secondes à quelques minutes, le cron tombe toutes les 5 minutes. Le risque d'overlapping est faible et les runs parallèles sur des connexions différentes n'apportent pas de bénéfice (le scheduler ne parallélise pas). Le flock global couvre le cas "un run particulièrement lent + nouveau cron" sans complexité supplémentaire.

### Pourquoi le CLI bootstrap toutes les dépendances manuellement

Le projet n'a pas de container DI (choix architectural initial). Le CLI duplique ~20 lignes de wiring depuis `routes.php`. Un refacto futur pourrait extraire une factory partagée, mais ça dépasse le scope de cette feature et toucherait le code HTTP existant (hors scope demandé).

## Points d'attention pour la maintenance

- **Monitoring** : les lignes JSON sur stdout sont la source de vérité. Pas de persistence des runs côté BDD (seul le détail par connexion est dans `sync_logs` via le service existant). Si besoin de dashboards, alimenter depuis les logs Railway ou externaliser.
- **Rotation du secret `BROKER_ENCRYPTION_KEY`** : si on change la clé, les credentials chiffrés existants deviennent illisibles → tous les syncs échouent → toutes les connexions passent en ERROR après 3 runs (~45 min). Prévoir une migration manuelle (déchiffrer avec l'ancienne clé + rechiffrer avec la nouvelle) avant toute rotation.
- **Backup du scheduler** : si le service Railway `scheduler` tombe pendant un déploiement, les syncs ne se font pas — mais les utilisateurs gardent leur bouton "Sync now" dans l'UI. Pas de data loss, juste un délai.
