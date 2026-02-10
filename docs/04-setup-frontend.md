# Étape 4 — Setup Frontend

## Résumé

Mise en place complète de l'écosystème frontend Vue 3 avec tous les outils nécessaires au développement du Trading Journal.

## Stack technique

| Outil | Version | Rôle |
|-------|---------|------|
| Vue 3 | 3.5.27 | Framework réactif (Composition API) |
| Vite | 7.3.1 | Build tool, dev server (localhost:5173) |
| Vue Router | 5.0.1 | Routing SPA |
| Pinia | 3.0.4 | State management |
| Tailwind CSS | 4.x | Styling utilitaire |
| PrimeVue | 4.x | Composants UI (thème Aura) |
| vue-i18n | 11.x | Internationalisation (fr/en) |
| Vitest | 4.0.18 | Tests unitaires |

## Structure des dossiers

```
frontend/
├── src/
│   ├── __tests__/              # Tests Vitest
│   │   ├── App.spec.js
│   │   ├── api.spec.js
│   │   └── auth-store.spec.js
│   ├── assets/
│   │   └── main.css            # Import Tailwind
│   ├── components/
│   │   ├── layout/
│   │   │   └── AppLayout.vue   # Layout principal (header, nav, main)
│   │   ├── common/
│   │   ├── account/
│   │   ├── position/
│   │   ├── trade/
│   │   ├── order/
│   │   └── stats/
│   ├── composables/
│   ├── constants/              # Enums JS (miroir backend)
│   ├── locales/
│   │   ├── fr.json             # Traductions françaises
│   │   ├── en.json             # Traductions anglaises
│   │   └── index.js            # Configuration vue-i18n
│   ├── router/
│   │   └── index.js            # Routes + navigation guard
│   ├── services/
│   │   ├── api.js              # Wrapper fetch avec JWT auto-refresh
│   │   └── auth.js             # Service auth (register, login, refresh, logout, me)
│   ├── stores/
│   │   └── auth.js             # Store Pinia auth (user, tokens, actions)
│   ├── utils/
│   ├── views/
│   │   ├── LoginView.vue       # Page de connexion
│   │   ├── RegisterView.vue    # Page d'inscription
│   │   └── DashboardView.vue   # Dashboard (placeholder)
│   ├── App.vue                 # RouterView racine
│   └── main.js                 # Point d'entrée (Vue, Pinia, Router, i18n, PrimeVue)
├── .env                        # VITE_API_URL, VITE_DEFAULT_LOCALE
├── .env.example
├── index.html
├── package.json
├── vite.config.js
└── vitest.config.js
```

## Configuration

### Variables d'environnement (`frontend/.env`)

| Variable | Valeur | Description |
|----------|--------|-------------|
| VITE_API_URL | http://journal.2ai-tools.local/api | URL de l'API backend |
| VITE_DEFAULT_LOCALE | fr | Locale par défaut |

### Vite (`vite.config.js`)

- Plugin `@vitejs/plugin-vue`
- Plugin `vite-plugin-vue-devtools`
- Plugin `@tailwindcss/vite`
- Alias `@` → `./src`

### PrimeVue

- Thème **Aura** via `@primeuix/themes`
- Composants importés à la demande (pas de plugin global)

### Internationalisation

- `vue-i18n` en mode Composition API (`legacy: false`)
- Locale par défaut : `fr`, fallback : `en`
- Clés structurées : `auth.*`, `common.*`, `nav.*`, `dashboard.*`, `error.*`
- Les clés d'erreur correspondent aux `message_key` retournées par l'API backend

## Service API (`services/api.js`)

### Fonctionnalités
- Wrapper autour de `fetch` avec méthodes `get`, `post`, `put`, `delete`
- Injection automatique du header `Authorization: Bearer <token>`
- **Auto-refresh** : sur 401 `TOKEN_EXPIRED`, tente un refresh automatique puis relance la requête
- Gestion des tokens dans `localStorage`
- Les erreurs contiennent `status`, `code`, `messageKey`, `field`

### Auth service (`services/auth.js`)
- `register(data)` — inscription (guest)
- `login(data)` — connexion (guest)
- `refresh(refreshToken)` — rotation de token (guest)
- `logout()` — déconnexion (authentifié)
- `me()` — profil (authentifié)

## Auth Store (`stores/auth.js`)

### State
- `user` — objet utilisateur ou null
- `loading` — booléen pour les actions en cours
- `error` — clé i18n de la dernière erreur

### Computed
- `isAuthenticated` — true si un access token existe
- `fullName` — prénom + nom concaténés

### Actions
- `login(data)` — appelle authService, stocke tokens + user
- `register(data)` — idem
- `logout()` — appelle authService, nettoie tout
- `fetchProfile()` — récupère le profil depuis l'API
- `initFromStorage()` — au montage, recharge le profil si token présent

## Routing

### Routes

| Path | Nom | Composant | Auth |
|------|-----|-----------|------|
| /login | login | LoginView | Guest only |
| /register | register | RegisterView | Guest only |
| / | dashboard | DashboardView (dans AppLayout) | Requis |

### Navigation guard
- Routes `meta.auth` → redirige vers `/login` si pas de token
- Routes `meta.guest` → redirige vers `/` si déjà connecté

## Pages

### LoginView
- Formulaire email + password avec PrimeVue (InputText, Password, Button)
- Affichage des erreurs i18n
- Lien vers inscription

### RegisterView
- Formulaire prénom, nom, email, password
- Affichage des erreurs i18n
- Lien vers connexion

### DashboardView
- Placeholder avec titre et message de bienvenue

### AppLayout
- Header avec nom de l'app, lien dashboard, nom utilisateur, bouton logout
- Zone de contenu avec `<RouterView />`

## Tests

**18 tests frontend** (Vitest) :
- `api.spec.js` — 7 tests (tokens, GET/POST, auth header, erreurs)
- `auth-store.spec.js` — 10 tests (login, register, logout, fetchProfile, computed)
- `App.spec.js` — 1 test (render RouterView)

**Total projet : 130 tests** (112 backend + 18 frontend)

## Commandes

```bash
# Dev server
cd frontend && npm run dev

# Build production
cd frontend && npm run build

# Tests
cd frontend && npx vitest run

# Tests backend (toujours depuis api/)
cd api && php vendor/bin/phpunit
```
