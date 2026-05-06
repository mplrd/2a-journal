# Étape 61 — Renommage d'un setup : propagation aux trades + résolution du conflit soft-delete

## Résumé

Deux bugs corrélés sur le renommage d'un setup :

1. **Renommage non propagé** : changer le label d'un setup mettait à jour la table `setups` mais pas les trades existants. Les `positions.setup` (JSON array de strings dénormalisée) gardaient l'ancien label, donc les stats par setup et l'affichage des trades historiques continuaient à montrer l'ancien nom.

2. **500 sur certains renommages** : la contrainte unique `uk_setups_user_label (user_id, label)` ne tient pas compte de `deleted_at`. Si un setup avec le label cible avait été soft-deleted dans le passé (typiquement, l'utilisateur a créé puis supprimé un setup custom du même nom), l'`UPDATE` se cognait à la contrainte → `SQLSTATE[23000] 1062 Duplicate entry` → 500 brut côté client.

Source du besoin : retour utilisateur en prod (étape 60 venait de livrer le respect du soft-delete des comptes ; cas similaire constaté sur les setups dans la foulée).

## Surface impactée

| Fichier | Méthode | Rôle |
|---|---|---|
| `api/src/Repositories/SetupRepository.php` | `findAnyByUserAndLabel` (nouveau) | Lookup par (user, label) qui inclut les soft-deleted, pour détecter le ghost qui bloque la contrainte |
| `api/src/Repositories/SetupRepository.php` | `hardDelete` (nouveau) | Suppression physique du ghost — pas d'UI pour restaurer un setup soft-deleted, donc safe |
| `api/src/Repositories/PositionRepository.php` | `renameSetupLabel` (nouveau) | UPDATE de propagation : remplace l'ancien label par le nouveau dans `positions.setup` JSON arrays |
| `api/src/Services/SetupService.php` | `update` | Orchestration : (a) si label change, hard-delete le ghost en conflit avant l'UPDATE, (b) après l'UPDATE, propage aux positions |
| `api/config/routes.php` | DI | `PositionRepository` instancié plus tôt pour être injecté dans `SetupService` |

## Décision : workaround applicatif plutôt que schema fix

Le vrai fix de fond est de remplacer la contrainte `(user_id, label)` par une contrainte qui ne s'applique qu'aux lignes actives — typiquement via colonne générée `active_label = IF(deleted_at IS NULL, label, NULL)` et `UNIQUE (user_id, active_label)` (NULLs multiples autorisés). Cette migration touche à une contrainte existante en prod, donc non strictement additive ; on la garde en backlog (`docs/evolutions.md`, section Code / conventions) pour la planifier hors urgence.

En attendant, le code applicatif détecte le conflit avec un ghost soft-deleted et le supprime physiquement avant l'UPDATE :

```php
if ($label !== $oldLabel) {
    $ghost = $this->repo->findAnyByUserAndLabel($userId, $label);
    if ($ghost && (int)$ghost['id'] !== $id && $ghost['deleted_at'] !== null) {
        $this->repo->hardDelete((int)$ghost['id']);
    }
}
```

Cohérent avec le fait qu'aucune UI n'expose les setups soft-deleted pour restauration : le ghost n'a aucune valeur fonctionnelle, on peut le supprimer sans regret.

## Propagation — détail SQL

```sql
UPDATE positions
SET setup = JSON_REPLACE(
    setup,
    REPLACE(JSON_SEARCH(setup, 'one', :old), '"', ''),
    :new
)
WHERE user_id = :user_id AND JSON_CONTAINS(setup, JSON_QUOTE(:old_match))
```

- `JSON_CONTAINS` filtre les positions qui mentionnent l'ancien label (et seulement celles-là — on ne touche pas les autres pour ne pas ticker `updated_at` inutilement)
- `JSON_SEARCH ... 'one'` retourne le path JSON de la première occurrence (ex. `"$[0]"` ou `"$[2]"`). Stripper les guillemets via `REPLACE` donne le path brut consommable par `JSON_REPLACE`
- L'UI empêche d'avoir le même setup deux fois dans la même position, donc `'one'` (vs `'all'`) suffit
- `:user_id` cloisonne le rename au seul périmètre du user qui a déclenché l'action
- Les paramètres nommés sont distincts (`:old` et `:old_match`) parce que les emulated prepares sont OFF dans ce projet — réutiliser un même placeholder est interdit

## Court-circuit "no-op"

Si le label demandé est identique à l'actuel, la propagation est court-circuitée :

```php
if ($newLabel !== null && $newLabel !== $oldLabel) {
    $this->positionRepo->renameSetupLabel($userId, $oldLabel, $newLabel);
}
```

Évite de toucher à `updated_at` sur toutes les positions concernées lors d'un PATCH `category` seul, ou d'un PUT idempotent.

## Tests

Onze tests d'intégration (TDD strict, RED puis GREEN) :

| Fichier | Test | Couvre |
|---|---|---|
| `SetupFlowTest.php` | `testUpdateSetupRenamePropagatesToPositions` | End-to-end : 3 positions, rename via PUT, vérifie que les 2 concernées ont le nouveau label et la 3e est intacte |
| `SetupFlowTest.php` | `testUpdateSetupRenameSucceedsWhenSoftDeletedConflictExists` | Régression du 500 prod : crée un ghost soft-deleted, rename → 200 + ghost hard-deleted |
| `SetupFlowTest.php` | `testUpdateSetupRenameDoesNotTouchPositionsWhenLabelUnchanged` | Vérifie que `updated_at` ne bouge pas sur un rename "vers le même label" |
| `SetupRepositoryTest.php` | 5 tests sur `findAnyByUserAndLabel` (3) et `hardDelete` (2) | Couverture unitaire des nouvelles méthodes du repo |
| `PositionRepositoryTest.php` | 3 tests sur `renameSetupLabel` | Comportement standard, no-match (rowCount=0), isolation multi-user |

Suite complète : 1126 tests passants, zéro régression.

## Bonus — détection sécurité

Le 500 d'origine remontait au client une `PDOException` non catchée mentionnant `SQLSTATE[23000]` et le nom de la contrainte (`uk_setups_user_label`). Le fix supprime ce code path, donc en plus de l'UX correcte, on évite une fuite mineure d'info schéma DB.
