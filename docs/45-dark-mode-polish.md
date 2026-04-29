# Étape 45 — Sprint 5 charte : dark mode polish

## Résumé

Cinquième et dernier sprint de l'audit UI charte (`docs/charte-graphique/AUDIT-UI.html` §4.1 + §4.5). Le dark mode est fonctionnel depuis la phase 4 du chantier charte initial (étape 35) mais l'audit pointait deux raffinements visuels :

1. **Échelle de surfaces** — body, cards, modals, hover rendaient à des niveaux de gris trop proches → hiérarchie floue
2. **Modal "Modifier"** — le modal a quasi le même fond que le body, et les inputs à l'intérieur sont *plus foncés* que le modal ⇒ effet "trou" inversé. Le bon pattern : modal lifte au-dessus du body, inputs liftent au-dessus du modal

## Fonctionnalités

### Échelle de surfaces dark (4 niveaux)

Quatre tokens ajoutés dans `@theme` :

```css
--color-surface-dark-base: #1f2a3c;  /* body / canvas (= brand-navy-900) */
--color-surface-dark-1:    #1a2435;  /* cards, table rows */
--color-surface-dark-2:    #283449;  /* nested: modals, popovers */
--color-surface-dark-3:    #354560;  /* hover state, focused row, inputs in modal */
```

Chaque niveau est **plus clair** que le précédent — c'est le pattern Material elevation : plus tu montes en hiérarchie, plus la surface "lifte" et reflète plus de lumière.

Les valeurs reprennent l'échelle navy de la charte (navy-900, surface custom, navy-800, navy-700) pour rester en cohérence avec la marque, plutôt que les `#0a0f17` … `#2a3346` du mockup audit qui partent vers le quasi-noir. Notre body est déjà brand-navy-900 (établi en étape 35), on garde.

### Override Dialog en dark

```css
.dark-mode .p-dialog {
  background-color: var(--color-surface-dark-2);
  border: 1px solid rgba(255, 255, 255, 0.1);
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.55);
}
```

- Background passe en `surface-2` (= `#283449`) → un cran au-dessus du body navy-900
- Border 10% blanc visible → contour précis
- Shadow plus marquée (55% opacity) → sensation de "lift" forte

### Inputs dans Dialog en dark

```css
.dark-mode .p-dialog .p-inputtext,
.dark-mode .p-dialog .p-inputnumber-input,
.dark-mode .p-dialog .p-textarea,
.dark-mode .p-dialog .p-select {
  background-color: var(--color-surface-dark-3);
}
```

Les inputs `surface-3` (`#354560`) restent **plus clairs** que le modal `surface-2` — ils émergent comme zones interactives. Avant, PrimeVue Aura les rendait sur sa propre surface qui finissait plus foncée que le modal sur certains écrans.

## Choix d'implémentation

### Pourquoi pas suivre exactement les valeurs `#0a0f17` … `#2a3346` du mockup audit ?

L'audit propose une échelle qui démarre quasi-noir. Notre body est `brand-navy-900` (`#1f2a3c`) — la couleur du logo, posée en étape 35 et validée. Descendre en `#0a0f17` casserait la cohérence avec le brand. L'audit le note d'ailleurs : *"avec un border 1px solid rgba(255,255,255,0.06) sur les cards, le contraste devient suffisant sans monter le niveau de gris."* On reste donc sur l'échelle navy + le trick du border.

### Pourquoi ne pas faire un sweep sur toutes les cards ?

Les cards de l'app utilisent déjà `dark:bg-gray-800` qui est mappé à `#1a2435` (= surface-1) via le `@theme` Tailwind override de l'étape 36. Pas besoin de toucher 30+ composants : la cohérence est déjà là par le mapping. Cette étape ajoute juste les niveaux 2 et 3 pour les cas où on veut explicitement un cran au-dessus.

### Pourquoi ne pas couvrir §4.4 (lien "Voir tout") ?

L'audit mentionnait un lien `Voir tout →` faible en dark. C'est un `<Button text>` PrimeVue qui pioche sa couleur dans `--p-primary-color`. Cette variable a été flippée en dark vers `brand-cream` par le preset Sprint 1 (étape 40) — le lien est donc maintenant en cream sur navy, contraste largement suffisant. Plus rien à faire.

### Pourquoi `.p-dialog .p-select` ne cible qu'un sélecteur trop large ?

PrimeVue rend `<Select>` comme un wrapper avec un `.p-select-label` à l'intérieur. Le `bg` qu'on veut changer est sur le wrapper `.p-select`. Si le label override par-dessus, on bumpera la spécificité. À tester en visuel sur les Dialogs avec Select (PositionForm, TradeForm).

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 191/191 ✓ |
| Build frontend | OK |

CSS pur, aucune logique. Validation visuelle requise sur Dialog "Modifier la position" / "Modifier le trade" / "Nouveau trade" en dark mode pour confirmer que le contraste fonctionne sur les browsers cibles.

## Fin de l'audit charte

L'audit `docs/charte-graphique/AUDIT-UI.html` est entièrement traité après cette étape :

| Sprint | Étape | Audit § | Statut |
|--------|-------|---------|--------|
| 1 | 40 | §1.1 (primary tokens), §3.1 (CTA dark), §3.4 (filtres) | ✅ |
| 2 | 41 | §1.2 (charts palette), §1.3 (numerics) | ✅ |
| 3 | 42 | §3.2 (setup tags taxonomy) | ✅ |
| 3.5 | 43 | §3.3 (action icons) | ✅ |
| 4 | 44 | §2.1 (KPI hero), §2.3 (empty states) | ✅ |
| 5 | 45 (ce doc) | §4.1, §4.5 (dark mode) | ✅ |

Items reportés dans `docs/evolutions.md` :
- §3.3 partiel — pattern "muted-rest, semantic-on-hover" pour les action icons (changement de langage visuel, demande test utilisateur)
- §2.1 partiel — delta tendance "▼ -3.5% sur la période" sous la valeur P&L hero (demande extension SQL côté `getOverview`)
