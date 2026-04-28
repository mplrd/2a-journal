# Étape 35 — Charte graphique 2A Trading Tools

## Résumé

Application de la charte graphique 2A Trading Tools (référentiel `docs/charte-graphique/`) sur les deux SPAs (`frontend/` et `admin/`) sans toucher au code métier. Quatre phases découpées en quatre commits indépendants pour faciliter la relecture et le rollback partiel.

## Phases livrées

### Phase 1 — Fondations (commit `7a09eee`)

- **Tokens Tailwind v4** ajoutés via `@theme` dans `frontend/src/assets/main.css` et `admin/src/assets/main.css` :
  - Palette navy (900 à 200) et green (800 à 100) brand
  - Cream (#e8e8e6)
  - Sémantique : success / danger / warning / info (avec leurs `*-bg`)
  - Surface dark (#1a2435)
  - Polices `--font-sans` (Inter) et `--font-mono` (JetBrains Mono)
- **Google Fonts** Inter + JetBrains Mono chargées via `<link>` dans les deux `index.html`.
- **Preset PrimeVue** : `definePreset(Aura, { semantic: { primary: {…navy ramp…} } })` dans les deux `main.js`. La palette `primary` est ancrée sur `#1f2a3c` en `500` pour que les boutons par défaut, focus rings et liens PrimeVue rendent en navy.
- Titre HTML frontend simplifié à `Journal` (admin déjà à `Admin`).
- Charte HTML committée à `docs/charte-graphique/`.

### Phase 2 — Logo (commit `5cf4929`)

- **`BrandLogo.vue`** créé dans `frontend/src/components/common/` et `admin/src/components/common/` (composant identique des deux côtés). Props :
  - `size` (number/string, défaut 32)
  - `mark` (bool, défaut false) — recadre le viewBox sur le monogramme et masque la signature « TRADING TOOLS »
- Les paths navy utilisent `currentColor` → la couleur s'hérite via classe Tailwind (`text-brand-navy-900 dark:text-brand-cream`) sans dupliquer le SVG light/dark.
- **Intégration** : header `AppLayout` (frontend) et `AdminLayout` (admin), `LoginView` / `RegisterView` (frontend), `LoginView` (admin).
- **Favicon SVG** dans `frontend/public/favicon.svg` et `admin/public/favicon.svg` :
  - Recadré sur le monogramme seul (viewBox `260 290 510 360`)
  - Adaptation auto au thème système via `@media (prefers-color-scheme: dark)`
  - Le `favicon.ico` Vite par défaut a été supprimé du frontend
- `app.title` simplifié à `Journal` (fr + en).

### Phase 3 — Couleurs sémantiques & numerics (commit `3b43e24`)

Sweep mécanique des couleurs hardcodées vers les tokens brand/sémantiques. 22 fichiers touchés.

- **Avatars** (`AppLayout`, `ProfileTab`) : `bg-blue-600` → `bg-brand-navy-900`. Focus ring sur upload avatar : `focus:ring-blue-500` → `focus:ring-brand-green-500`.
- **Liens auth** (login / register / forgot / reset / verify) : `text-blue-600 dark:text-blue-400` → `text-brand-green-700 dark:text-brand-green-400`, conformément à la charte (`a { color: var(--brand-green-700); }`).
- **Banners info** (onboarding, grace period, billing) : pattern `bg-blue-50 / text-blue-800` → `bg-info-bg dark:bg-info/20 / text-info dark:text-info-bg`.
- **Numerics** (P&L, balances, KPIs) : ajout de `font-mono tabular-nums` + couleurs sémantiques `text-success` / `text-danger` :
  - `KpiCard` (générique → propage à tous les KPIs du dashboard)
  - `pnlClass` dans `TradesView`, `RecentTrades`, `KpiCards`, `StatsDetailDialog`
  - `balanceClass` et colonne `initial_capital` dans `AccountsView`
  - Stats imports (`ImportDialog`, `ImportView`)
- **Spinners de chargement** (`SubscribeSuccessView`, admin `LoginView`) : `text-blue-600` → `text-brand-navy-900 dark:text-brand-cream`.
- **Nav admin actif** : `text-blue-600` → `text-brand-green-700 dark:text-brand-green-400`.

### Phase 4 — Dark mode polish (commit `24c3a75`)

Pour éviter de balayer manuellement les ~230 occurrences de `dark:bg-gray-*` / `dark:border-gray-*` / `dark:text-gray-*` dans 35 fichiers, l'approche choisie est de **redéfinir l'échelle gray Tailwind** directement dans `@theme` :

- `--color-gray-50` → `#f7f7f5` (charte warm light)
- `--color-gray-100` → `#ececea`
- `--color-gray-200` → `#d8d8d4`
- `--color-gray-700` → `#3a3d44` (charte neutral, légèrement plus sombre)
- `--color-gray-800` → `#1a2435` (= surface-dark de la charte)
- `--color-gray-900` → `#1f2a3c` (= navy 900 de la charte)

Effet : tous les `dark:bg-gray-900` et `dark:bg-gray-800` existants rendent maintenant en navy/surface-dark sans modification de fichiers Vue. Les autres niveaux (300/400/500/600) restent neutres pour ne pas teinter les textes secondaires.

Body : `.dark-mode body` passe en `var(--color-brand-navy-900)` / `var(--color-brand-cream)` pour cohérence.

## Choix d'implémentation

### Pourquoi `currentColor` et pas deux SVG ?

La charte fournit `2a-trading-tools_logo-light.svg` (encre navy) et `..._dark.svg` (encre cream). Les deux ne diffèrent que sur la couleur des paths "encre + signature". En passant ces paths à `fill="currentColor"`, un seul composant Vue couvre les deux modes — le parent applique `text-brand-navy-900 dark:text-brand-cream`. Les deux paths verts gardent leurs couleurs hardcodées (`#2d7952` / `#46926b`) car ils sont identiques en light et dark.

### Pourquoi ancrer la `primary` PrimeVue à `500` ?

Aura (preset PrimeVue) référence `primary.500` pour le bg du bouton par défaut, `600` pour hover, `700` pour active, et les nuances `300/400` pour le dark mode. Ancrer `#1f2a3c` (navy 900 de la charte = `btn-primary`) à la position `500` fait pointer toutes ces références vers la bonne couleur sans toucher aux composants. Les nuances 50-400 et 600-950 sont des extrapolations vers blanc / noir pour rester cohérentes en hover, focus halo et dark.

### Pourquoi redéfinir l'échelle gray plutôt que sweeper ?

Le sweep manuel de 230 occurrences `dark:bg-gray-*` aurait demandé 35 fichiers de modifications, avec risque de régression sur des composants tiers (PrimeVue) qui consomment indirectement ces classes. Tailwind v4 permet de redéfinir un token au niveau `@theme` et toutes les utilities déjà compilées le suivent automatiquement.

Limite acceptée : `text-gray-700` est utilisé pour le body en light mode → en passant `--color-gray-700` à `#3a3d44` (charte neutral), le texte reste neutre. Les bordures dark `dark:border-gray-700` héritent du même `#3a3d44` au lieu d'un navy-700 — visuellement très proche et acceptable.

### Pourquoi le titre "Journal" sans suffixe brand ?

Demandé explicitement par le owner pour la cohérence avec "Admin" (déjà simplifié). Le logo véhicule la marque ; le titre identifie le SPA.

## Ce qui n'est PAS inclus dans cette feature

- **Migration des icônes** (PrimeIcons → Lucide / Phosphor) : reportée à une feature à part. Le coût (~80 fichiers à toucher) excède le bénéfice UX direct dans cette itération. Tracée dans `docs/evolutions.md`.
- **Composants graphiques (Chart.js)** : les couleurs des séries de charts ne sont pas encore alignées sur la séquence charte (`#2d7952, #2c6e8f, #c98a2b, #45587a, #9bc7af, #b5384a`). À faire dans une passe data-viz dédiée.
- **HeatmapChart Europe / EUROPE series** : conservé en blue-100/700 — couleur de série data-viz, pas de marque.

## Couverture des tests

| Phase | Frontend tests | Admin tests | Build frontend | Build admin |
|-------|---------------:|------------:|:---------------|:------------|
| 1     | 191 ✓          | 11 ✓        | OK             | OK          |
| 2     | 191 ✓          | 11 ✓        | OK             | OK          |
| 3     | 191 ✓          | 11 ✓        | OK             | OK          |
| 4     | 191 ✓          | 11 ✓        | OK             | OK          |

Aucun test n'a dû être adapté : la charte ne change pas le comportement, juste le rendu visuel. Les sélecteurs de tests existants ciblent des `data-testid` ou des éléments structurels, pas des classes CSS.

## Référentiel

`docs/charte-graphique/charte-graphique.html` reste la source de vérité visuelle (palette interactive, do/don't, dark mode preview, design tokens prêts à coller). Tokens repris dans `frontend/src/assets/main.css` et `admin/src/assets/main.css`.
