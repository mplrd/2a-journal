# scheduler/

Runtime de planification — **ne contient aucune logique métier**.

## Contenu

- `Dockerfile` — image PHP 8.4-cli + supercronic, copie `api/` dedans au build.
- `crontab` — liste des jobs planifiés (format cron standard, 5 champs). Source de vérité.

## Où vit la logique applicative

Les entry points CLI planifiés et leurs services vivent dans `api/` avec le reste du code backend :

| Job | Entry point | Service |
|---|---|---|
| Broker auto-sync | `api/cli/sync-brokers.php` | `api/src/Services/Broker/BrokerSyncSchedulerService.php` |

## Ajouter un nouveau job

1. Créer le service + repo methods dans `api/src/...` (code métier).
2. Créer l'entry point CLI dans `api/cli/<job-name>.php` (wrapper fin qui bootstrap + appelle le service).
3. En haut du fichier CLI, déclarer `const JOB_NAME = '<job-name>';` (kebab-case, match le nom du fichier).
4. Inclure `'job' => JOB_NAME` dans **chaque** sortie JSON (stdout pour succès/locked, stderr pour error). Permet le filtrage des logs quand plusieurs jobs coexistent.
5. Ajouter une ligne dans `scheduler/crontab` pointant vers le nouvel entry point.
6. Commit → prochain deploy du service `scheduler` sur Railway applique le changement.

Voir `api/cli/sync-brokers.php` comme référence du pattern.

## Déploiement Railway

Voir `docs/31-broker-auto-sync.md` pour la procédure complète (création du 3e service, env vars, scaling).

Règle dure : **scale = 1 instance maximum**. Deux instances = jobs exécutés en double.
