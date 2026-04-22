# Onboarding utilisateur

## Vue d'ensemble

L'onboarding guide les nouveaux utilisateurs à travers les étapes essentielles de configuration avant d'accéder à l'application complète. Le processus débloque progressivement les menus du sidebar.

## Flux

1. **Inscription / Connexion** : l'utilisateur est redirigé vers l'étape courante
2. **Étape "Comptes"** : créer au moins un compte de trading
3. **Étape "Actifs"** : vérifier les actifs pré-configurés, ajuster si nécessaire
4. **Clic "Commencer à trader"** : l'onboarding est marqué comme terminé, redirection vers le dashboard

## Comportements clés

- Les liens du sidebar sont **grisés et non cliquables** pour les étapes verrouillées
- **Redirection automatique** si l'utilisateur tente d'accéder directement à une URL bloquée
- La page `/account` (profil) reste accessible à tout moment
- L'onboarding est **idempotent** : un second appel à `complete-onboarding` ne modifie pas le timestamp

## Verrouillage par étape

| Étape | Routes autorisées |
|-------|-------------------|
| `accounts` (0 compte) | accounts, account |
| `symbols` (1+ compte) | accounts, symbols, account |
| _(terminé)_ | toutes |

## Backend

### Colonne DB

```sql
ALTER TABLE users ADD COLUMN onboarding_completed_at TIMESTAMP NULL DEFAULT NULL AFTER profile_picture;
```

- `NULL` = onboarding en cours
- Timestamp = onboarding terminé

### Endpoint

**POST** `/api/auth/complete-onboarding`

- **Auth** : JWT requis
- **Réponse** : profil utilisateur complet avec `onboarding_completed_at` renseigné
- **Idempotent** : si déjà complété, retourne le profil sans modifier le timestamp

### Champs impactés

- `GET /auth/me` : retourne `onboarding_completed_at`
- `POST /auth/register` : le champ est `null` pour les nouveaux utilisateurs
- `POST /auth/login` : le champ est inclus dans le profil

## Frontend

### Composable `useOnboarding`

| Export | Description |
|--------|-------------|
| `isOnboarding` | `computed` : `true` si l'utilisateur existe et `onboarding_completed_at` est `null` |
| `currentStep` | `computed` : `'accounts'` si 0 comptes, `'symbols'` si 1+ comptes, `null` si terminé |
| `onboardingRoute` | `computed` : objet route vers l'étape courante |
| `isRouteAllowed(name)` | Vérifie si une route nommée est accessible à l'étape courante |
| `completeOnboarding()` | Appelle l'API et met à jour le store auth |
| `ensureAccountsLoaded()` | Charge les comptes si pas encore fait (pour le guard du router) |

### Router guard

Le `beforeEach` du router :
1. Après la vérification d'authentification, charge les comptes si nécessaire
2. Si onboarding actif et route non autorisée → redirection vers l'étape courante
3. Si route guest + token → redirige vers l'étape d'onboarding au lieu du dashboard

### Sidebar (AppLayout)

Chaque lien de navigation possède une propriété `name`. Si `isRouteAllowed(name)` retourne `false`, le lien est rendu comme un `<span>` grisé (`text-gray-400 dark:text-gray-600 cursor-not-allowed`) au lieu d'un `<RouterLink>`.

### Bannières

- **AccountsView** : bannière bleue "Bienvenue ! Commencez par créer un compte de trading." (étape `accounts`)
- **SymbolsView** : bannière bleue avec description + bouton "Commencer à trader" (toute l'étape onboarding)

### Clés i18n

- `onboarding.welcome`
- `onboarding.step_accounts_description`
- `onboarding.step_symbols`
- `onboarding.step_symbols_description`
- `onboarding.start_trading`

## Tests

### Backend (PHPUnit)

- `testRegisterReturnsOnboardingNull` — register retourne `onboarding_completed_at: null`
- `testCompleteOnboardingSuccess` — POST → 200, champ renseigné
- `testCompleteOnboardingIdempotent` — second appel ne change pas le timestamp
- `testCompleteOnboardingWithoutAuth` — 401
- `testOnboardingCompletedAtInMe` — GET /auth/me retourne le champ

### Frontend (Vitest)

- `isOnboarding` : false si user null, true si completed_at null, false si set
- `currentStep` : 'accounts' si 0 comptes, 'symbols' si comptes, null si terminé
- `isRouteAllowed` : combinaisons étape/route
- `completeOnboarding` : appelle API, met à jour user
- Accounts store : flag `loaded` (initial false, set after fetch, cleared on reset)
