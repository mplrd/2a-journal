# Étape 39 — Menu : desktop dock figé, mobile overlay généreux

## Résumé

Suite à la mise en place du dock latéral compact (étape 36), le bouton burger devenait peu utile sur desktop (le dock est déjà visible à 56 px) et l'expérience mobile restait une simple version du dock à 256 px qui n'exploitait pas l'espace écran. Cette étape :

- **masque le burger sur desktop** (le dock est définitivement compact, pas de toggle expanded ↔ compact)
- **agrandit le menu mobile** : overlay 288 px (`w-72`), boutons hauts (~56 px), icônes 2× plus grosses, labels en `text-base`

## Fonctionnalités

### Desktop

- Bouton burger : `class="md:hidden"` → invisible à partir du breakpoint Tailwind `md` (≥ 768 px).
- Sidebar : toujours en mode compact (`w-14`), pas de toggle. Hover sur les icônes → tooltip avec le label (déjà en place via `v-tooltip.right`).
- Disparition de la préférence `localStorage.sidebarExpanded` (devenue inutile).

### Mobile

- Bouton burger toujours présent dans le header.
- Overlay sidebar passe de `w-64` (256 px) à `w-72` (288 px) — un peu plus large pour accueillir des boutons plus généreux.
- Style des items en mode ouvert :
  - padding `px-4 py-4` (≈ 56 px de hauteur, conforme aux 44 px+ recommandés Apple/Google pour les zones tactiles)
  - gap `gap-4`
  - texte `text-base` (au lieu du défaut `text-sm`)
  - icône `text-2xl` (au lieu de la taille héritée)
- Backdrop tap-to-close inchangé.

### États

- `mobileOpen` : seul état restant, éphémère (pas persisté). Toggle via le burger.
- `showLabels` : computed → `isMobile && mobileOpen`. Sur desktop, toujours faux ⇒ icônes seules en permanence.

## Choix d'implémentation

### Pourquoi enlever complètement le toggle desktop ?

Le dock compact répond déjà à toutes les contraintes : reconnaissance immédiate des sections via les icônes, label disponible au survol, gain de place horizontal pour le contenu (≈ 200 px récupérés). L'expanded mode était une option de confort dont l'utilité a été remise en question — la simplicité l'emporte. Si un futur utilisateur réclame le toggle, on peut le réintroduire derrière un setting de préférences.

### Pourquoi `w-72` sur mobile et pas plein écran ?

`w-72` (288 px) couvre la grande majorité de l'écran sur les téléphones standard (320–414 px de large) tout en laissant ~30 à 130 px de backdrop visible — assez pour percevoir le mode "overlay" et tap-to-close. Plein écran cacherait totalement le contenu et ferait perdre cette affordance.

### Pourquoi `text-2xl` pour les icônes mobile ?

PrimeIcons héritent de la `font-size` du parent. À `text-base` (16 px) les icônes sont à peine lisibles. `text-2xl` (24 px) donne un visuel proche de la convention "icon-bouton" sur app native (icône ≈ 24 px pour une cible tactile de 44–56 px).

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 191/191 ✓ |
| Build frontend | OK |

Pas de tests dédiés ajoutés : la sidebar n'a pas de logique métier nouvelle, juste des classes conditionnelles. Le test `language-selector.spec.js` qui monte `AppLayout` sans router (déjà mocké via `vi.mock('vue-router')`) passe sans modification.

## Notes pour la prod

Les utilisateurs qui avaient `sidebarExpanded=true` en localStorage en garderont la valeur (jamais lue), aucune migration nécessaire. Le flag est silencieusement orphelin et sera nettoyé par le navigateur au gré du temps. Si un jour on a besoin de réutiliser la clé, on la consommera explicitement.
