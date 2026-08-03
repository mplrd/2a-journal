# 74 - support-cli + skill `/ticket`

## Objectif

Permettre de **traiter un ticket de support de bout en bout dans une session Claude Code** :
lire le ticket → le qualifier → (si besoin) le développer en réutilisant le workflow TDD
existant → répondre au demandeur et clore, le tout sans quitter la session ni passer par
le back-office web.

## Pourquoi

Le module de support (cf. `docs/69-support-tickets.md`) expose déjà une API admin complète.
Plutôt que de naviguer dans le BO pour lire un ticket, basculer dans l'éditeur pour coder le
fix, puis revenir clore le ticket à la main, on rassemble la boucle en un seul flux outillé.
Gain : moins de contexte perdu, lien direct entre la livraison et la réponse au demandeur,
et qualification systématique (SUPPORT / BUG / FEATURE) alignée sur le backlog `evolutions.md`.

## Architecture

Deux briques, séparées par responsabilité :

```
tools/support-cli/        # L'OUTIL : I/O avec l'API support
  support-cli.php         #   client CLI (login admin + endpoints admin)
  .env.example            #   gabarit de config (.env réel gitignoré)
  README.md               #   usage
.claude/skills/ticket/    # LE WORKFLOW : orchestration de session
  SKILL.md
```

Le CLI ne porte aucune logique métier : il appelle l'API. La skill ne fait aucun I/O
réseau : elle pilote le flux et délègue (au CLI pour l'API, aux autres skills pour le dev).
Si demain on remplace le CLI par un serveur MCP, seule la couche « comment j'appelle l'API »
change ; le workflow de la skill reste identique.

### support-cli.php

Client en PHP (réutilise le stack du projet, aucune nouvelle techno). À chaque appel il se
connecte via `POST /auth/login` (credentials dans `tools/support-cli/.env`), vérifie que le
compte est bien `ADMIN`, puis appelle les endpoints admin avec le token en `Bearer`. Aucun
token persisté.

Commandes : `list`, `show`, `reply`, `set-status`, `set-priority`, `set-type`. Mapping sur l'API admin :

| Commande CLI | Endpoint |
|---|---|
| `list [--status/--type/--priority/--search/--page/--per-page]` | `GET /admin/support/tickets` |
| `show <id>` | `GET /admin/support/tickets/{id}` |
| `reply <id> --body=…` | `POST /admin/support/tickets/{id}/messages` |
| `set-status <id> <STATUS>` | `PATCH /admin/support/tickets/{id}/status` |
| `set-priority <id> <PRIORITY>` | `PATCH /admin/support/tickets/{id}/priority` |
| `set-type <id> <TYPE>` | `PATCH /admin/support/tickets/{id}/type` (reclassement, cf. doc 88) |

**Écritures = dry-run par défaut.** `reply` et `set-status` déclenchent un e-mail réel au
demandeur (via les notifications du module support) ; `set-priority` et `set-type` sont
silencieux mais restent des écritures. Sans `--confirm`, toutes n'affichent que ce qu'elles
feraient. Le `--confirm` est requis pour exécuter — garde-fou au niveau de l'outil, en plus
du gating au niveau de la skill.

### Skill `/ticket`

Orchestre la session, **opt-in à chaque étape** (rien ne part sans décision explicite) :

1. **Lecture** — `list` (si pas d'id) puis `show <id>`.
2. **Triage** — résumé + classification SUPPORT/BUG/FEATURE, puis propose : répondre direct /
   développer / mettre au backlog (`docs/evolutions.md`).
3. **Livraison** (sur « go » uniquement) — enchaîne `/tdd-feature`, puis `/check-quality`,
   `/check-i18n`, `/audit-security`, `/audit-privacy`, puis `/doc-feature` (doc FR avant merge).
4. **Clôture** (writes gated) — rédige la réponse (dry-run d'abord), envoie sur validation,
   propose le changement de statut, applique sur validation.

## Sécurité & vie privée

- **Credentials** : compte admin dans `.env`, déjà couvert par la règle `.env` du `.gitignore`.
  Jamais commité, jamais écrit en mémoire.
- **Writes gated** : une réponse ou un changement de statut part par e-mail au client. Toute
  écriture passe par validation explicite (dry-run → l'utilisateur voit le texte exact →
  `--confirm`). Une validation ne vaut que pour une action.
- **PII** : les tickets contiennent des données d'autres utilisateurs ; aucun contenu de
  ticket n'est persisté hors session (ni mémoire, ni repo).

## Tests

`api/tests/Unit/Tools/SupportCliTest.php` couvre les fonctions pures du CLI : parsing du
`.env`, parsing des arguments (positionnels / options / flags), construction de la query de
filtres, formatage d'une ligne de liste et d'un détail de ticket (fil + champs `details`).

```bash
cd api && php vendor/bin/phpunit --filter SupportCliTest
```

Les commandes réseau (login, appels admin) ne sont pas testées en unitaire (dépendance à
l'API live) ; elles se valident en exécutant le CLI contre l'environnement réel.

## Limitations / suite

- Pas de pièces jointes à l'envoi via le CLI (le BO web reste la voie pour répondre avec image).
- Login à chaque appel (simple, sans cache de token) — suffisant pour le volume actuel.
- Évolution possible vers un **serveur MCP** (outils typés, persistés entre sessions) si
  l'usage se densifie ; le découpage actuel rend la migration triviale.
