# Page Mon compte, Dark mode & Sélecteur locale

## Vue d'ensemble

Quatre fonctionnalités liées au profil utilisateur :

1. **Page "Mon compte"** — formulaire d'édition du profil (prénom, nom, timezone, devise, thème, langue)
2. **Dark mode** — basculement clair/sombre avec persistance DB + localStorage
3. **Sélecteur locale** — dropdown avec drapeaux remplaçant les boutons FR/EN
4. **Photo de profil** — upload d'avatar avec stockage sur disque

## Endpoint `PATCH /auth/profile`

Endpoint généraliste acceptant n'importe quelle combinaison de champs du profil.

### Champs acceptés

| Champ | Type | Validation |
|-------|------|------------|
| `first_name` | string | max 100 caractères |
| `last_name` | string | max 100 caractères |
| `timezone` | string | doit être dans `DateTimeZone::listIdentifiers()` |
| `default_currency` | string | regex `^[A-Z]{3}$` |
| `theme` | string | `light` ou `dark` |
| `locale` | string | `fr` ou `en` |

### Réponses

- `200` — profil mis à jour, retourne l'utilisateur complet
- `401` — non authentifié
- `422` — validation échouée (`auth.error.invalid_timezone`, `auth.error.invalid_currency`, `auth.error.invalid_theme`, `auth.error.invalid_locale`)

### Exemple

```json
PATCH /api/auth/profile
Authorization: Bearer <token>

{ "first_name": "Jane", "theme": "dark", "locale": "en" }
```

Réponse :
```json
{
  "success": true,
  "data": {
    "id": 1,
    "email": "jane@example.com",
    "first_name": "Jane",
    "last_name": "Doe",
    "timezone": "Europe/Paris",
    "default_currency": "EUR",
    "theme": "dark",
    "locale": "en"
  }
}
```

## Composable `useTheme`

Fichier : `frontend/src/composables/useTheme.js`

### API

| Fonction | Description |
|----------|-------------|
| `initTheme()` | Lit le thème depuis le profil utilisateur, puis localStorage, puis `'light'` par défaut. Applique la classe `.dark-mode` sur `<html>`. |
| `toggleTheme()` | Inverse le thème courant, persiste dans localStorage et appelle `authStore.updateProfile()`. |
| `applyTheme(theme)` | Applique un thème spécifique (`'light'` ou `'dark'`), met à jour la classe CSS et localStorage. |
| `getCurrentTheme()` | Retourne le thème courant (`'light'` ou `'dark'`) basé sur la présence de `.dark-mode`. |

### Fonctionnement

- PrimeVue Aura est configuré avec `darkModeSelector: '.dark-mode'` → les composants PrimeVue suivent automatiquement
- Tailwind v4 utilise `@custom-variant dark (&:where(.dark-mode, .dark-mode *))` pour activer les classes `dark:`
- Le thème est initialisé depuis localStorage **avant le mount** de l'app (dans `main.js`) pour éviter le flash blanc
- Un watcher sur `authStore.user?.theme` applique le thème quand le profil est chargé

## Sélecteur locale

Le sélecteur de langue dans le header utilise un `Select` PrimeVue avec :

- Options : `🇫🇷 Français` / `🇬🇧 English`
- Template `#value` : affiche le drapeau seul (compact)
- Template `#option` : drapeau + texte

La sélection appelle `authStore.updateProfile({ locale })` pour persister en DB.

## Photo de profil

### Endpoint `POST /auth/profile-picture`

Upload d'une image de profil en `multipart/form-data`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `profile_picture` | file | Image JPEG, PNG ou WebP, max 2 Mo |

### Validation

- **Type MIME** : vérifié via `finfo` (pas le Content-Type client) — `image/jpeg`, `image/png`, `image/webp`
- **Taille** : maximum 2 Mo (2 097 152 octets)
- L'ancien fichier est automatiquement supprimé lors d'un nouvel upload

### Stockage

- Dossier : `api/public/uploads/avatars/`
- Nommage : `{userId}_{timestamp}.{ext}` (ex: `1_1709654321.jpg`)
- Servi en statique par Apache (le `.htaccess` existant route vers PHP uniquement les requêtes sans fichier correspondant)
- Le chemin relatif est stocké en DB dans `users.profile_picture` (ex: `uploads/avatars/1_1709654321.jpg`)

### Réponses

- `200` — succès, retourne le profil utilisateur complet avec `profile_picture`
- `401` — non authentifié
- `422` — `auth.error.invalid_image_type` ou `auth.error.image_too_large`

### Frontend

- Zone avatar cliquable (96px) en haut de la page "Mon compte"
- Si `profile_picture` existe → affiche l'image ; sinon → initiales sur fond bleu
- Overlay crayon au hover, spinner pendant l'upload
- Upload immédiat au choix du fichier (pas de bouton "Enregistrer")
- Preview locale via `URL.createObjectURL` avant confirmation serveur
- L'avatar du header (`AppLayout`) affiche également l'image si disponible

## Page "Mon compte"

Route : `/account` — composant `AccountView.vue`

### Champs du formulaire

- **Photo de profil** — zone avatar cliquable avec upload
- **Prénom** / **Nom** — `InputText`
- **Email** — `InputText` (lecture seule)
- **Fuseau horaire** — `Select` avec filtre, alimenté par `Intl.supportedValuesOf('timeZone')`
- **Devise par défaut** — `Select` (EUR, USD, GBP, CHF, JPY, CAD, AUD)
- **Thème** — `Select` (Clair / Sombre)
- **Langue** — `Select` (Français / English)

Le bouton "Enregistrer" appelle `authStore.updateProfile()` et applique les effets secondaires (thème CSS, changement de locale i18n). L'upload de photo est immédiat et indépendant du bouton "Enregistrer".

Accès via le menu utilisateur (Popover) dans le header → lien "Mon compte".

## Tests

### Backend (PHPUnit)

- `testUpdateProfileSuccess` — PATCH avec combinaison de champs, vérifie 200
- `testUpdateProfilePersists` — PATCH puis GET /auth/me
- `testUpdateProfilePartialUpdate` — un seul champ modifié, les autres inchangés
- `testUpdateProfileInvalidTheme` — 422
- `testUpdateProfileInvalidTimezone` — 422
- `testUpdateProfileInvalidCurrency` — 422
- `testUpdateProfileWithoutAuth` — 401
- `testUploadProfilePictureSuccess` — POST multipart JPEG, vérifie 200 + chemin dans la réponse
- `testUploadProfilePictureInvalidType` — POST avec .txt → 422
- `testUploadProfilePictureTooLarge` — POST avec fichier > 2 Mo → 422
- `testUploadProfilePictureWithoutAuth` — 401
- `testProfilePictureUrlInMe` — après upload, GET /auth/me retourne `profile_picture`

### Frontend (Vitest)

- **auth-store** : `updateProfile updates user data`, `updateProfile does not throw on failure`
- **theme** : `initTheme applies light by default`, `initTheme applies dark from localStorage`, `toggleTheme switches light to dark`, `toggleTheme switches dark to light`, `applyTheme persists to localStorage`
- **language-selector** : adapté au composant Select (dropdown drapeaux)
- **account-view** : `renders profile form fields`, `initializes form from user data`, `calls updateProfile on save`, `renders avatar with initials when no picture`, `renders avatar with image when picture exists`
