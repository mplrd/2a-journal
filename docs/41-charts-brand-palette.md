# Étape 41 — Sprint 2 charte : palette brand sur les charts

## Résumé

Deuxième sprint suite à l'audit UI (`docs/charte-graphique/AUDIT-UI.html` §1.2). Tous les charts de l'app étaient stylés avec des couleurs Tailwind/Material génériques (bleu `#3b82f6`, violet `#8b5cf6`, vert `#22c55e`, rouge `#ef4444`...) qui ne reliaient pas la dataviz à la marque navy/verts.

Cette étape introduit un **palette brand partagé** et l'applique aux 7 chart components + au calendar P&L. Le rendu des charts devient cohérent avec le logo, la sidebar, les CTAs.

## Fonctionnalités

### Palette partagé `frontend/src/constants/chartPalette.js`

```js
export const CHART_PALETTE = {
  primary:    '#1f2a3c',  // brand-navy-900    (séries neutres, equity, win rate)
  positive:   '#2d7952',  // brand-green-700   (gains, P&L positif)
  positiveLt: '#46926b',  // brand-green-500   (R:R, accent secondaire)
  negative:   '#b5384a',  // danger            (pertes)
  warning:    '#c98a2b',  // warning           (BE, sécurisé)
  accent:     '#9bc7af',  // brand-green-300
  neutral:    '#6b6e75',  // gray-500          (axes, grilles - via useChartOptions)
  secondary:  '#283449',  // brand-navy-800
  cream:      '#e8e8e6',  // brand-cream       (utilisé en dark pour la série primary)
}
```

Plus deux helpers :

- `withAlpha(hex, alpha)` : ajoute un canal alpha à une couleur hex (utile pour les `backgroundColor` de fill sous une `borderColor` solide).
- `primaryFor(isDark)` : retourne `cream` en dark, `primary` (navy) en light. Évite que la courbe principale se confonde avec le fond navy en dark mode.

### Charts touchés

| Composant | Avant | Après |
|-----------|-------|-------|
| `CumulativePnlChart` | bleu vif `#3b82f6` | navy en light / cream en dark |
| `EquityCurveChart` | violet `#8b5cf6` | navy en light / cream en dark |
| `PnlBySymbolChart` | green/red Tailwind | `positive`/`negative` charte |
| `WinLossChart` (donut) | green/red/amber Tailwind | `positive`/`negative`/`warning` charte |
| `RrDistributionChart` | red/yellow/green pour buckets | `negative`/`warning`/`positive` charte |
| `PerformanceView` (charts inline : `pnlBarData`, `dualMetricData`, cumulative, win/loss) | bleu+violet pour Win Rate/R:R | navy + green-500 |
| `PnlCalendar` (cellules journalières) | `bg-green-100`/`bg-red-100`/`bg-yellow-100` | tokens sémantiques `bg-success-bg`/`bg-danger-bg`/`bg-warning-bg` |

### Axes & grilles plus contrastés (dark mode)

`useChartOptions` factorise désormais ses couleurs d'axes/grilles dans deux objets `AXIS_LIGHT` / `AXIS_DARK` :

- **Light** : grid `gray-100` (warm), tick `gray-500`, legend `gray-700`
- **Dark** : grid `rgba(255,255,255,0.08)`, tick `brand-navy-300` (#93a3b9), legend `#c0c8d4`

Le dark devient lisible sans agresser les yeux : grilles très fines, ticks suffisamment lumineux pour ne pas disparaître sur surface navy.

### `doughnutChartOptions` désormais réactif

Auparavant un objet statique. Maintenant un `computed` qui suit `isDark` pour la couleur de la légende. Pas de breaking change : Vue auto-unwrap les refs dans les bindings de template (`:options="..."`).

## Choix d'implémentation

### Pourquoi `primaryFor(isDark)` plutôt qu'une variable CSS ?

Chart.js consume des hex/rgb literals ; il ne lit pas les CSS vars. On pourrait passer par `getComputedStyle(document.documentElement).getPropertyValue('--color-...')` mais ça ajoute du JS pour rien et casse en SSR. Une fonction qui retourne un literal en fonction du theme est plus simple et tracable.

### Pourquoi pas un store/composable global ?

Les charts lisent le theme au moment du `computed` (donc à chaque mutation de `isDark`). Pas besoin d'un store dédié — le composable `useChartOptions` expose déjà `isDark` qu'on peut récupérer en composant.

### Pourquoi conserver la HeatmapChart telle quelle ?

L'audit (§1.2) classe la heatmap en ✅ : ses red/green pâles fonctionnent et servent à représenter une intensité, pas une catégorie. Y toucher casserait le sens visuel sans gain.

### Pourquoi le calendar P&L passe par les classes Tailwind sémantiques au lieu de la palette JS ?

Le calendar n'utilise pas Chart.js — c'est un layout Tailwind pur (grille de divs). Dans ce contexte, des classes utilitaires (`bg-success-bg`, `text-warning`) restent plus lisibles que des inline styles avec des hex.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 191/191 ✓ |
| Build frontend | OK |

Pas de test unitaire ajouté : la palette est une constante, et les charts sont rendus côté Chart.js — pas testables proprement en vitest sans un canvas mock. Validation visuelle en local + Railway test.

## Suite (Sprint 3)

Composants identité — `feat/components-identity` :
- Setup tags taxonomie (`timeframe` / `pattern` / `context`) configurable via l'écran setups (migration `013_setups_category.sql`)
- Action icons agrandis (24px) + tooltip systématique
