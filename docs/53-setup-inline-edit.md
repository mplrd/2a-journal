# Étape 53 — Édition inline d'un setup (label + catégorie)

## Résumé

L'utilisateur peut désormais modifier un setup directement dans la grid « Mes Setups » (compte utilisateur → onglet Setups), sans passer par une suppression / recréation. Le clic sur l'icône crayon bascule la ligne en mode édition : la cellule label devient un `<InputText>` et la cellule catégorie devient un `<Select>`. Un seul bouton ✓ enregistre les modifications (label et/ou catégorie), un bouton ✗ annule. Le bouton supprimer est désactivé pendant l'édition pour éviter une action contradictoire.

En lecture seule, la catégorie est désormais affichée comme un libellé traduit (« Pattern », « Contexte », « Non catégorisé »…) — l'ancien `<Select>` toujours actif a été retiré pour unifier l'expérience de modification derrière le bouton crayon.

Source du besoin : retour bêta-testeur (`docs/retours-beta-tests.md` — ticket E-01).

## Comportement

### Démarrer l'édition
- Clic sur l'icône **crayon** dans la colonne d'actions de la ligne → la cellule label devient un input pré-rempli avec le label courant, et la cellule catégorie devient un `<Select>` pré-rempli avec la catégorie courante (`pattern`, `timeframe`, `context` ou `null` = Non catégorisé).
- L'input label est `autofocus`. Une seule ligne peut être en cours d'édition à la fois (`editingId` = id du setup en cours).

### Valider (`saveEdit`)
- Bouton **✓** ou touche **Enter** dans l'input label.
- Construit un patch contenant uniquement les champs **réellement** modifiés (`label`, `category`, ou les deux).
- Si label vide après trim → no-op, on reste en mode édition (pas d'appel API).
- Si rien n'a changé (ni label ni catégorie) → no-op, on sort simplement du mode édition (pas d'appel API).
- Sinon → `PUT /setups/{id}` avec le patch minimal. Toast succès. Sortie du mode édition.
- En cas d'erreur API (ex. doublon de label) → toast d'erreur traduit, on **reste** en mode édition pour permettre la correction.

### Annuler (`cancelEdit`)
- Bouton **✗** ou touche **Escape** dans l'input label.
- Réinitialise `editingId = null`, `editLabel = ''`, `editCategory = null`. Aucun appel API.

## Backend

### `SetupService::update`

Accepte désormais le champ `label` dans le payload (en plus de `category`) :

```php
$service->update($userId, $id, ['label' => 'Renamed']);
$service->update($userId, $id, ['label' => 'Renamed', 'category' => 'context']);
```

Validations :
- Label trim → si vide → `ValidationException('setups.error.field_required', 'label')`.
- Longueur > 100 → `ValidationException('setups.error.label_too_long', 'label')`.
- Doublon **autre setup** du même user → `ValidationException('setups.error.duplicate_label', 'label')`.
- Renommage **vers le même label** (même id) → autorisé (no-op DB-side, l'UPDATE écrit la même valeur).

### `SetupRepository::update`

`UPDATE setups SET label = :label, category = :category WHERE id = :id AND deleted_at IS NULL`. Les deux champs sont optionnels et indépendants côté patch.

## Tests

### Backend

`api/tests/Unit/Services/SetupServiceTest.php` — section *Update label (inline edit)* :
- `testUpdateAcceptsLabelChange`
- `testUpdateTrimsLabel`
- `testUpdateRejectsEmptyLabel`
- `testUpdateRejectsLabelTooLong`
- `testUpdateRejectsDuplicateLabelFromAnotherSetup`
- `testUpdateAllowsRenameToSameLabel`
- `testUpdateAcceptsLabelAndCategoryTogether`

`api/tests/Integration/Setups/SetupFlowTest.php` — flow HTTP bout-en-bout :
- `testUpdateSetupLabelSuccess`
- `testUpdateSetupLabelRejectsDuplicate`
- `testUpdateSetupLabelRejectsEmpty`

### Frontend

`frontend/src/__tests__/setups-tab.spec.js` — section *Inline label edit* (11 tests) couvrant : exposition des helpers, démarrage édition (label + catégorie, normalisation `null`), annulation, sauvegarde happy-path label, patch catégorie seule, patch label + catégorie, reset catégorie à `null` (uncategorized), sauvegarde vide, sauvegarde no-op (rien n'a changé), conservation du mode édition sur erreur API.

## Fichiers touchés

- `api/src/Services/SetupService.php`
- `api/src/Repositories/SetupRepository.php`
- `api/tests/Unit/Services/SetupServiceTest.php`
- `api/tests/Integration/Setups/SetupFlowTest.php`
- `frontend/src/components/account/SetupsTab.vue`
- `frontend/src/__tests__/setups-tab.spec.js`

## i18n

Aucune nouvelle clé : on réutilise `common.edit`, `common.save`, `common.cancel`, `setups.placeholder`, `setups.success.updated`, et les clés d'erreur existantes (`setups.error.field_required`, `setups.error.duplicate_label`, `setups.error.label_too_long`).
