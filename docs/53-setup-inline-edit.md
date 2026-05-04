# Étape 53 — Édition inline du label des setups

## Résumé

L'utilisateur peut désormais renommer un setup directement dans la grid « Mes Setups » (compte utilisateur → onglet Setups), sans passer par une suppression / recréation. Le clic sur l'icône crayon transforme la cellule label en `<InputText>` avec deux boutons (✓ valider / ✗ annuler). Le bouton supprimer est désactivé pendant l'édition pour éviter une action contradictoire.

Source du besoin : retour bêta-testeur (`docs/retours-beta-tests.md` — ticket E-01).

## Comportement

### Démarrer l'édition
- Clic sur l'icône **crayon** dans la colonne d'actions de la ligne → la cellule label devient un input pré-rempli avec le label courant.
- L'input est `autofocus`. Une seule ligne peut être en cours d'édition à la fois (`editingId` = id du setup en cours).

### Valider (`saveEdit`)
- Bouton **✓** ou touche **Enter** dans l'input.
- Si label vide après trim → no-op, on reste en mode édition (pas d'appel API).
- Si label inchangé après trim → no-op, on sort simplement du mode édition (pas d'appel API).
- Si label valide et différent → `PUT /setups/{id}` avec `{ label }`. Toast succès. Sortie du mode édition.
- En cas d'erreur API (ex. doublon) → toast d'erreur traduit, on **reste** en mode édition pour permettre la correction.

### Annuler (`cancelEdit`)
- Bouton **✗** ou touche **Escape** dans l'input.
- Réinitialise `editingId = null` et `editLabel = ''`. Aucun appel API.

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

`frontend/src/__tests__/setups-tab.spec.js` — section *Inline label edit* (7 tests) couvrant : exposition des helpers, démarrage édition, annulation, sauvegarde happy-path, sauvegarde vide, sauvegarde no-op (label inchangé), conservation du mode édition sur erreur API.

## Fichiers touchés

- `api/src/Services/SetupService.php`
- `api/src/Repositories/SetupRepository.php`
- `api/tests/Unit/Services/SetupServiceTest.php`
- `api/tests/Integration/Setups/SetupFlowTest.php`
- `frontend/src/components/account/SetupsTab.vue`
- `frontend/src/__tests__/setups-tab.spec.js`

## i18n

Aucune nouvelle clé : on réutilise `common.edit`, `common.save`, `common.cancel`, `setups.placeholder`, `setups.success.updated`, et les clés d'erreur existantes (`setups.error.field_required`, `setups.error.duplicate_label`, `setups.error.label_too_long`).
