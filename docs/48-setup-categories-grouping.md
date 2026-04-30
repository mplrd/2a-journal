# Étape 48 — Coloration des setups par catégorie sur le filtre Performance

## Résumé

Sur le filtre Setups de la page Performance, les setups restent affichés sur une seule ligne (rangée plate) mais chaque badge est désormais teinté d'une couleur correspondant à sa catégorie sémantique. Le principe **fill (sélectionné) / outline (non sélectionné)** reste la signalétique principale — la couleur encode la typologie du setup, pas son état.

Pour les setups qui ne rentrent dans aucune des 3 cases existantes (Timeframe / Pattern / Contexte), ajout d'une catégorie **Non catégorisé** alimentée par une nouvelle valeur `NULL` autorisée sur la colonne `setups.category`.

## Fonctionnalités

### Catégorie nullable côté DB

Migration **014** (additive, idempotente) : `ALTER TABLE setups MODIFY COLUMN category ENUM('timeframe','pattern','context') NULL DEFAULT NULL`. Les setups existants conservent leur catégorie actuelle (`pattern` par défaut historiquement). Les nouveaux setups créés sans catégorie tombent en `NULL` (= "Non catégorisé") jusqu'à ce que l'utilisateur les classe.

### `SetupService::update`

Accepte désormais trois cas pour le patch `category` :
- une des 3 valeurs de la whitelist → enregistré tel quel
- `null` ou `''` (chaîne vide) → enregistré comme `NULL`
- toute autre valeur → `ValidationException('setups.error.invalid_category')`

### `SetupsTab.vue`

Le `<Select>` de la colonne Catégorie a une 4ème option `{ value: null, label: t('setups.category.uncategorized') }`. L'utilisateur peut donc dé-tagger un setup en le repassant à "Non catégorisé".

### Store `setups`

Nouveau computed `setupsByCategory` qui retourne :

```js
{ timeframe: [labels], pattern: [labels], context: [labels], uncategorized: [labels] }
```

Ordre figé `timeframe → pattern → context → uncategorized`. Une catégorie inconnue (au cas où la colonne enum évolue plus tard sans que le front soit mis à jour) tombe dans `uncategorized` plutôt que de disparaître.

L'ancien computed `setupOptions` (liste plate de labels) est conservé, il est encore consommé par `OrdersView`, `TradesView` et leurs dialogs.

### `BadgeFilter.vue` — palette par option

`BadgeFilter` accepte désormais un champ `color` optionnel sur chaque option (`{ value, label, color? }`). Une palette statique côté composant mappe ce token vers une paire de classes Tailwind active/inactive :

| `color` | Active | Inactive |
|---|---|---|
| `default` (fallback) | brand-green-700 | gray |
| `blue` | blue-600 | blue-300 outline + blue-700 text |
| `purple` | purple-600 | purple-300 outline + purple-700 text |
| `amber` | amber-600 | amber-300 outline + amber-700 text |
| `gray` | gray-600 | gray-300 outline (proche du default mais avec fill gris) |

Les classes sont en littéraux statiques pour que Tailwind les conserve au build (pas de class names construits dynamiquement).

### `DashboardFilters.vue` — setup row

La row Setups reste plate (une seule ligne de badges). Chaque setup est mappé vers une option `BadgeFilter` avec un `color` dérivé de sa catégorie :

- `timeframe` → `blue`
- `pattern` → `purple`
- `context` → `amber`
- `uncategorized` → `gray`

L'ordre d'affichage suit l'ordre des catégories (`timeframe → pattern → context → uncategorized`) puis l'ordre alphabétique du backend à l'intérieur de chaque catégorie. Cela regroupe visuellement les badges de même couleur sans imposer un découpage en sections séparées.

### Seeder démo

Les 5 setups de démo sont désormais répartis pour exercer les 4 buckets :
- `Breakout`, `Pullback`, `Reversal` → `pattern`
- `Range` → `context`
- `Trend Follow` → `timeframe`
- (aucun en uncategorized par défaut — l'utilisateur peut tester en passant un setup à `NULL` via la SetupsTab)

## Choix d'implémentation

### Pourquoi `NULL` plutôt qu'une 4e valeur d'enum `'other'`

`NULL` est plus juste sémantiquement : "pas encore classé" vs "classé dans la catégorie 'autre'". Si on ajoute plus tard une vraie 4e catégorie (ex. `'flow'`), on n'a pas un `'other'` orphelin à migrer — les setups en `NULL` restent simplement non catégorisés tant que l'utilisateur ne les reclasse pas. Migration également plus légère (juste rendre la colonne nullable).

### Pourquoi garder `setupOptions` en plus de `setupsByCategory`

L'ancien computed est consommé par 4 emplacements hors filtre (formulaires Trade / Order, dialogs). Refactorer ces 4 endroits pour passer à un format groupé serait du churn pour zéro bénéfice : ces UI ne montrent qu'une simple liste de labels. On garde donc les deux APIs.

### Pourquoi une seule ligne plate plutôt que des sections séparées

Avec quelques setups par catégorie (cas typique : 5 à 10 setups au total), une row par catégorie ajoute du chrome et fait grossir le bloc filtres. Une rangée plate avec couleurs par typologie reste compacte et lisible — la couleur identifie le bucket aussi vite qu'un titre, sans imposer de scrolling vertical. Les badges étant ordonnés par catégorie, les couleurs sont déjà groupées visuellement.

### Pourquoi pas de légende couleur → catégorie

Une mini-légende sous les badges a été testée puis retirée : avec un nombre limité de setups (≤ 10) et un ordre stable, les groupes de couleurs se lisent d'eux-mêmes ; la légende ajoutait du bruit visuel sans gain de compréhension. Les couleurs servent de regroupement visuel, pas d'encodage à décoder.

### Pourquoi `color` (token symbolique) sur les options BadgeFilter plutôt que des classes brutes

Faire passer des classes Tailwind en prop pousse de la connaissance front (palette) chez les consommateurs de `BadgeFilter` et casse le tree-shaking si quelqu'un construit les classes dynamiquement (`bg-${color}-600`). Un token symbolique (`'blue'`, `'purple'`, …) résolu en interne via une map statique garde les classes en littéraux et limite la palette à un set choisi.

## Couverture des tests

| Surface | Tests |
|---|---|
| Backend — `SetupServiceTest` | +2 (testUpdateAcceptsNullCategory, testUpdateTreatsEmptyStringCategoryAsNull) — 18/18 |
| Backend — suite globale | 1050/1050 ✓ |
| Frontend — suite globale | 192/192 ✓ |
| Build | OK |

## i18n

Clé ajoutée dans `fr.json` et `en.json` :

- `setups.category.uncategorized` — "Non catégorisé" / "Uncategorized"

Parité 715 / 715, 0 manquante.

## Migration à appliquer en prod

```sql
SOURCE api/database/migrations/014_setups_category_nullable.sql;
```

Idempotente : l'ALTER ne s'exécute que si la colonne est encore `NOT NULL`. Sûre à passer plusieurs fois.

## Évolutions retirées de `docs/evolutions.md`

- (rien — cette feature n'était pas explicitement listée, vient d'une demande utilisateur)
