# Étape 38 — Modale d'édition d'un trade : parité avec le formulaire de création

## Résumé

Bug fonctionnel : la modale "Modifier" d'un trade utilisait `PositionForm.vue` qui ne montrait que les champs de la position (entry, size, SL, BE, targets, notes). Conséquence : impossible de corriger la **date d'ouverture**, la **date de clôture** (pour un trade clôturé), ou les **champs personnalisés** après coup. Le formulaire de **création** (`TradeForm.vue`), lui, expose tous ces champs.

Cette étape unifie : la modale d'édition utilise désormais `TradeForm` en mode `edit`, qui prend tous les inputs du mode `create` et les pré-remplit depuis le trade existant.

## Fonctionnalités

### Backend — `PUT /trades/:id`

Nouvelle route ; gérée par `TradeController::update` qui délègue à `TradeService::update`. Le service orchestre, dans une seule requête HTTP :

1. **Champs de position** (`direction`, `symbol`, `entry_price`, `size`, `setup`, `sl_points`, `be_points`, `be_size`, `notes`, `targets`) → `PositionRepository::update`. Recalcul automatique de `sl_price` et `be_price` selon la direction. `setup` passe par `SetupRepository::ensureExist` pour auto-créer les nouveaux libellés.
2. **Champs trade** (`opened_at`, `closed_at`) → `TradeRepository::update` (allow-list étendue avec `opened_at`).
3. **Champs personnalisés** → `CustomFieldService::validateAndSaveValues` (remplacement complet de la liste).

Validation :
- `opened_at` : strtotime non-falsy.
- `closed_at` : ne peut être édité que si le trade a `status === CLOSED` (sinon `ValidationException trades.error.closed_at_not_closed`).
- Position fields validés en mode "partiel" : seuls les champs présents sont vérifiés (un PUT minimal sur `notes` ne demande pas direction/symbol/etc.).

### Frontend — `TradeForm` mode édition

Nouvelle prop `trade` (Object | null) :
- `null` → mode création (comportement existant).
- Object → mode édition, pré-remplit le formulaire depuis ce trade.

Comportement spécifique en édition :
- **Sélecteur de compte caché** (le compte d'un trade n'est pas modifiable ici ; `TransferDialog` reste pour ça).
- **`closed_at` visible** uniquement si `trade.status === 'CLOSED'`, en grille 2 colonnes avec `opened_at`.
- **Header** dynamique : "Modifier le trade" vs "Nouveau trade".
- **Bouton submit** : "Enregistrer" vs "Créer".
- **Custom fields** : conversion `array<{field_id,value}>` → `map<id,typed_value>` à l'ouverture (BOOLEAN → bool, NUMBER → Number, autres → string), puis re-conversion à la sauvegarde (déjà existant).

### Frontend — `TradesView` câblage

- `openEdit(trade)` fait `tradesService.get(trade.id)` (au lieu de `positionsStore.fetchPosition`) → la response inclut tous les champs nécessaires (position fields + opened_at + closed_at + custom_fields).
- `handleEditSave(data)` appelle `tradesService.update(editingTrade.id, data)` au lieu de `positionsStore.updatePosition(...)`.
- L'import `PositionForm` côté `TradesView` est retiré (le composant existe encore dans le repo mais n'est plus consommé par cette vue).

## Choix d'implémentation

### Pourquoi ré-utiliser `TradeForm` plutôt qu'enrichir `PositionForm` ?

`TradeForm` a déjà toute la logique pour `opened_at` et `custom_fields` (création). Étendre `PositionForm` aurait dupliqué cette logique. Refondre la modale d'édition autour de `TradeForm` réduit la dette : un seul composant, une seule source de vérité pour la surface "saisie d'un trade", à jour en simultané pour create et edit.

### Pourquoi un seul endpoint plutôt que deux ?

`PUT /trades/:id` orchestre côté serveur les writes sur 3 tables (`positions`, `trades`, `custom_field_values`). Frontend = 1 call, 1 toast d'erreur. Si on avait split en `PUT /positions/:id + PUT /trades/:id + PUT /trades/:id/custom-fields`, on aurait 3 chances d'échec et un état partiellement mis à jour à gérer côté SPA. L'orchestration côté service garde la cohérence.

(Note : le code n'utilise pas une transaction PDO explicite. Les 3 updates ne sont pas atomiques, mais en pratique le risque de partial-failure est faible — chaque update est validée en amont et tombe sur des contraintes stables. Tracé dans `evolutions.md` si besoin de durcir plus tard.)

### Pourquoi gater `closed_at` sur `status === CLOSED` ?

Modifier la date de clôture d'un trade qui n'est pas clôturé n'a pas de sens (et corromprait la cohérence des stats). Le statut `CLOSED` est posé par `TradeService::close` quand toutes les exits sont faites ; la date saisie ici corrige a posteriori.

### Validation partielle côté service

`TradeService::create` exige tous les champs de position (création depuis zéro). Pour update, exiger les mêmes champs forcerait le SPA à toujours envoyer le payload complet — pénible et fragile (drift entre prefill et soumission). `validatePartialPositionFields` ne valide que les champs présents dans le payload, ce qui rend le PUT vraiment partiel.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Backend — `TradeFlowTest` | 5 nouveaux : `testUpdateTradeOpenedAt`, `testUpdateTradePositionFields` (avec recompute `sl_price`), `testUpdateTradeClosedAtRequiresClosedStatus`, `testUpdateTradeRejectsCrossUserAccess`, `testUpdateTradeRequiresAuth` |
| Backend — suite globale | 1043/1043 ✓ |
| Frontend — suite globale | 191/191 ✓ |

## i18n

Nouvelles clés (fr + en) :
- `trades.edit` ("Modifier le trade" / "Edit trade")
- `trades.error.invalid_opened_at`
- `trades.error.invalid_closed_at`
- `trades.error.closed_at_not_closed`
- `trades.success.updated`

## Suite

Le composant `PositionForm.vue` n'est plus consommé par `TradesView`. Il reste sur le repo car `PositionsView` (vue agrégée des positions) pourrait encore en avoir besoin pour un futur edit. Si à terme cette vue n'expose pas d'édition, le composant peut être supprimé (tracé dans `evolutions.md` au moment où ça sera décidé).
