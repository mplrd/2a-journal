# 69 - Module de support (tickets)

## Objectif

Donner aux utilisateurs un canal in-app pour remonter un besoin — **demande de support**, **bug** ou **proposition d'évolution** — et à l'administration un back-office pour traiter ces demandes : liste filtrable, fil de discussion, changement de statut et de priorité. Chaque évènement (création, nouvelle réponse, changement de statut) déclenche une **notification e-mail**.

## Pourquoi

Jusqu'ici aucun canal structuré : les retours passaient par des e-mails épars, sans suivi ni statut. Cette brique :

- centralise les demandes (historique, statut, priorité au même endroit) ;
- est accessible à **tout utilisateur connecté**, y compris sans abonnement actif (le support ne doit jamais être bloqué par le paywall) ;
- permet des échanges bidirectionnels (fil de messages) avec **pièces jointes images** (captures d'écran de bug).

## Architecture

### Schéma DB (migration `025_support_tickets.sql`)

Trois tables additives :

- `support_tickets` — une ligne par demande : `user_id` (créateur), `type` ∈ `SUPPORT | BUG | FEATURE`, `status` ∈ `OPEN | IN_PROGRESS | WAITING_USER | RESOLVED | CLOSED` (défaut `OPEN`), `priority` ∈ `LOW | NORMAL | HIGH` (défaut `NORMAL`), `subject`, timestamps. `closed_at` est horodaté au passage en `CLOSED`.
- `support_ticket_messages` — le fil. Une ligne par message (créateur ou admin). `author_is_admin` fige le côté à l'écriture (le fil reste correct même si le compte auteur est supprimé → `author_id` devient `NULL` via `ON DELETE SET NULL`).
- `support_ticket_attachments` — métadonnées des images jointes (`stored_path`, `original_name`, `mime_type`, `size_bytes`). Les fichiers vivent **hors webroot** ; la table ne stocke que les métadonnées.

FK `ON DELETE CASCADE` depuis `users` et entre ticket → messages → attachments.

### Enums

`TicketType`, `TicketStatus`, `TicketPriority` (PHP natifs, `App\Enums`), répliqués côté front dans `frontend/src/constants/support.js` et inline côté admin.

### Pièces jointes — stockage & sécurité

- Service réutilisable `FileUploadService` : valide le type **réel** via `finfo` (jamais l'en-tête client), la taille (5 Mo max) contre une whitelist (`image/jpeg|png|webp`), génère un nom **non devinable** (`bin2hex(random_bytes(16))`), et stocke sous une base configurable.
- Base de stockage : `api/storage/uploads/tickets/` — **hors `public/`** (contrairement aux avatars), car une PJ de ticket peut être sensible. Dossier `.gitignore` (seul `.gitkeep` versionné).
- Téléchargement **uniquement via endpoint authentifié** qui vérifie que le demandeur est le créateur **ou** un admin, puis stream le fichier (`Content-Type` validé, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, nom de fichier sanitisé contre l'injection d'en-tête). Pas d'URL statique Apache.
- Max 5 PJ par message.

#### Robustesse taille — mobile (fix 2026-05-31)

Symptôme : sur mobile (photos de pellicule lourdes, 3-8 Mo / 12 Mpx), l'envoi pouvait échouer (« erreur interne du serveur » ou message trompeur), alors que la même image passe sur desktop — le fichier réellement envoyé n'est pas le même poids. Trois corrections en défense en profondeur :

- **Compression côté client** (`frontend/src/utils/imageCompression.js`) : avant l'upload, les images > 1,5 Mo sont redimensionnées (2000 px max sur le grand côté, JPEG qualité 0,82) dans le navigateur via `<canvas>`. Non-images et petites images laissées intactes ; fallback sur le fichier original si la compression échoue (jamais bloquant). Câblé dans `NewTicketDialog` et `TicketDetailDialog`. → la photo lourde n'atteint plus le serveur.
- **`FileUploadService::store()`** : un fichier rejeté par PHP pour dépassement (`UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE`) renvoie désormais `upload.error.too_large` au lieu du trompeur `upload.error.required`.
- **`Request::isMultipartTruncated()` + `Controller::guardUploadNotTruncated()`** : si le corps multipart dépasse `post_max_size` (PHP vide alors `$_POST`/`$_FILES`), les endpoints d'upload renvoient un `upload.error.too_large` propre (422) au lieu d'un 500 / d'une validation incohérente. Limites serveur : `upload_max_filesize` / `post_max_size` = 32 Mo (`api/docker/php.ini`).

### Endpoints

Côté utilisateur — `AuthMiddleware` seul (**pas** de gate abonnement) :

```
GET    /api/support/tickets
POST   /api/support/tickets
GET    /api/support/tickets/{id}
POST   /api/support/tickets/{id}/messages
GET    /api/support/tickets/{id}/attachments/{attId}
```

Côté admin — `AuthMiddleware + RequireAdminMiddleware` :

```
GET    /api/admin/support/tickets
GET    /api/admin/support/tickets/{id}
POST   /api/admin/support/tickets/{id}/messages
PATCH  /api/admin/support/tickets/{id}/status
PATCH  /api/admin/support/tickets/{id}/priority
GET    /api/admin/support/tickets/{id}/attachments/{attId}
```

Contrôleurs fins (`SupportTicketController`, `AdminSupportTicketController`) délégant à `SupportTicketService`. Réponses au format `{ success, data, meta }`, erreurs via `message_key`.

### Service `SupportTicketService`

Centralise la logique et les notifications :

- `createTicket` → ticket + 1er message + PJ ; le statut/priorité sont **forcés** (`OPEN`/`NORMAL`), le client ne peut pas les fixer. Notifie tous les admins.
- `replyAsUser` → vérifie l'ownership, refuse si `CLOSED`. Notifie les admins.
- `replyAsAdmin` → notifie le créateur.
- `changeStatus` → horodate `closed_at` si `CLOSED`, notifie le créateur (si changement effectif).
- `changePriority` → pas de mail (interne).
- Listes paginées (`{ data, meta }`), détail assemblé (messages + PJ groupées par message).

Validation serveur systématique (type/statut/priorité via enums, sujet ≤ 200, corps non vide, nb de PJ ≤ 5).

### Notifications e-mail

`EmailService` (driver `log` en dev, `resend` en prod) — 3 méthodes + templates fr/en (`api/templates/emails/ticket-*.html`) :

- `sendTicketCreatedToAdmin` — création → **tous les comptes `ADMIN`** (`UserRepository::findAdmins()`).
- `sendTicketReplyEmail` — nouvelle réponse → admins (réponse user) ou créateur (réponse admin).
- `sendTicketStatusChangedEmail` — changement de statut → créateur.

Envoi best-effort (try/catch + log, ne bloque jamais la requête). Lien `frontend_url/support?ticket=ID` (deep-link).

## Frontend journal

- **Picto « aide »** (`pi pi-question-circle`) à côté de l'avatar (`AppLayout.vue`) → `Popover` (menu burger) avec **« Mes demandes »** → route `/support`.
- `SupportView.vue` : `DataTable` paginée des demandes de l'utilisateur (type / statut / priorité en `Tag`), bouton « Nouvelle demande ». Deep-link `?ticket=ID` ouvre directement le détail.
- `NewTicketDialog.vue` : formulaire (type, sujet, description, images) avec validation front.
- `TicketDetailDialog.vue` : fil de discussion, zone de réponse, PJ ouvrables (fetch blob authentifié → nouvel onglet), bandeau si demande fermée.
- `services/support.js`, `stores/support.js`, `constants/support.js`. `api.js` enrichi de `getBlob` (téléchargement authentifié).

## Frontend admin

- Route `/support` + lien nav (`AdminLayout.vue`).
- `SupportView.vue` : `DataTable` de **tous** les tickets + filtres (type / statut / priorité / recherche email & sujet), e-mail du demandeur, badges, nombre de messages.
- `AdminTicketDialog.vue` : édition statut & priorité (`Select` → `PATCH` instantané), fil de discussion, réponse avec PJ, téléchargement authentifié.
- `services/support.js`, `stores/support.js`. `api.js` enrichi de `upload` + `getBlob`.

## i18n

Bloc `support.*` (+ `upload.error.*`) ajouté dans `frontend/src/locales/{fr,en}.json` et `admin/src/locales/{fr,en}.json`. Parité fr/en vérifiée. Les `message_key` renvoyés par l'API (`support.error.*`, `upload.error.*`) sont résolus côté front.

## Tests

- **Backend** (PHPUnit) : enums (`SupportEnumsTest`), `FileUploadServiceTest` (rejet MIME/taille dont `INI_SIZE`/`FORM_SIZE` → `too_large`, nom non devinable), `RequestTest` (détection multipart tronqué), `SupportTicketServiceTest` (création, ownership, déclencheurs mail, transitions de statut), `SupportTicketRepositoryTest` (CRUD + scoping), `SupportFlowTest` (intégration bout-en-bout : création, scoping, fil admin, statut/priorité, accès **sans abonnement**, ownership sur le download des PJ). Suite complète verte.
- **Frontend** (Vitest) : `support-store.spec.js`, `support-service.spec.js`, `imageCompression.spec.js` (journal) et `support-store.spec.js` (admin).

## Limitations / suite

Voir `docs/evolutions.md` (section *Support*) : nettoyage physique des PJ sur hard-delete, rate-limiting création de tickets, preview inline des images + support PDF, refacto de l'upload avatar sur `FileUploadService`.
