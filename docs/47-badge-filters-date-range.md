# Étape 47 — Filtres en badges et sélecteur de plage de dates

## Résumé

Suivi de `docs/evolutions.md` — deux items UI/filtres traités sur une seule branche puisqu'ils relèvent du même sujet :

1. **Badges au lieu de dropdowns** sur les filtres légers (compte / statut) de Trades, Orders, Positions
2. **DateRangePicker** unique avec presets remplaçant les deux DatePicker "Du" / "Au" séparés sur DashboardFilters (utilisé aussi par PerformanceView)

## Fonctionnalités

### `<BadgeFilter>` — `frontend/src/components/common/BadgeFilter.vue`

Composant réutilisable :
- `modelValue` : single (string/number/null) ou array (multi)
- `options: [{ value, label }]`
- `multi: bool` (défaut false)
- Émets `update:modelValue` + `change` (utile pour `@change="applyFilters"`)

Style : pill `rounded-full px-3 py-1`, actif = `bg-brand-green-100 / dark:bg-brand-green-700/30 + text-brand-green-800 / brand-green-300`, inactif = neutral gray pill.

Mode single : cliquer une badge déjà active la désélectionne (revient à `null`). Convention pour gérer "Tous" comme un cas particulier au choix de l'appelant (en passant `{ value: null, label: 'Tous' }`).

Mode multi : array. Chaque clic toggle l'inclusion. Tableau vide = "pas de filtre".

### Vues consommatrices

- `TradesView` : compte (single, "Tous" inclus) + statut (multi). Les Select / MultiSelect PrimeVue sont retirés.
- `OrdersView` : compte (single) + statut (single).
- `PositionsView` : compte (single) uniquement.

Les `<label>` sont passés en `<span>` inline avec les badges sur la même row, gain visuel de hauteur.

### `<DateRangePicker>` — `frontend/src/components/common/DateRangePicker.vue`

Wrapper autour de `PrimeVue DatePicker` avec `selectionMode="range"` + `numberOfMonths=2`. Ajoute un footer de presets cliquables :

- 7 derniers jours
- 30 derniers jours
- Ce mois
- Ce trimestre
- Depuis le 1er janvier (YTD)
- Effacer

API : `v-model:from` + `v-model:to` (deux v-models séparés pour rester compatible avec les filtres existants `date_from` / `date_to` côté API).

### `DashboardFilters` (utilisé par Dashboard ET Performance)

Les deux DatePicker "Du" / "Au" sont remplacés par un seul `DateRangePicker` qui occupe `sm:col-span-2` dans la grille — gain de place et meilleure ergonomie. Le formatDate / emit `date_from` / `date_to` reste identique côté payload sortant.

## Choix d'implémentation

### Pourquoi un span inline pour le label des badges plutôt qu'un label au-dessus ?

Avec des dropdowns, le label au-dessus aérait le visuel. Avec des badges, le label inline économise de la hauteur et garde la row scannable d'un coup d'œil — `Compte : [Tous] [A] [B]`.

### Pourquoi single vs multi via une prop plutôt que deux composants ?

L'API du composant est identique (options, modelValue, emit), seul le type de modelValue change. Une prop `multi` est plus légère qu'un BadgeFilter / BadgeMultiFilter en doublon, et le store côté View définit déjà clairement si c'est un single ou multi via le typeof initial value.

### Pourquoi `v-model:from` + `v-model:to` plutôt qu'un seul `v-model="range"` array ?

Les vues parentes ont déjà `dateFrom = ref(null)` et `dateTo = ref(null)` en deux refs séparées qui sont consommées indépendamment dans la `applyFilters` et la `resetFilters`. Émettre deux v-models gardait le contrat parent inchangé. En interne le composant pilote un `range` array et synchronise.

### Pourquoi pas un composant tiers (vue-tailwind-datepicker, etc.) ?

Pas de dépendance supplémentaire à introduire pour un composant qui se monte en ~80 lignes au-dessus de PrimeVue DatePicker (déjà installé). Les presets sont la valeur ajoutée — le calendrier 2-mois sortait déjà du `numberOfMonths` natif.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 192/192 ✓ |
| Build | OK |

Pas de test dédié ajouté : composants présentationnels, l'émission `update:modelValue` est triviale. Les tests d'intégration des stores sur les views couvrent déjà l'effet utilisateur (filter changement → fetch avec params attendus).

## i18n

- `common.from`, `common.to`
- `common.range.{last_7_days,last_30_days,this_month,this_quarter,year_to_date,clear}`
- `dashboard.date_range`

## Évolutions retirées de `docs/evolutions.md`

- ~~Filtres "légers" en badges plutôt qu'en dropdowns~~ — fait
- ~~Sélecteur de plage de dates "à la Airbnb" sur Performance~~ — fait
