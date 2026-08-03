# 27 — Sécurité du profil (changement mot de passe + suppression compte)

> **MAJ 2026-05-04** : la "Zone dangereuse" initiale regroupait deux actions de nature très différente — changement de mot de passe (banal) et suppression de compte (irréversible). Le terme « zone dangereuse » était trop anxiogène pour le mot de passe (cf. ticket D-02 dans `docs/retours-beta-tests.md`). Le bloc a été **scindé en deux sections distinctes** :
> - **Sécurité** (`SecuritySection.vue`) — neutre (bordure grise), contient le changement de mot de passe.
> - **Zone dangereuse** (`DangerZone.vue`) — rouge, contient uniquement la suppression de compte.

> **MAJ 2026-08-03** (ticket support #12) : le terme « zone dangereuse » restait mal perçu sur un produit fini, même une fois réduit à la seule suppression de compte. La **charge d'alerte a été déplacée du titre vers l'avertissement** :
> - Le titre devient neutre — **« Compte et données »** / *Account & data*, en gris.
> - L'irréversibilité, jusqu'ici noyée en fin de description en petit gris, devient un **avertissement dédié** : « ACTION IRRÉVERSIBLE » en rouge, gras, `text-base`, précédé d'une icône d'alerte.
> - La bordure rouge du bloc et le bouton `severity="danger"` sont **conservés** : l'alerte visuelle reste, seul le mot disparaît.
> - Renommage complet pour aligner technique et UI : `DangerZone.vue` → **`AccountDataSection.vue`**, `account.danger_zone.*` → **`account.account_data.*`**, `data-testid="danger-zone-*"` → `account-data-*`, `danger-zone.spec.js` → `account-data.spec.js`.

## Contexte

Le profil utilisateur permettait seulement de modifier des champs d'affichage (nom, photo) et des préférences (langue, thème, seuil BE). Deux actions critiques manquaient :

- **Changer son mot de passe** quand on est connecté (aujourd'hui seul le flux "mot de passe oublié" par email existe).
- **Supprimer son compte** (aucun endpoint, la colonne `users.deleted_at` existe mais n'est jamais écrite).

On les expose en bas de l'onglet Profil dans deux sections distinctes (cf. encart en haut de doc) : actions irréversibles protégées par double confirmation.

## Scope

### Dans le scope

- Endpoint `POST /auth/change-password` (authentifié).
- Endpoint `DELETE /auth/me` (authentifié).
- Composant `AccountDataSection.vue` intégré au bas de `ProfileTab.vue`, avec deux dialogs de confirmation.
- i18n FR + EN.
- Tests unit + integration.

### Hors scope

- **Export des données avant suppression** (RGPD) : out of scope, sujet à part.
- **Hard delete** ou delete différé (ex. "purge après 30 jours") : soft delete uniquement pour cette itération.
- **2FA** ou re-authentification plus forte (email de confirmation pour la suppression) : non, on se contente du pattern "tape ton email + mot de passe".
- **Restauration de compte** depuis l'interface : pas prévue. Un compte supprimé reste en base (soft delete) mais ne peut plus se reconnecter. Restauration = intervention DB manuelle.

## Conception

### Changement de mot de passe

**Endpoint** : `POST /auth/change-password`

Body :
```json
{ "current_password": "...", "new_password": "..." }
```

Logique `AuthService::changePassword(int $userId, array $data)` :
1. Valider que `current_password` et `new_password` sont présents.
2. Vérifier les règles de force du nouveau mot de passe (même `validatePassword()` que `register`/`resetPassword` : 8+ chars, maj/min/chiffre, ≤ 72 chars).
3. Charger le user via `findById`, puis charger le hash actuel via `findByEmail` (le hash n'est pas retourné par `findById`).
4. `password_verify($data['current_password'], $user['password'])` → sinon `UnauthorizedException('auth.error.invalid_current_password')`.
5. `password_hash` du nouveau + `updatePassword($userId, $hash)`.
6. **Ne pas** invalider les refresh tokens (décision : la session courante reste active, l'utilisateur vient de prouver son identité). Contrairement à `resetPassword` qui elle révoque tout (car token email = preuve plus faible).

Réponse : `{ success: true, data: { message_key: 'auth.success.password_changed' } }`.

### Suppression de compte

**Endpoint** : `DELETE /auth/me`

Body (POST-like, via JSON) :
```json
{ "password": "...", "email_confirmation": "..." }
```

Logique `AuthService::deleteAccount(int $userId, array $data)` :
1. Charger le user (`findById`).
2. Vérifier que `email_confirmation` === `user.email` → sinon `ValidationException('auth.error.email_confirmation_mismatch', 'email_confirmation')`.
3. Charger le hash via `findByEmail`, vérifier `password_verify` → sinon `UnauthorizedException('auth.error.invalid_credentials')`.
4. `UserRepository::softDelete($userId)` : `UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id`.
5. Invalider tous les refresh tokens (`tokenRepo->deleteAllByUserId`).
6. Renvoyer un cookie de clear.

Réponse : `{ success: true, data: { message_key: 'auth.success.account_deleted' } }` + `Set-Cookie` clear.

**Effet côté données** : toutes les relations FK vers `users` sont `ON DELETE CASCADE`, mais comme on fait un soft delete rien n'est supprimé en cascade. Les données restent pour :
- permettre une éventuelle récupération manuelle,
- ne pas casser l'historique partagé (ex. un trade partagé via un lien public doit rester accessible).

Les requêtes `findByEmail` et `findById` filtrent déjà par `deleted_at IS NULL` → le user ne peut plus se connecter ni être retrouvé.

### UI

**Où** : en bas de `ProfileTab.vue`, **deux sections superposées** depuis le split D-02 :
- `SecuritySection.vue` — bordure neutre (`border-gray-200`), titre "Sécurité", bouton outlined non-rouge pour le changement de mot de passe.
- `AccountDataSection.vue` — bordure rouge (`border-red-300`), titre neutre "Compte et données", avertissement « ACTION IRRÉVERSIBLE » en rouge gras, bouton rouge (`severity="danger"`) pour la suppression du compte.

Hiérarchie de lecture dans la section rouge (post-#12) :

```
┌── border-red-300, bg-red-50/30 ─────────────────────────────────────────┐
│  Compte et données            (gris, semibold)                           │
│                                                                           │
│  Supprimer mon compte      ⚠ ACTION IRRÉVERSIBLE   [Supprimer mon compte] │
│  Supprime définitivement…     (rouge, bold, base)      (bouton rouge)     │
│  (titre + sous-titre)                                                     │
└───────────────────────────────────────────────────────────────────────────┘
```

La ligne d'action (`data-testid="account-data-row"`) est un `flex` à **trois enfants directs** : le bloc titre + sous-titre à gauche, l'avertissement au milieu, le bouton à droite (`sm:justify-between`). En dessous de `sm`, les trois s'empilent. Un test verrouille cette structure (nombre et ordre des enfants) pour éviter que l'avertissement ne retombe dans la colonne de gauche.

**Dialog changement mot de passe** (`ChangePasswordDialog.vue`) :
- 3 champs : mot de passe actuel, nouveau, confirmation.
- Validation client : nouveau ≠ actuel (soft warning), confirmation = nouveau, règles de force affichées.
- Submit → appel `authStore.changePassword({ current_password, new_password })`.
- Erreur serveur : afficher `messageKey` via toast.
- Succès : toast + fermeture dialog.

**Dialog suppression** (`DeleteAccountDialog.vue`) :
- Texte d'avertissement clair (FR/EN).
- Champ "Tape ton email pour confirmer" : doit être strictement égal à l'email du user (vérif côté front pour désactiver le bouton, vérif côté back en plus).
- Champ mot de passe.
- Bouton "Supprimer" désactivé tant que l'email saisi ≠ email user ET mot de passe non saisi.
- Submit → `authStore.deleteAccount({ password, email_confirmation })`.
- Succès : toast + `authStore.logout()` local + redirect `/login`.

### i18n (clés ajoutées)

Namespace `account.security.*` (post-split D-02) pour la section neutre :
- `title`, `change_password`, `change_password_description`

Namespace `account.account_data.*` pour la section rouge (renommé depuis `account.danger_zone.*` en 2026-08-03) :
- `title`, `irreversible_warning`, `delete_account`, `delete_account_description`

Namespace `account.change_password.*` pour le dialog :
- `current_password`, `new_password`, `confirm_password`, `submit`, `success`

Namespace `account.delete_account.*` pour le dialog :
- `title`, `warning_title`, `warning_line1`, `warning_line2`, `type_email`, `password`, `confirm_button`, `email_mismatch`

Namespace `auth.error.*` :
- `invalid_current_password`, `email_confirmation_mismatch`

Namespace `auth.success.*` :
- `password_changed`, `account_deleted`

## TDD

### Backend

**`AuthServiceTest`** :
- `changePassword` throws if current_password missing / new_password missing / new too weak / current wrong.
- `changePassword` succeeds : user hash updated, refresh tokens **not** invalidated.
- `deleteAccount` throws if email mismatch / password wrong.
- `deleteAccount` succeeds : repo.softDelete called, all refresh tokens deleted.

**`AuthFlowTest` (integration)** :
- `POST /auth/change-password` : 401 sans JWT, 422 avec current wrong, 200 avec current OK.
- Après changement, login avec nouveau mot de passe OK, avec ancien KO.
- `DELETE /auth/me` : 401 sans JWT, 422 avec email mismatch, 401 avec password wrong, 200 avec les deux OK.
- Après suppression, login KO avec les mêmes credentials.

**`UserRepositoryTest`** :
- `softDelete` écrit `deleted_at` ; `findById` / `findByEmail` retournent null ensuite.

### Frontend

**`account-data.spec.js`** (ex-`danger-zone.spec.js`) :
- Chaque section rend son seul bouton (`SecuritySection` → mot de passe, `AccountDataSection` → suppression).
- Clic sur "Changer mot de passe" ouvre le dialog.
- Clic sur "Supprimer le compte" ouvre le dialog.
- La section compte garde une bordure rouge, la section sécurité non.
- Le titre est neutre en FR **et** en EN, sans occurrence de "danger", et n'est pas rouge.
- L'avertissement d'irréversibilité existe, porte les classes `text-base font-bold` + rouge, et la description courte ne répète plus "irréversible".

**`change-password-dialog.spec.js`** :
- Submit envoie les bons champs via `authStore.changePassword`.
- Confirmation ≠ nouveau → erreur affichée.

**`delete-account-dialog.spec.js`** :
- Bouton désactivé tant qu'email ≠ email user.
- Submit appelle `authStore.deleteAccount` avec email et password.

## Livrables

- `api/src/Services/AuthService.php` : `changePassword`, `deleteAccount`, validations.
- `api/src/Repositories/UserRepository.php` : `softDelete`.
- `api/src/Controllers/AuthController.php` : 2 actions.
- `api/config/routes.php` : `POST /auth/change-password`, `DELETE /auth/me` (tous deux protégés par `authMiddleware`).
- `frontend/src/services/auth.js` + `frontend/src/stores/auth.js` : 2 méthodes.
- `frontend/src/components/account/AccountDataSection.vue` (créé sous le nom `DangerZone.vue` ; allégé en 2026-05-04 pour ne contenir que la suppression compte cf. split D-02, puis renommé et retitré en 2026-08-03 cf. ticket #12).
- `frontend/src/components/account/SecuritySection.vue` (ajouté en 2026-05-04, hébergeant le changement de mot de passe).
- `frontend/src/components/account/ChangePasswordDialog.vue` (nouveau).
- `frontend/src/components/account/DeleteAccountDialog.vue` (nouveau).
- `frontend/src/components/account/ProfileTab.vue` : intègre `<AccountDataSection />` en bas.
- i18n fr + en : clés ajoutées.
- Tests backend (Unit + Integration) + frontend (Vitest).
