# support-cli

Client en ligne de commande pour l'**API de support** du Trading Journal.
Permet de lire, trier et mettre à jour les tickets sans passer par le back-office web.
C'est l'outil derrière la skill `/ticket`.

## Configuration

```bash
cp tools/support-cli/.env.example tools/support-cli/.env
```

Puis renseigner dans `.env` (gitignoré, jamais commité) :

| Variable | Description |
|---|---|
| `JOURNAL_API_URL` | URL de base de l'API, sans `/` final (ex. `http://2a.journal.local/api`) |
| `JOURNAL_ADMIN_EMAIL` | Compte **ADMIN** (le CLI refuse un compte non admin) |
| `JOURNAL_ADMIN_PASSWORD` | Mot de passe du compte admin |

Le CLI se connecte (`POST /auth/login`) à chaque appel et utilise le token en `Bearer`.
Aucun token n'est stocké sur disque.

## Commandes

```bash
# Lecture (libre, aucun effet de bord)
php tools/support-cli/support-cli.php list --status=OPEN,IN_PROGRESS
php tools/support-cli/support-cli.php list --type=BUG --priority=HIGH --search=crash
php tools/support-cli/support-cli.php show 42

# Écriture (DRY-RUN par défaut — voir plus bas)
php tools/support-cli/support-cli.php reply 42 --body="Corrigé en v1.2, merci !"
php tools/support-cli/support-cli.php set-status 42 RESOLVED
php tools/support-cli/support-cli.php set-priority 42 HIGH
```

Ajouter `--json` à n'importe quelle commande pour obtenir la réponse brute de l'API.

### Écritures = dry-run par défaut

`reply`, `set-status` et `set-priority` **déclenchent un e-mail réel** au demandeur.
Par sécurité, ces commandes sont en **dry-run** : sans `--confirm`, elles affichent
seulement ce qu'elles feraient. Ajouter `--confirm` pour exécuter réellement :

```bash
php tools/support-cli/support-cli.php reply 42 --body="…" --confirm
php tools/support-cli/support-cli.php set-status 42 RESOLVED --confirm
```

## Valeurs

- **Statuts** : `OPEN | IN_PROGRESS | WAITING_USER | RESOLVED | CLOSED`
- **Priorités** : `LOW | NORMAL | HIGH`
- **Types** (filtre) : `SUPPORT | BUG | FEATURE`

## Codes de sortie

| Code | Signification |
|---|---|
| 0 | OK |
| 1 | Erreur d'usage (argument manquant/invalide) |
| 2 | Erreur de config (`.env` absent/incomplet, compte non admin) |
| 3 | Erreur API/HTTP (login échoué, endpoint en erreur) |

## Tests

Les fonctions pures (parsing `.env`, parsing des arguments, construction de la
query, formatage) sont couvertes par `api/tests/Unit/Tools/SupportCliTest.php` :

```bash
cd api && php vendor/bin/phpunit --filter SupportCliTest
```
