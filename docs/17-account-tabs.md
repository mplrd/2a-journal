# 17 - Page Mon Compte à onglets

## Fonctionnalités

Regroupement de toutes les préférences utilisateur dans une page unique à onglets accessibles via le menu avatar (popover utilisateur).

### Onglet Profil
- Formulaire existant : avatar, prénom, nom, email (lecture seule), fuseau horaire, devise, thème, langue
- Inchangé par rapport à l'implémentation précédente

### Onglet Mes Actifs
- Contenu déplacé depuis l'ancienne page SymbolsView
- DataTable CRUD complet : code, nom, type (badge coloré), valeur du point, devise
- Création/édition via SymbolForm (dialog modal)
- Suppression avec confirmation
- Bandeau d'onboarding conservé (bouton "Commencer à suivre mes performances")

### Onglet Mes Setups
- **Nouveau** : gestion des setups de trading (labels)
- DataTable avec colonnes : Label, Actions (supprimer)
- Ajout inline : bouton "Ajouter" → champ texte + confirmation (Enter/bouton)
- Suppression avec dialog de confirmation
- Utilise le store Pinia existant (`setups.js`) et les endpoints GET/POST/DELETE `/setups`

### Navigation
- Entrée "Symbols" retirée de la sidebar
- Route `/symbols` supprimée du routeur
- Onboarding : l'étape "symbols" redirige désormais vers `/account?tab=assets`
- Support du query param `?tab=profile|assets|setups` pour ouvrir directement un onglet

## Choix d'implémentation

### TabView PrimeVue avec `value`
Utilisation de TabPanel avec prop `value` (string) plutôt que `activeIndex` (number) pour un routing par query param plus lisible (`?tab=assets` plutôt que `?tab=1`).

### Extraction en composants
Le contenu de chaque onglet est un composant indépendant (`ProfileTab`, `AssetsTab`, `SetupsTab`) pour maintenir la lisibilité et la testabilité. L'`AccountView` ne fait que composer les trois.

### Pas de backend
Aucun nouveau endpoint — les CRUD setups (GET/POST/DELETE `/setups`) et symbols existaient déjà. Seul le frontend a été restructuré.

### Onboarding
L'étape "symbols" redirige vers `/account?tab=assets` au lieu de `/symbols`. Les routes autorisées pendant l'onboarding ont été mises à jour (`STEP_ROUTES`).

## Couverture des tests

### Frontend (Vitest)

| Fichier | Tests | Scénarios |
|---------|-------|-----------|
| `setups-tab.spec.js` | 9 | empty state, DataTable rendu, bouton ajout, input affiché au clic, createSetup appelé, input vidé après création, label vide ignoré, dialog de suppression, fetchSetups au mount |
| `account-view.spec.js` | 6 | TabView avec 3 panels, titre, tab profil par défaut, query `?tab=assets`, query `?tab=setups`, query inconnu → profil |
| `onboarding.spec.js` | 12 | isOnboarding (3), currentStep (3), isRouteAllowed (4 dont mis à jour), onboardingRoute redirige vers account, completeOnboarding |

### Backend (PHPUnit)

Aucun changement — les 566 tests existants passent toujours.
