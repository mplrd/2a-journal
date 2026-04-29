# Étape 42 — Sprint 3 charte : taxonomie des setup tags

## Résumé

Troisième sprint de l'audit UI (`docs/charte-graphique/AUDIT-UI.html` §3.2). Les tags de setup étaient tous rendus en bleu uniforme (`badge-app-blue`), ce qui rendait illisible la lecture rapide d'un trade : impossible de distinguer un timeframe (`h1`, `m5`) d'un pattern (`Demand`, `Combo`) ou d'un contexte (`Open cash`, `News`) à l'œil.

L'audit proposait soit un parsing par regex, soit un marquage manuel. On a retenu l'**option manuelle** : chaque setup porte une `category ∈ {timeframe, pattern, context}`, choisie par l'utilisateur via l'onglet **Compte → Setups**, et utilisée comme couleur sémantique partout où le tag s'affiche.

## Fonctionnalités

### Schéma & migration

```sql
-- Migration 013
ALTER TABLE setups
  ADD COLUMN category ENUM('timeframe','pattern','context')
    NOT NULL DEFAULT 'pattern'
  AFTER label;
```

Idempotent via `INFORMATION_SCHEMA`. Tous les setups existants démarrent en `pattern` — pas de régression visuelle, juste un changement de couleur après reclassification.

### API — `PUT /setups/{id}`

Nouvelle route gérée par `SetupController::update`, déléguée à `SetupService::update`. Validation server-side :
- `category` doit appartenir à l'allow-list `['timeframe', 'pattern', 'context']` sinon `ValidationException('setups.error.invalid_category')`
- Ownership check (404 si pas trouvé, 403 si autre user)
- ID > 0

`SetupRepository` :
- Toutes les `SELECT` exposent `category` (findById, findAllByUserId, findByUserAndLabel)
- Nouvelle méthode `update(id, $data)` — met à jour les colonnes whitelistées (juste `category` aujourd'hui)

### UI — Compte → Setups

`SetupsTab.vue` ajoute une **colonne « Catégorie »** avec un `<Select>` inline par row : changement persisté immédiatement via `store.updateSetup(id, { category })`. Toast de succès silencieux (2s).

### Rendu des tags

Helper partagé `frontend/src/utils/setupCategory.js` :

```js
export const SETUP_TAG_CLASSES = {
  timeframe: 'bg-brand-navy-200 dark:bg-brand-navy-700/40 text-brand-navy-900 dark:text-brand-cream',
  pattern: 'bg-brand-green-100 dark:bg-brand-green-700/25 text-brand-green-800 dark:text-brand-green-300',
  context: 'bg-warning-bg dark:bg-warning/25 text-warning dark:text-warning-bg',
}

export function useSetupCategory() {
  const store = useSetupsStore()
  function classFor(label) {
    const setup = store.setups.find((s) => s.label === label)
    return SETUP_TAG_CLASSES[setup?.category || 'pattern']
  }
  return { classFor }
}
```

Vues consommatrices :
- `TradesView` — colonne `setup` : `<Tag severity="info">` remplacé par `<span class="...rounded-full" :class="setupTagClass(s)">`
- `OrdersView` — colonne `setup` : idem

Le composable lit le `setupsStore` (déjà chargé au mount des vues) pour résoudre la catégorie. Si un label n'a pas de setup row matching (cas legacy), fallback transparent vers `pattern`.

## Choix d'implémentation

### Pourquoi un span Tailwind plutôt que `<Tag severity>` PrimeVue ?

PrimeVue `<Tag>` a un système de severity (success, info, warning, danger) dont les couleurs sont dérivées d'Aura. On ne peut pas y injecter un `bg-brand-navy-200` directement. Plutôt que de fabriquer une 4e severity (qui cascaderait dans tout le projet), un `<span>` stylé en classes Tailwind est plus direct et lisible.

### Pourquoi pas une enum côté frontend ?

L'enum sert seulement au rendu (couleur) et à l'UI du Select dans SetupsTab. Trois valeurs littérales n'ont pas besoin d'un fichier de constantes dédié. Si plus tard on rajoute des catégories (e.g. `risk`, `session`), on consolidera.

### Pourquoi le défaut 'pattern' et pas NULL ?

NULL forcerait du `?? 'pattern'` partout côté front + un check `if (...)` côté validation. Default 'pattern' simplifie : tous les setups ont une catégorie, le code n'a qu'un seul chemin.

### Pourquoi pas de batch update ?

Modifier la catégorie de plusieurs setups d'un coup (ex: tout marquer `timeframe`) serait pratique mais peu fréquent (les utilisateurs ont 8-15 setups grand max). On garde l'UI simple : un Select par row. Si demande plus tard, on étendra.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Backend — `SetupServiceTest` | 5 nouveaux : `testUpdateAcceptsAllSupportedCategories`, `testUpdateRejectsInvalidCategory`, `testUpdateThrowsWhenNotFound`, `testUpdateThrowsForbiddenWhenNotOwner`, `testUpdateThrowsWhenIdIsZero` |
| Backend — suite globale | 1048/1048 ✓ |
| Frontend — `setups-tab.spec.js` | adapté (stub Select ajouté) |
| Frontend — suite globale | 191/191 ✓ |

## i18n

Nouvelles clés (fr + en) :
- `setups.category_header` — header de la colonne dans SetupsTab
- `setups.category.{timeframe,pattern,context}` — labels des options du Select
- `setups.error.invalid_category` — message d'erreur server-side
- `setups.success.updated` — toast au changement de catégorie

## Suite

L'audit §3.3 (action icons : tooltip systématique, padding plus généreux, couleur muted+sémantique au hover) reste à attaquer. Trace dans `evolutions.md` ou pris dans Sprint 3.5 court selon la charge. Pas de blocage, c'est de la finition.
