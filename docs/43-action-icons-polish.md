# Étape 43 — Sprint 3.5 charte : action icons polish

## Résumé

Petit sprint follow-up à l'audit UI (`docs/charte-graphique/AUDIT-UI.html` §3.3). Les boutons d'action des grilles (edit / delete / share / transfer / close / next-objective…) avaient deux faiblesses identifiées :

1. **Trop petits** pour le tactile — `size="small"` PrimeVue rend ~28 px alors que la cible recommandée Apple/Google est 32–44 px.
2. **Edit (severity="secondary") invisible en dark mode** — le token Aura secondary se confond avec la surface navy.

Fix uniformisé via main.css plutôt qu'un sweep de 12+ Button à travers TradesView, OrdersView, AccountsView, SymbolsView.

## Fonctionnalités

### 32 × 32 par défaut sur les actions de grille

```css
.p-datatable .p-button.p-button-icon-only {
  min-width: 2rem;
  min-height: 2rem;
}
```

Selector ciblé (cellule de DataTable + button icon-only) → ne touche pas les boutons full-label ailleurs (footer de Dialog, headers, etc.). Les `size="small"` existants gardent leur padding mais s'assurent désormais d'un floor à 32 px.

### Secondary text-only visible en dark

```css
.dark-mode .p-button-secondary.p-button-text {
  color: var(--color-brand-navy-300);
}
.dark-mode .p-button-secondary.p-button-text:hover:not(:disabled) {
  color: var(--color-brand-cream);
  background-color: rgba(255, 255, 255, 0.06);
}
```

`brand-navy-300` (#93a3b9) au repos = lisible sur surface navy sans crier. Hover passe en `brand-cream` + petit fond blanc-alpha pour signaler l'interactivité.

## Choix d'implémentation

### Pourquoi pas un sweep par composant ?

12+ Button répartis dans 4 vues. Un sweep imposerait soit un wrapper component (`<ActionButton>`), soit la duplication de la même config sur chaque ligne. Les deux options sont plus lourdes que 19 lignes de CSS scopées.

### Pourquoi conserver `size="small"` au lieu de l'enlever ?

Sans `size="small"`, PrimeVue rend ~36 px de hauteur — trop massif dans une cellule de DataTable au milieu de texte 14 px. La règle `min-width/height: 2rem` agrandit juste assez quand `size="small"` est trop petit, sans casser le visuel.

### Pourquoi pas la recommandation "tout muted au repos, sémantique au hover" de l'audit ?

L'audit suggère un pattern où toutes les icônes sont gris-muted au repos et ne révèlent leur sémantique (red pour delete, etc.) qu'au hover. C'est élégant mais c'est un changement plus profond du langage visuel : aujourd'hui les utilisateurs scannent par couleur (rouge = delete) sans hover. Inverser ça demande un test utilisateur. Reporté dans `evolutions.md` si on veut creuser.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 191/191 ✓ |
| Build frontend | OK |

CSS pur, aucune logique. Pas de test ajouté.
