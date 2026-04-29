# Étape 40 — Sprint 1 charte : dark mode CTA, filtres, numerics

## Résumé

Premier sprint de suite à l'audit UI du 28 avril 2026 (`docs/charte-graphique/AUDIT-UI.html`). Trois corrections ciblées pour aligner l'app sur la charte sans toucher à la fonctionnalité :

1. **CTA dark mode** : les boutons primaires en mode sombre rendaient sur `#45587a` (navy désaturé d'Aura) au lieu d'un brand visible. Fix → primary "flippe" en cream sur navy.
2. **Filtres tronqués** : "Filtrer par com..." dans Trades / Orders / Positions remplacé par label séparé au-dessus + Select élargi.
3. **Numerics filet de sécurité** : règle CSS globale sur `.num` / `.numeric` / `.tabular` pour qu'aucune colonne chiffrée ne saute si on a oublié `font-mono tabular-nums` quelque part.

## Fonctionnalités

### CTA dark mode (#1 audit)

Override du `colorScheme.dark.primary` dans le preset PrimeVue (`frontend/src/main.js` + `admin/src/main.js`) :

```js
colorScheme: {
  dark: {
    primary: {
      color: '#e8e8e6',          // brand-cream
      contrastColor: '#1f2a3c',  // brand-navy-900
      hoverColor: '#d8d8d4',
      activeColor: '#c9d1de',
    },
  },
}
```

Effet : en dark mode, tous les boutons "Nouveau X", focus rings et liens primaires PrimeVue passent en **cream sur navy** au lieu de navy désaturé sur navy. La règle d'inversion (cf. audit §3.1) garantit le contraste tout en restant brand.

Le mode light reste inchangé : `primary.500 = #1f2a3c` (navy 900) côté Aura → tout pinte en navy.

### Filtres avec label séparé (#9 audit)

Pattern uniformisé sur les 3 vues paginées :

```html
<div>
  <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('xxx.account') }}</label>
  <Select v-model="filterAccountId" ... class="w-56" />
</div>
```

- Width passe de `w-48` (192 px) à `w-56` (224 px)
- Plus de placeholder long ("Filtrer par compte") qui se faisait crop par le chevron — le label hors champ porte cette information
- Touche `TradesView`, `OrdersView`, `PositionsView`

### Numerics filet de sécurité

Nouveau bloc dans `main.css` :

```css
table .num,
.numeric,
.tabular {
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
}
```

Filet pour les cellules numériques qui auraient pu manquer le mono+tabular au cas par cas. Les composants existants (KpiCard, pnlClass via Tailwind) gardent leur application explicite ; cette règle est juste là pour ne plus avoir à y penser.

## Choix d'implémentation

### Pourquoi ne pas re-déclarer la chaîne `--p-primary-*` côté CSS ?

L'audit propose un drop-in `:root { --p-primary-color: var(--color-brand-navy-900); ... }` à coller dans le theme override. C'est valide mais ça écrase Aura à 2 endroits : `definePreset` (côté JS) ET `:root` (côté CSS). Risque de drift.

`definePreset(Aura, { semantic: { primary: { ... }, colorScheme: { dark: { primary: { ... } } } } })` est la voie API officielle PrimeVue : un seul endroit où la règle vit, Aura recompile tous ses tokens dérivés (hover, focus, active, ring, …) cohéremment.

### Pourquoi `w-56` plutôt que `w-48` + width fix sur le placeholder ?

Le label hors champ gagne sur les deux axes : l'affordance "à quoi sert ce filtre ?" est claire en permanence (pas seulement quand le placeholder n'est pas écrasé par une valeur), et le Select n'a plus à porter une longue explication dans un espace contraint. `w-56` reste sobre et tient sur tous les viewports normaux.

### Pourquoi un filet `.numeric` plutôt qu'un sweep manuel ?

Le sweep est en cours (KpiCard, pnlClass, formatSize ont déjà été migrés). Mais il reste des cellules de tableau (notamment celles avec `class="num"` dans certains DataTables PrimeVue) où la règle peut manquer. Une règle CSS globale sur ces sélecteurs neutralise la dette accumulée + future, sans rien casser puisque `font-variant-numeric: tabular-nums` est purement visuel.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 191/191 ✓ |
| Admin — suite globale | 11/11 ✓ |
| Build frontend & admin | OK |

Pas de tests dédiés ajoutés : pas de logique métier touchée. Le preset PrimeVue est purement déclaratif, les filtres ont gardé leur même `v-model`/`@change`.

## Suite (Sprint 2)

Charts repaint — `feat/charts-brand-palette` : palette navy/verts/danger/warning définie dans un fichier partagé, application aux 6+ composants charts (cf. audit §1.2).
