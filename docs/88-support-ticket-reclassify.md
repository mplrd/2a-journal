# 88 - Reclassement du type d'un ticket support

## Objectif

Permettre à un administrateur de **changer le type** d'un ticket support (`SUPPORT` ↔ `BUG` ↔ `FEATURE`) après sa création, depuis le back-office comme depuis le CLI de support.

## Pourquoi

Le type était figé à la création : c'est le **rapporteur** qui le choisissait, une fois pour toutes. En pratique la typologie dérive — un bug déclaré en « demande de support », une évolution déclarée en « bug » — et le tri devient faux : les filtres du back-office (`?type=BUG`) et le comptage par type ne reflètent plus la réalité. Jusqu'ici la seule correction possible était une mise à jour SQL directe.

Le statut et la priorité étaient déjà modifiables par l'admin ; le type manquait à l'appel sans raison particulière.

## Fonctionnalités

- **Back-office** : dans le détail d'un ticket, un `Select` « Type » à côté de Statut et Priorité. Le changement est appliqué immédiatement (`PATCH`), avec toast de confirmation. Le badge de la ligne correspondante dans la liste est resynchronisé sans rechargement.
- **CLI** : `php support-cli.php set-type <id> <SUPPORT|BUG|FEATURE> [--confirm]`, en DRY-RUN par défaut comme les autres commandes d'écriture.
- **Côté utilisateur** : le nouveau type est visible sur sa demande (badge), sans notification e-mail.

## Choix d'implémentation

### Pas de migration

La colonne `support_tickets.type` est déjà un `ENUM('SUPPORT','BUG','FEATURE')` (migration `025`). Le reclassement ne fait que réécrire une valeur déjà autorisée : rien à migrer.

### Les `details` sont conservés tels quels

C'est le seul vrai arbitrage. Les champs structurés (`details`, cf. doc 69) sont **whitelistés par type** : `expected_behavior` / `reproduction_steps` pour un bug, `benefit` / `imagined_solution` pour une évolution. Reclasser un `BUG` en `FEATURE` laisse donc des clés qui n'appartiennent pas au nouveau type.

**Décision : on garde le contenu et on l'affiche tel quel.** C'est ce que le rapporteur a réellement écrit ; le perdre coûterait plus cher que l'incohérence de libellé. `AdminTicketDialog` itère déjà sur les clés présentes sans filtrer par type — l'affichage reste donc correct sans code supplémentaire. Le commentaire de `SupportTicketRepository::updateType()` documente cette intention pour éviter qu'un futur « nettoyage » ne les efface.

### Pas d'e-mail

Le reclassement est une opération de **tri interne**, pas un évènement de cycle de vie : il ne notifie pas le créateur — même choix que `changePriority` (`changeStatus`, lui, notifie). Un ticket peut ainsi être retypé plusieurs fois pendant le triage sans spammer l'auteur.

### Validation

`SupportTicketService::changeType()` réutilise le `validateType()` existant (celui de la création) : valeur inconnue → `ValidationException` avec `message_key = support.error.invalid_type` (422). Aucune chaîne en dur, l'enum `TicketType` reste la source de vérité. L'endpoint est monté derrière `AuthMiddleware + RequireAdminMiddleware` : un utilisateur non-admin reçoit un 403, y compris sur son propre ticket.

### Endpoint

```
PATCH  /api/admin/support/tickets/{id}/type      body: { "type": "FEATURE" }
```

Retourne le détail complet du ticket (`{ success, data }`), comme `status` et `priority`.

Chaîne complète : `AdminSupportTicketController::updateType()` → `SupportTicketService::changeType()` → `SupportTicketRepository::updateType()` (requête préparée, `details` non touchée).

### Front admin

`syncListFrom()` du store propageait déjà `status` et `priority` vers la ligne de liste ; `type` y a été ajouté — sans quoi le badge de la colonne « Type » restait périmé jusqu'au prochain `fetchTickets()`.

Le `Tag` de type qui figurait dans l'en-tête du dialog a été déplacé dans la barre de contrôles, à côté du `Select`, pour suivre le motif existant (sélecteur + badge de confirmation) et éviter deux affichages concurrents de la même donnée.

## Couverture des tests

| Test | Scénario | Statut |
|---|---|---|
| `SupportTicketRepositoryTest::testUpdateTypeReclassifiesAndKeepsDetails` | `updateType()` écrit le nouveau type et laisse `details` intact | ✅ |
| `SupportTicketServiceTest::testChangeTypeReclassifiesWithoutNotifyingCreator` | `changeType()` appelle le repo avec la bonne valeur et **n'envoie aucun mail** | ✅ |
| `SupportTicketServiceTest::testChangeTypeRejectsInvalidValue` | Type inconnu (`QUESTION`) → `ValidationException` | ✅ |
| `SupportTicketServiceTest::testChangeTypeUnknownTicketThrowsNotFound` | Ticket inexistant → `NotFoundException` | ✅ |
| `SupportFlowTest::testAdminReclassifiesTicketType` | `PATCH .../type` bout-en-bout ; le créateur voit le nouveau type sur sa demande | ✅ |
| `SupportFlowTest::testAdminReclassifyRejectsUnknownType` | Type invalide → 422 | ✅ |
| `SupportFlowTest::testUserCannotReclassifyTicketType` | Utilisateur non-admin → 403 | ✅ |
| `admin/support-service.spec.js` — « patches the type endpoint » | Le service front tape bien `PATCH /admin/support/tickets/{id}/type` | ✅ |
| `admin/support-store.spec.js` — « updateType syncs the list row badge » | Le store met à jour `current` **et** la ligne de liste | ✅ |

Suites complètes vertes : backend 1592 tests, admin 22 tests.

## i18n

Clé ajoutée dans `admin/src/locales/{fr,en}.json` : `support.toast.type_updated` (« Type mis à jour » / « Type updated »). Les libellés `support.type.*` et `support.field.type` existaient déjà. Parité fr/en vérifiée.

## Limitations / suite

- **Pas d'historique du reclassement** : on ne trace pas qui a retypé quoi ni quand (le `$adminId` est reçu par le service mais non persisté — même situation que la priorité). Si un audit du triage devient nécessaire, il faudra une table d'évènements pour l'ensemble des changements admin, pas seulement le type.
- Les `details` conservés peuvent afficher un libellé incohérent avec le nouveau type (assumé, cf. ci-dessus).
