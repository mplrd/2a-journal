# Évolution #13 — Navigation : sidebar persistant + menu compte utilisateur

## Résumé

Refactorisation complète du layout de l'application. Le bandeau horizontal plat (tous les liens en ligne) est remplacé par un layout à deux zones : header full-width en haut (burger, titre, sélecteur de langue, avatar + Popover) et sidebar persistant en dessous (navigation avec icônes). Le sidebar est ouvert par défaut sur desktop et repliable ; sur mobile il se comporte en overlay.

## Architecture

### Scope

Frontend uniquement — aucun changement backend.

### Fichiers modifiés

| Fichier | Action |
|---------|--------|
| `frontend/src/components/layout/AppLayout.vue` | Réécriture complète |
| `frontend/src/main.js` | Ajout directive `Tooltip` |
| `frontend/src/__tests__/language-selector.spec.js` | Adapté (plus de stub Sidebar) |

### Composant PrimeVue utilisé

| Composant | Usage |
|-----------|-------|
| `Popover` | Menu utilisateur (dropdown ancré sur avatar) |

Le sidebar n'utilise **pas** le composant PrimeVue `Sidebar` — c'est un `<aside>` CSS custom avec transition, pour permettre un mode persistant (in-flow) sur desktop.

### Structure du layout

```
┌──────────────────────────────────────────────────┐
│  [☰] App Title                    [FR|EN] [👤 ▾] │  ← header full-width
├──────────┬───────────────────────────────────────┤
│ Dashboard│                                       │
│ Comptes  │         Contenu (RouterView)          │
│ Positions│                                       │
│ Ordres   │                                       │
│ Trades   │                                       │
│ Mes actifs│                                      │
└──────────┴───────────────────────────────────────┘
```

### Comportement responsive

| | Desktop (≥768px) | Mobile (<768px) |
|--|---|---|
| **Sidebar** | `static`, in-flow, pousse le contenu | `fixed`, overlay avec backdrop sombre |
| **Défaut** | Ouvert | Fermé |
| **Toggle** | Burger (ouvert/fermé) | Burger (ouvert/fermé) |
| **Clic lien** | Reste ouvert | Ferme le sidebar |
| **Persistance** | `localStorage('sidebarOpen')` | Toujours fermé au chargement |

### Header

- **Burger** : toujours visible, toggle le sidebar
- **Titre** : nom de l'application
- **Sélecteur de langue** : boutons FR / EN directement dans le header
- **Avatar** : cercle CSS (initiales), ouvre le Popover utilisateur

### Menu utilisateur (Popover)

1. **Identité** : nom complet + email
2. **Bouton logout**

### Avatar

```js
const userInitials = computed(() => {
  const f = authStore.user?.first_name?.[0] || ''
  const l = authStore.user?.last_name?.[0] || ''
  return (f + l).toUpperCase()
})
```

### Liens de navigation (Sidebar)

| Lien | Icône |
|------|-------|
| Dashboard | `pi-home` |
| Comptes | `pi-wallet` |
| Positions | `pi-chart-line` |
| Ordres | `pi-list` |
| Trades | `pi-arrow-right-arrow-left` |
| Mes actifs | `pi-star` |

### Fix Tooltips

La directive PrimeVue `Tooltip` n'était pas enregistrée dans `main.js`. Les `v-tooltip.top` présents sur tous les boutons d'action des grids étaient silencieusement ignorés. Corrigé :

```js
import Tooltip from 'primevue/tooltip'
app.directive('tooltip', Tooltip)
```

### Colonne Compte sur les grids

Ajout d'une colonne "Compte" en première position dans les 3 DataTable :
- **OrdersView**, **TradesView**, **PositionsView**
- Lookup du nom via `accountsStore.accounts` par `account_id`

## i18n

Clés ajoutées :

| Clé | FR | EN |
|-----|----|----|
| `nav.menu` | Menu | Menu |
| `nav.my_account` | Mon compte | My account |
| `positions.account` | Compte | Account |

## Tests

### Frontend (91 tests)

- **language-selector.spec.js** — 5 tests adaptés :
  - `renders language options` — boutons FR/EN dans le header
  - `highlights current locale` — classe `font-bold` sur le bouton actif
  - `switches locale to English` — clic → locale change
  - `persists locale choice in localStorage` — clic → localStorage mis à jour
  - `loads saved locale from localStorage on mount` — bouton correct bold

#### Stubs de test

```js
Popover: { template: '<div><slot /></div>', methods: { toggle() {} } }
```

## Vérification manuelle

1. **Header** : full-width, burger, titre, FR/EN, avatar + Popover
2. **Sidebar desktop** : ouvert par défaut, pousse le contenu, toggle avec burger, état persisté
3. **Sidebar mobile** : fermé par défaut, overlay avec backdrop, clic lien ferme
4. **Popover** : nom + email, logout
5. **Tooltips** : survoler les boutons d'action des grids → tooltip visible
6. **Colonne Compte** : visible dans Positions, Ordres, Trades

## Scope exclu (évolutions ultérieures)

- Notifications dans le header — P3
- Thème sombre (dark mode toggle dans le Popover) — P3
- Breadcrumbs sous le header — P3
- Animation de transition sur les liens actifs — P3
