# Étape 39 — Menu : desktop dock figé, mobile en grille de tuiles

## Résumé

Suite à la mise en place du dock latéral compact (étape 36), le bouton burger devenait peu utile sur desktop (le dock est déjà toujours visible à 56 px), et l'expérience mobile méritait un format pensé pour le tactile plutôt qu'une simple variante du dock. Cette étape :

- **Masque le burger sur desktop** : le dock est définitivement compact, sans toggle expanded ↔ compact.
- **Refond le menu mobile en drop-down full-width** sous le header, avec les sections en **tuiles carrées** (grille 3 colonnes), façon launcher d'app native.

## Fonctionnalités

### Desktop

- Bouton burger : `class="md:hidden"` → invisible à partir du breakpoint Tailwind `md` (≥ 768 px).
- Sidebar : structure dédiée (`hidden md:flex md:flex-col w-14`) qui n'existe que sur desktop, toujours en mode compact. Hover sur les icônes → tooltip avec le label (déjà en place via `v-tooltip.right`).
- Disparition de la préférence `localStorage.sidebarExpanded` (devenue inutile).

### Mobile

- Bouton burger toujours présent dans le header.
- Le menu apparaît comme un **panneau drop-down full-width** sous le header (`fixed top-[53px] left-0 right-0`), pas comme un overlay latéral. Backdrop tap-to-close.
- Les sections sont rendues en **grille `grid-cols-3 gap-3`** ; chaque tuile est `aspect-square`, contient l'icône (`text-3xl`) au-dessus du label (`text-xs`), avec une bordure et un fond léger (différent en état actif/inactif).
- Le bouton "Aller à l'admin" reste en pleine largeur sous la grille, séparé par un border-top — ce n'est pas un raccourci de navigation au même titre que les autres, son rôle visuel doit rester distinct.
- Les routes non-autorisées (onboarding) s'affichent en tuile désactivée (gris clair, non-cliquable) plutôt qu'être masquées : la cohérence visuelle de la grille est préservée.

### États

- `mobileOpen` : ref booléen, éphémère (pas persisté). Toggle via le burger ou via tap sur le backdrop. Sur desktop le burger n'existe pas, donc cette ref reste à `false` en pratique.
- `isActiveLink(to)` : exact match pour `/`, prefix match avec séparateur (`startsWith(to + '/')`) pour les autres → couvre les sous-routes futures.

## Choix d'implémentation

### Pourquoi 2 markups séparés (dock + tiles) plutôt qu'un seul avec classes conditionnelles ?

L'ancienne version pilotait l'aside avec un `:class="sidebarWidthClass"` et empilait les conditionnels showLabels partout. À chaque ajout de variante, le markup devenait de plus en plus chargé. Maintenant :
- Desktop dock = `hidden md:flex` → un markup, des classes lisibles, pas de branche.
- Mobile tiles = `md:hidden` + `v-if="mobileOpen"` → un markup, une grille fixe, pas de branche.

Coût : un peu de duplication (les liens de nav sont rendus deux fois dans le DOM, l'un caché par CSS sur l'autre breakpoint). Bénéfice : chaque mode est lu/édité indépendamment.

### Pourquoi un drop-down et pas un slide latéral ?

Le slide latéral (`w-72` qui sort de la gauche) est familier mais pénible à atteindre au pouce sur grand écran (les boutons en haut sont loin). Un drop-down depuis le header colle au mouvement naturel : pouce qui presse le burger en haut, doigt qui descend vers les tuiles.

### Pourquoi 3 colonnes ?

Avec 6 items de nav principale, 3 colonnes donnent exactement 2 rangées, équilibre visuel. 2 colonnes = 3 rangées (verticalement étiré, scroll possible sur petits écrans). 4 colonnes = tuiles étriquées sur écran 320 px. 3 est le sweet spot.

### Pourquoi `aspect-square` pour les tuiles ?

L'aspect carré donne un visuel "icône d'app" reconnaissable, et la zone tactile est généreuse (≈ 96 × 96 px sur écran 360 px) — bien au-dessus des 44 × 44 px recommandés par Apple/Google.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 191/191 ✓ |
| Build frontend | OK |

Pas de tests dédiés ajoutés : la sidebar n'a pas de logique métier nouvelle, juste deux markups conditionnels par breakpoint. Le test `language-selector.spec.js` qui monte `AppLayout` sans router (mocké via `vi.mock('vue-router')`) passe sans modification.

## Notes pour la prod

Les utilisateurs qui avaient `sidebarExpanded=true` en localStorage en garderont la valeur (jamais lue), aucune migration nécessaire. Le flag est silencieusement orphelin et sera nettoyé par le navigateur au gré du temps.
