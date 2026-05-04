# Étape 54 — Paramètres de gestion du risque conditionnels (drawdowns sur compte non-PF)

## Résumé

Sur le formulaire de création/édition de compte, les champs **Drawdown max** et **Drawdown journalier** s'affichent désormais selon le type de compte :

- **Prop Firm** → champs toujours visibles, comme avant (au même titre que la phase, ils font partie du contrat de la propfirm).
- **Compte démo / personnel** → champs **masqués par défaut**. Une checkbox « Paramètres de gestion du risque » (`accounts.risk_params_toggle`) permet de les révéler. Ils restent **optionnels** : l'utilisateur les renseigne s'il veut se fixer ses propres garde-fous.

Si on charge un compte non-PF qui a déjà des valeurs DD enregistrées, le toggle est **automatiquement coché** pour conserver l'accès aux données existantes (on ne masque jamais des données déjà saisies).

Source du besoin : retour bêta-testeur (`docs/retours-beta-tests.md` — ticket E-05).

## Comportement

### Logique d'affichage

| Type de compte | Champs DD visibles ? | Toggle visible ? |
|----------------|----------------------|------------------|
| `PROP_FIRM` | Oui (toujours) | Non |
| `BROKER_DEMO` / `BROKER_LIVE` sans DD pré-existant | Non par défaut | Oui (décoché) |
| `BROKER_DEMO` / `BROKER_LIVE` avec DD déjà saisi | Oui | Oui (coché) |

### Transitions

- À l'ouverture du dialog, `showRiskParams` est calculé via `hasRiskValues(account)` → `true` ssi `max_drawdown` ou `daily_drawdown` non null.
- Quand l'utilisateur change de type vers `PROP_FIRM`, les champs s'affichent automatiquement (le `v-if` se base sur `isPropFirm || showRiskParams`). Le toggle disparaît.
- Quand l'utilisateur revient sur un type non-PF, l'état du toggle est conservé tel quel (pas de reset).

### Backend — aucun changement

`AccountService::validate` accepte déjà `max_drawdown` et `daily_drawdown` comme **optionnels** quel que soit le type de compte (cf. `api/src/Services/AccountService.php`). Aucune migration ni validation supplémentaire n'a été nécessaire pour ce ticket : le changement est purement UX.

## Tests

`frontend/src/__tests__/account-form.spec.js` — nouveau fichier de tests, suite *AccountForm — risk management fields visibility* (5 tests) :

- `hides DD fields by default for a new non-PF account (BROKER_DEMO)`
- `shows DD fields when toggle is checked on non-PF account`
- `shows DD fields directly (no toggle) on PF account`
- `auto-reveals DD fields when loading a non-PF account that already has DD set`
- `switching from non-PF to PF reveals DD fields automatically`

## Fichiers touchés

- `frontend/src/components/account/AccountForm.vue` — toggle + rendu conditionnel + `hasRiskValues()`.
- `frontend/src/__tests__/account-form.spec.js` — nouveau, 5 tests.
- `frontend/src/locales/fr.json` — clé `accounts.risk_params_toggle`.
- `frontend/src/locales/en.json` — clé `accounts.risk_params_toggle`.

## Limitations connues / hors scope

- Les champs `profit_target` et `profit_split` restent visibles pour tous les types de comptes (non couverts par ce ticket). Ils sont surtout pertinents pour les propfirms ; un nettoyage UX dédié pourrait les masquer aussi sur les comptes non-PF dans un ticket futur.
- Aucune notification/alerte n'est encore déclenchée si le DD est dépassé (cf. `docs/retours-beta-tests.md` — ticket E-08, à scoper séparément).
