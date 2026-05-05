# Étape 58 — Convention responsive (smartphone vertical)

## Résumé

Mise en place d'une convention responsive durable pour l'application. Jusqu'ici les vues étaient exclusivement pensées web/desktop : DataTables larges, filtres permanents, bouton "Nouveau" inline. Sur smartphone vertical, ça donnait une UX dégradée (scroll horizontal pénible, filtres qui occupent un tiers d'écran, perte d'espace).

Cette livraison introduit **3 patterns réutilisables** appliqués aux 3 vues prioritaires (Comptes, Ordres, Trades) :

1. **Filtres pliables** par défaut sur mobile (`<CollapsibleFilters>`).
2. **Bouton d'action flottant** (FAB) en bas à droite sur mobile (`<FloatingActionButton>`), remplace le bouton "Nouveau" inline qui est masqué.
3. **Liste de tuiles paginées** (`<TileList>`) en remplacement des `<DataTable>` sur mobile, avec les mêmes champs et les mêmes boutons d'action regroupés en coin de tuile.

Source du besoin : retour utilisateur direct (en cours de livraison E-07, l'utilisateur a constaté que l'UI mobile était sous-investie).

## Convention de breakpoint — 3 layouts

| Layout | Plage | Liste | Bouton "Nouveau" | Filtres |
|--------|-------|-------|------------------|---------|
| **mobile** | < 768 px | TileList vertical | FAB (`md:hidden` fixé bas-droite) | CollapsibleFilters |
| **compact** | 768–1023 px | DataTable compacte (`size="small"`) + colonnes secondaires masquées | Inline "Nouveau" | Inline single-row |
| **desktop** | ≥ 1024 px | DataTable pleine densité | Inline "Nouveau" | Inline single-row |

Le composable `useLayout()` (`frontend/src/composables/useIsMobile.js`) expose `{ layout, isMobile, isCompact, isDesktop }` basé sur 2 matchMedia (mobile + compact ranges). Pas de listener resize-throttling : `matchMedia` ne déclenche `change` que quand le prédicat bascule, c'est gratuit. SSR/test-safe (défaut 'desktop' si matchMedia indisponible).

```js
const { layout, isMobile, isCompact, isDesktop } = useLayout()
```

`useIsMobile()` reste exporté comme alias backward-compat (retourne `{ isMobile }` seul) pour les call-sites qui n'ont besoin que du booléen.

**Historique du choix** : 2 itérations de retour utilisateur :
1. Initial à `md:` (768) → trop cramé sur iPhone Pro Max + iPad portrait.
2. Bump à `lg:` (1024) en 2 layouts → mobile gagné, mais iPad portrait basculait en TileList alors qu'il avait la place pour une DataTable compactée.
3. Refactor à 3 layouts (état actuel) — TileList strictement < 768, DataTable compactée 768–1023, DataTable pleine ≥ 1024.

```js
const { isMobile } = useIsMobile()
```

À utiliser dans les vues qui doivent switcher de layout. Pas besoin pour les composants purement visuels : Tailwind `md:` suffit (`md:hidden`, `hidden md:block`, etc.).

## Patterns

### 1. Filtres pliables — `<CollapsibleFilters>`

`frontend/src/components/common/CollapsibleFilters.vue`

Header avec chevron + bouton toggle, body conditionnel rendu dans un slot par défaut. État replié/déplié **persisté en `localStorage`** sous une clé propre à chaque vue (paramètre `storage-key` requis), pour que la préférence de l'utilisateur survive aux navigations et reloads.

```html
<CollapsibleFilters storage-key="trades-filters-expanded">
  <div class="flex flex-col gap-3">
    <!-- contenu des filtres : BadgeFilter, DateRangePicker… -->
  </div>
</CollapsibleFilters>
```

**Pattern d'application** :
- En **mobile** (`v-if="isMobile"`) → `<CollapsibleFilters>` enroule les filtres.
- En **desktop** (`v-else`) → les filtres restent inline sur leur barre dédiée (cf. layout single-row de doc 57).

**Clés `storage-key` utilisées** :
- `trades-filters-expanded`
- `orders-filters-expanded`
- (Comptes n'a pas de filtre → pas de clé)
- L'ancien `dashboardFiltersExpanded` (DashboardFilters.vue) reste tel quel pour ne pas invalider les préférences déjà stockées des utilisateurs.

### 2. FAB — `<FloatingActionButton>`

`frontend/src/components/common/FloatingActionButton.vue`

Bouton circulaire **fixé en bas à droite** (`fixed bottom-6 right-6`), **icône seule** (pas de label), **visible seulement sur mobile** (`md:hidden` natif). Couleur primaire (`bg-brand-green-700`), shadow appuyée, focus ring accessible.

```html
<FloatingActionButton
  icon="plus"
  :aria-label="t('trades.create')"
  @click="showForm = true"
/>
```

**Pattern d'application** :
- Le bouton "Nouveau" inline existant reçoit un `v-if="!isMobile"` (ou bloc englobant `v-if`) → masqué sur mobile.
- Le `<FloatingActionButton>` est mounté en parallèle, naturellement caché sur desktop par sa classe `md:hidden`.
- **Aria-label obligatoire** (le FAB n'a pas de texte visible, l'accessibilité l'exige).

### 3. Tuiles paginées — `<TileList>`

`frontend/src/components/common/TileList.vue`

Wrapper générique : prend une liste d'`items`, expose chaque item via un slot scope-named `default` ; rend chaque tuile dans un container vertical espacé (`flex flex-col gap-3`). État `loading` (spinner) et `empty` (slot dédié) gérés. **Pagination optionnelle** en bas (Précédent / Page X de Y / Suivant), activée si `totalRecords` est fourni et que `totalRecords > perPage`.

**Contrat `@page`** : émet `{ page: 0-based, rows: N }`, identique au DataTable PrimeVue → les handlers `onPage(event)` existants se branchent sans modification.

```html
<TileList
  v-else-if="isMobile && store.totalRecords > 0"
  :items="store.trades"
  :loading="store.loading"
  :total-records="store.totalRecords"
  :page="store.page"
  :per-page="store.perPage"
  @page="onPage"
>
  <template #default="{ item }">
    <div class="relative p-4 rounded-lg border …">
      <!-- Boutons d'action en haut à droite (corner). Mêmes handlers que la grid. -->
      <div class="absolute top-2 right-2 flex gap-1">…</div>
      <!-- Champs : mêmes que les colonnes de la DataTable. -->
    </div>
  </template>
</TileList>
```

**Choix de design** :
- **Pas de composant per-entity** (`AccountTile`, etc.) : les helpers de la vue (`typeSeverity`, `balanceClass`, `getNextObjective`…) sont scope-locaux et ne mériteraient pas d'être extraits — slot inline est plus simple et plus DRY.
- **Boutons d'action en coin** (top-right, absolute) : libère le contenu de la tuile pour les champs métier, tout en gardant les actions visibles d'un coup d'œil. Mêmes icônes / mêmes handlers que la version DataTable, juste un autre layout.
- **Champs identiques à la grid** : pas de réduction "pour mobile". L'utilisateur voit la même information. Volonté explicite de l'utilisateur lors du cadrage : "tile = format d'affichage uniquement, pas de scope réduit".

## Vues impactées

| Vue | TileList (mobile) | Compact (768–1023) — colonnes masquées | Filtres pliables | FAB | Bulk-select mobile |
|-----|:---:|:---|:---:|:---:|:---:|
| **Accounts** | ✅ | `currency`, `initial_capital`, `broker` | — (pas de filtre) | ✅ | — (pas de bulk) |
| **Orders** | ✅ | `direction`, `status`, `expires_at`, `order_created_at` | ✅ | ✅ | — (pas de bulk) |
| **Trades** | ✅ | `direction`, `status`, `remaining_size`, `opened_at`, custom fields | ✅ | ✅ | ❌ (cf. limitations) |

### Patterns spécifiques compact + mobile

**Direction + statut → pictos préfixant le symbole** (Orders + Trades) : les Tags PrimeVue prenaient ~140 px par ligne. En compact + tuile mobile, on les remplace par 2 icônes encadrant le nom du symbole. Tooltip sur chaque icône pour conserver la lisibilité. Le `setup` est ainsi ramené dans la grille compact sans perdre de place.

- Direction : `pi pi-arrow-up` (BUY, vert) / `pi pi-arrow-down` (SELL, rouge).
- Statut Trade : `pi pi-bolt` (OPEN, bleu = position active) / `pi pi-shield` (SECURED, orange = BE atteint, partie restante protégée) / `pi pi-check-circle` (CLOSED win/BE, vert) / `pi pi-times-circle` (CLOSED loss, rouge). Le picto CLOSED bascule selon le P&L réalisé.
- Statut Order : `pi pi-clock` (PENDING, bleu) / `pi pi-bolt` (EXECUTED, vert = ordre déclenché, trade créé) / `pi pi-times-circle` (CANCELLED, orange) / `pi pi-ban` (EXPIRED, rouge).

**Compte → badge sous le symbole** (compact uniquement) : la colonne dédiée `account_id` est masquée en compact. À la place, le nom du compte apparaît dans un petit badge gris sous la rangée `direction · symbol · status` de la colonne Symbol. Libère une colonne supplémentaire dans la grille compact, le compte reste lisible d'un coup d'œil.

**Taille = `remaining_size (size)`** (Trades) : en compact, la colonne `remaining_size` est masquée mais l'info reste accessible — la colonne `size` affiche `{remaining}` en valeur principale et `({size})` en plus petit/muté. Si remaining = size (aucune partielle), seule la valeur principale est rendue. Même format dans la tuile mobile.

**Tuile mobile — actions sur une seule ligne en haut à droite + dropdown "⋮"** : le L inversé (mgmt en ligne + secondaires empilés verticalement) prenait trop de hauteur. Désormais toutes les actions tiennent sur une seule ligne en haut à droite, alignée avec le titre du symbole, grâce à un PrimeVue `Menu` popup partagé qui héberge **modifier + supprimer**. Le bouton `pi pi-ellipsis-v` à l'extrémité droite ouvre ce menu, ce qui ramène la rangée visible à 2-5 boutons selon le statut :
- Trades open : next-obj, close, transfer (si activé), share, ⋮ (max 5)
- Trades closed : share, ⋮ (2)
- Orders pending : execute, cancel, transfer, share, ⋮ (5)
- Orders autres : share, ⋮ (2)
- Accounts : sync (si activé), import, ⋮ (3)

Le `Menu` est instancié une seule fois par vue (singleton) ; le clic sur ⋮ d'une tuile met à jour l'item courant puis appelle `menu.toggle($event)`. Le contenu de la grille (entry, size, P&L, setups…) suit en pleine largeur sous l'en-tête, sans contrainte de colonne actions.

Les autres colonnes restent visibles dans tous les modes (desktop, compact). Les valeurs masquées en compact restent accessibles via le détail / l'édition de l'item.

Les autres vues (Performance, Dashboard, Symbols, Trades-import, etc.) ne sont **pas** responsive dans cette livraison. Convention à appliquer à mesure que les retours mobile remontent.

## Couverture des tests

| Test | Fichier | Scénario | Statut |
|------|---------|----------|--------|
| `CollapsibleFilters > starts collapsed by default` | `__tests__/responsive-components.spec.js` | Slot caché initialement, révélé après toggle | ✅ |
| `CollapsibleFilters > persists expanded state in localStorage` | idem | localStorage écrit avec la clé fournie | ✅ |
| `CollapsibleFilters > honors defaultExpanded` | idem | Si `defaultExpanded=true` et localStorage vide, slot visible d'entrée | ✅ |
| `FloatingActionButton > emits click and exposes aria-label` | idem | Click bubble + classes `md:hidden fixed` présentes | ✅ |
| `TileList > renders one tile per item via slot` | idem | 2 items → 2 tiles via slot scoped | ✅ |
| `TileList > renders empty slot when items is empty` | idem | Slot `empty` rendu | ✅ |
| `TileList > hides pagination when totalRecords ≤ perPage` | idem | Pas de footer si tout tient sur 1 page | ✅ |
| `TileList > emits @page with 0-based contract on next click` | idem | `{ page, rows }` 0-based, drop-in compatible avec onPage | ✅ |
| `TileList > emits @page with 0-based contract on previous click` | idem | Idem côté précédent | ✅ |

**Suite complète post-fix** : 1085/1085 PHPUnit + 221/221 Vitest (212 + 9 nouveaux).

## Limitations connues / Évolutions futures

- **Bulk-select sur mobile** : non livré. Dans la version desktop de TradesView, la sélection multiple via la colonne checkbox de la DataTable alimente le bouton "Supprimer la sélection". En mobile (TileList), il n'y a pas de mécanisme de sélection. Pour l'ajouter, il faudrait soit :
  - Un mode "sélection" déclenché par long-press (style Gmail mobile),
  - Soit un bouton "Sélectionner" qui transforme chaque tuile en mode-checkbox temporaire.
  - Pour l'instant, le bulk-delete reste desktop-only.
- **Autres vues non couvertes** : Performance (graphes), Symbols, Imports, Dashboard. À traiter au fur et à mesure des besoins.
- **Tablette portrait dense** : la plage 768–1023 utilise désormais le mode `compact` (DataTable resserrée + colonnes secondaires masquées). Si certains comptes-utilisateurs trouvent encore le compact trop chargé, on pourra élargir la liste de colonnes masquées par vue ou abaisser la borne haute du compact (ex. `< 1280 px` au lieu de `< 1024 px`).
- **Densité par utilisateur** : pas de toggle UI pour choisir entre compact/desktop manuellement. Si le besoin remonte, on pourra ajouter une préférence user (cf. `users.default_page_size` qui est dans le même esprit).
