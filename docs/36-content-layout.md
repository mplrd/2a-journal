# Étape 36 — Layout : largeur contenu et sidebar compacte

## Résumé

Refonte du squelette de l'app utilisateur pour mieux exploiter la place disponible : suppression du cap `max-w-7xl` sur la zone de contenu, et passage de la sidebar à un mode dock vertical compact (icônes seules, label en tooltip au survol). Pas de modification du SPA admin dans cette itération.

## Fonctionnalités

### Zone de contenu pleine largeur

L'enveloppe `<div class="max-w-7xl w-full mx-auto">` qui plafonnait le contenu à 1280 px sur grand écran a été remplacée par `<div class="w-full">`. Sur écrans larges, les grids respirent : colonnes qui passaient sur 2 lignes (label long, badges) tiennent souvent sur une seule.

### Sidebar 2 états (desktop)

- **Compact** (par défaut, `w-14` ≈ 56 px) : seulement les icônes, label affiché en tooltip à droite au hover
- **Expanded** (`w-64` ≈ 256 px) : icônes + label, comme avant
- Toggle via le burger du header. Préférence persistée dans `localStorage` (`sidebarExpanded`)

Mobile inchangé : overlay plein-écran qui s'ouvre/ferme sur le burger, indépendant de la préférence desktop (clé `mobileOpen` éphémère).

### Indicateur de route active

Le lien correspondant à la route courante reçoit un fond vert pâle (`bg-brand-green-100` / `dark:bg-brand-green-700/20`) + texte vert foncé semibold. Détection :
- `/` : exact match uniquement
- autres : exact match OU prefix avec séparateur (`startsWith(to + '/')`) → couvre les futurs sous-routes type `/trades/123/edit`

### Centrage vertical des icônes

Les liens nav sont centrés verticalement dans la hauteur de la sidebar (façon dock latéral). Le bouton "Aller à l'admin" reste collé en bas (séparé par sa border-top).

## Choix d'implémentation

### Pourquoi 2 états indépendants pour mobile et desktop ?

L'ancienne logique avait un seul `sidebarOpen` qui se faisait écraser par le toggle mobile (qui forçait `false` quand on cliquait un lien). Résultat : passer du mobile au desktop ramenait toujours la sidebar fermée. Avec `sidebarExpanded` (desktop, persisté) et `mobileOpen` (mobile, éphémère), les deux mondes ne se polluent plus.

### Pourquoi compact = défaut ?

Le compact gagne ~200 px sur la largeur disponible. Combiné à la suppression du cap `max-w-7xl`, on libère ~25 % de largeur en plus pour le contenu sur écrans 1080p, ~40 % sur 4K. Le tooltip au survol reste rapide à consulter ; les utilisateurs qui préfèrent voir les labels en permanence togglent une fois et la préférence est mémorisée.

### Indicateur actif via path-based plutôt que `active-class`

`<RouterLink active-class>` propose un matching qui dépend du route record + des params. Pour des liens simples comme `/`, `/trades`, etc., un check `route.path === linkTo || route.path.startsWith(linkTo + '/')` est plus prévisible et plus facile à comprendre — pas de surprise sur les paths exotiques.

## Ce qui n'est PAS inclus

- **SPA admin** : la sidebar admin actuelle (`AdminLayout.vue`) garde son layout horizontal (nav inline dans le header). Pas de dock latéral pour le BO car sa surface est plus restreinte (Users, Settings).
- **Configuration utilisateur de la pagination** : tracée à part (étape suivante).
- **Trade edit form parity** : tracée à part (étape suivante).

## Couverture des tests

| Phase | Frontend tests | Build |
|-------|---------------:|:------|
| Full width content      | 191 ✓ | OK |
| Sidebar compacte        | 191 ✓ | OK |
| Centrage vertical       | 191 ✓ | OK |
| Active highlight        | 191 ✓ | OK |

Le test `language-selector.spec.js` montait `AppLayout` sans fournir de router. L'ajout de `useRoute()` dans le composant a forcé l'ajout d'un `vi.mock('vue-router')` minimal dans le test.
