# 73 - Carnet de notes

## Objectif

Donner à l'utilisateur un **espace de notes personnel** dédié à son trading, indépendant des trades : retours d'expérience (REX), points d'attention, rappels de discipline. Chaque note porte une **date**, peut être rangée dans une **catégorie personnalisée**, recevoir des **images** (captures de setup) et être **épinglée** pour apparaître sur le tableau de bord.

> Exemples visés : « les trades de nuit : spread de 4 pts vs 1 + 2 pts / position », « si journée flux, attendre la reprise de la MM7 UT15 », « ne pas renforcer plus d'une fois quand je ne suis pas protégé ».

## Pourquoi

Le besoin (remonté en proposition d'évolution) : un endroit libre où l'utilisateur consigne ce que 2A n'analyse pas encore — un carnet perso avec **catégories qu'il crée lui-même** (Money Management, Setup de trading, Gestion de position, etc.). 2A **n'analyse pas** ces notes : elles servent uniquement de mémoire à l'utilisateur.

Choix structurants validés avec le demandeur :

- **Onglet autonome** « Carnet » dans la marge (et non un encart dans Performance).
- **Catégories en table dédiée et 100 % personnalisables** (≠ les `setups` de trade dont la catégorie est un ENUM figé). Une note sans catégorie est « Autre ».
- Catégories gérées **depuis l'onglet Carnet** lui-même (pas dans les paramètres de compte).
- **Plusieurs images par note**.
- **Épingle** → affichage sur le dashboard (home).

## Architecture

### Schéma DB (migration `030_notebook.sql`)

Trois tables additives :

- `note_categories` — buckets définis par l'utilisateur : `user_id`, `label`, soft delete (`deleted_at`), `UNIQUE (user_id, label)`. Même forme que `setups` mais sans ENUM de taxonomie — ce sont les libellés eux-mêmes que l'utilisateur crée/renomme/supprime.
- `notes` — une ligne par note : `user_id`, `category_id` (nullable), `title` (nullable), `content` (TEXT, requis), `note_date` (DATE, **obligatoire**), `is_pinned` (TINYINT), timestamps, soft delete. FK `category_id → note_categories` en `ON DELETE SET NULL`.
- `note_attachments` — métadonnées des images (`stored_path`, `original_name`, `mime_type`, `size_bytes`). Fichiers **hors webroot**, comme le module support. FK `note_id → notes` en `ON DELETE CASCADE`.

FK `ON DELETE CASCADE` depuis `users`.

### Catégories : suppression = détachement

Les catégories sont **soft-deleted**. Comme le soft delete ne déclenche pas le `ON DELETE SET NULL`, `NoteCategoryService::delete()` :

1. réaffecte explicitement les notes de la catégorie à `NULL` (`NoteRepository::clearCategory()`) → elles repassent en « Autre » au lieu de pointer vers une catégorie disparue ;
2. soft-delete la catégorie.

Le couple unique `(user_id, label)` ignore `deleted_at` : un fantôme soft-deleted bloquerait un re-`INSERT`/rename du même libellé (1062). Géré comme pour les setups (`findAnyByUserAndLabel` + `hardDelete` du fantôme à la création/rename) — aucun écran ne restaure une catégorie supprimée, le hard delete du fantôme est donc sûr.

### Backend (couches fines, logique en service)

| Élément | Fichier |
|---|---|
| Repositories | `NoteCategoryRepository`, `NoteRepository`, `NoteAttachmentRepository` |
| Services | `NoteCategoryService`, `NoteService` |
| Controllers | `NoteCategoryController`, `NoteController` |

La **propriété est toujours vérifiée en service** (`user_id` → `ForbiddenException`). `NoteService` valide : contenu requis (max 20 000), titre optionnel (max 150), `note_date` au format `Y-m-d` strict, `category_id` appartenant bien à l'utilisateur (sinon `notes.error.invalid_category`), et coercition booléenne de `is_pinned`.

La liste charge les images en **une requête** (`findByNoteIds`, anti N+1) et joint le libellé de catégorie (`category_label`) pour l'affichage.

### Endpoints (auth + abonnement actif)

```
GET    /note-categories
POST   /note-categories
PUT    /note-categories/{id}
DELETE /note-categories/{id}

GET    /notes                         (filtres: category_id, pinned)
POST   /notes                         (multipart: champs + attachments[])
GET    /notes/{id}
PUT    /notes/{id}                    (JSON: édition des champs, dont is_pinned)
DELETE /notes/{id}
POST   /notes/{id}/attachments        (multipart: ajout d'images)
DELETE /notes/{id}/attachments/{attId}
GET    /notes/{id}/attachments/{attId} (stream authentifié)
```

Choix : création **avec ses images en un seul multipart** ; édition des champs en **PUT JSON** ; les images additionnelles passent par les routes d'attachment dédiées (on évite le multipart PUT, peu fiable côté PHP).

### Pièces jointes — stockage & sécurité

Réutilisation directe de `FileUploadService` (déjà éprouvé par le support) :

- type **réel** validé via `finfo` (jamais l'en-tête client), whitelist `image/jpeg|png|webp`, **5 Mo** max par image, **10 images** max par note ;
- nom non devinable (`bin2hex(random_bytes(16))`), base `api/storage/uploads/notes/` **hors `public/`** ;
- téléchargement **uniquement via endpoint authentifié** vérifiant la propriété (jointure `notes`), puis stream (`Content-Type` validé, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, nom sanitisé contre l'injection d'en-tête) ;
- compression côté client (`imageCompression.js`) avant upload, comme pour les tickets.

### Frontend

| Élément | Fichier |
|---|---|
| Service | `services/notebook.js` (`notesService`, `noteCategoriesService`) |
| Store Pinia | `stores/notebook.js` (catégories + notes, `pinnedNotes`) |
| Onglet | `views/NotebookView.vue` (grille de notes ; pas de titre interne — le header global suffit ; filtres par catégorie en chips et boutons d'action « Gérer les catégories » / « Ajouter une note » sur **une même ligne**) |
| Dialogue note | `components/notebook/NoteDialog.vue` (création/édition, upload multi-images) |
| Carte note | `components/notebook/NoteCard.vue` (épingle / éditer / supprimer) |
| Gestion catégories | `components/notebook/CategoryManagerDialog.vue` (CRUD inline, depuis l'onglet) |
| Image authentifiée | `components/notebook/NoteImage.vue` (object URL révoqué au démontage) |
| Widget dashboard | `components/dashboard/PinnedNotesCard.vue` |

Navigation : nouvel onglet « Carnet » (`pi pi-book`) dans `AppLayout.vue` + route `notebook` (`router/index.js`). i18n : `nav.notebook` + blocs `notebook.*` (UI) et `notes.*` / `note_categories.*` (clés d'erreur renvoyées par l'API), en `fr.json` et `en.json`.

### Dashboard — notes épinglées + réorganisation des tuiles

`PinnedNotesCard` récupère les notes épinglées (`GET /notes?pinned=1`) et les affiche en lecture seule (l'édition reste dans l'onglet Carnet) ; le widget **se masque tout seul** quand rien n'est épinglé.

L'intégration des notes a été l'occasion de réorganiser le tableau de bord (`DashboardView.vue`) en trois lignes :

- **Ligne 1** — deux tuiles : à gauche une **tuile overview** (`KpiCards.vue`) qui regroupe P&L total (héro) + taux de réussite empilés à gauche, le **doughnut win/loss** centré à droite, et les métriques secondaires (Profit Factor / R:R moyen / Total trades) en footer ; à droite le **P&L cumulé élargi** (`lg:col-span-2`).
- **Ligne 2** — la bande des notes épinglées (`PinnedNotesCard`).
- **Ligne 3** — trades récents (en cours / récents) + calendrier P&L journalier (inchangée).

Le graphe **« P&L par symbole » a été retiré du dashboard** (il reste disponible dans la vue Performance). La répartition win/loss n'a plus de tuile dédiée sur le dashboard : elle vit désormais dans la tuile overview.

## Tests

- **Backend** — unitaires services (`NoteCategoryServiceTest`, `NoteServiceTest`) : validation, propriété, détachement à la suppression de catégorie, comptage des PJ, ownership des attachments. Intégration HTTP de bout en bout (`NotebookFlowTest`) : CRUD catégories + notes, filtre épinglées, isolation entre utilisateurs, « supprimer une catégorie détache ses notes ». Suite complète : 1416 tests verts.
- **Frontend** — `notebook-store.spec.js`, `notebook-service.spec.js` (construction multipart / query string), `notebook-view.spec.js` (filtrage par catégorie, états vides). Suite complète : 377 tests verts, build de production OK.

## Limitations connues / évolutions possibles

Voir `docs/evolutions.md` : recherche plein-texte, partage, rappels/échéances, suppression physique des fichiers à la suppression d'une note (aujourd'hui soft delete → les fichiers restent, accessibles au seul propriétaire).
