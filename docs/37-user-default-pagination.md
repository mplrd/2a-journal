# Étape 37 — Pagination par défaut configurable par l'utilisateur

## Résumé

Chaque utilisateur peut désormais choisir le nombre de lignes affichées par défaut dans les listes paginées (trades, positions, ordres). Valeurs autorisées : 10, 25, 50, 100. Défaut applicatif : **10** (au lieu de 25 hardcodé précédemment, qui forçait du scroll dès la première page).

Profite aussi du passage pour nettoyer l'affichage des colonnes `size`, `remaining_size` et `total_size` : elles affichaient systématiquement 5 décimales (`1.50000`) au lieu de garder seulement les chiffres significatifs (`1.5`).

## Fonctionnalités

### Préférence "Lignes par page"

Nouveau champ `default_page_size` sur la table `users` (`INT UNSIGNED NOT NULL DEFAULT 10`). Modifiable depuis l'onglet **Compte → Préférences** via un sélecteur déroulant (10 / 25 / 50 / 100).

Validation server-side dans `AuthService::updateProfile` : allow-list stricte. Les valeurs hors de l'allow-list lèvent `ValidationException('auth.error.invalid_page_size')`.

### Application dans l'app

Au mount des vues paginées, le store concerné lit `authStore.user.default_page_size` avant le premier fetch, et l'applique comme valeur initiale du paginator PrimeVue. L'utilisateur garde la possibilité de basculer ponctuellement vers une autre taille via le paginator (ephémère).

Vues concernées :
- `TradesView`
- `PositionsView`
- `OrdersView`

### Format des tailles sans zéros traînants

Helper partagé `frontend/src/utils/format.js#formatSize(value)` : convertit en `Number` puis `.toString()`, ce qui élague les zéros à droite (`Number("1.50000").toString()` → `"1.5"`). Renvoie `'-'` pour `null` / `''` / `NaN`.

Appliqué à :
- `TradesView` — colonnes `size` et `remaining_size`
- `OrdersView` — colonne `size`
- `PositionsView` — colonne `total_size`
- `RecentTrades` (dashboard) — colonne `size`

Toutes ces cellules portent désormais aussi `font-mono tabular-nums` (cohérence charte sur les numerics).

## Choix d'implémentation

### Allow-list au lieu de min/max

Borner via une liste finie évite qu'un utilisateur règle à 10 000 lignes par page et fasse exploser la requête + le rendu. Le SPA propose les 4 valeurs ; l'API les valide indépendamment côté serveur.

### Défaut applicatif à 10 plutôt que 25

L'observation : sur écran portrait ou laptop 14", 25 lignes forcent du scroll vertical dès l'affichage initial. 10 tient au-dessus du fold dans la quasi-totalité des cas et l'utilisateur sait qu'il peut élargir d'un clic via le paginator s'il en veut plus pour une session.

### Lecture lazy depuis `authStore` côté views, pas dans les stores

Pour éviter une dépendance circulaire potentielle (auth store ↔ data stores), les data stores gardent un défaut `10` codé en dur, et chaque vue applique `store.perPage = authStore.user?.default_page_size || 10` dans son `onMounted` avant le premier fetch. C'est explicite, traçable, et l'auth store reste le seul à connaître le `user`.

### Trim des décimales sans `Intl`

`Number.toString()` suffit pour le format demandé (drop des zéros de droite). Pas de séparateur de milliers sur les sizes parce que les valeurs métier (lots / contrats) tiennent sous 100 dans la quasi-totalité des cas. Si on voulait un séparateur, il faudrait passer par `Intl.NumberFormat` avec `maximumFractionDigits` adapté — pas nécessaire ici.

## Schéma

```sql
-- Migration 012
ALTER TABLE users
  ADD COLUMN default_page_size INT UNSIGNED NOT NULL DEFAULT 10
  AFTER be_threshold_percent;
```

Idempotent via `INFORMATION_SCHEMA` (pattern projet).

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Backend — `AuthServiceTest` | 3 nouveaux : `testUpdateProfileAcceptsSupportedPageSizes`, `testUpdateProfileRejectsUnsupportedPageSize`, `testUpdateProfileRejectsNonIntegerPageSize` |
| Backend — suite globale | 1038/1038 ✓ |
| Frontend — `positions-store.spec.js` | adapté (`per_page: 25` → `10`) |
| Frontend — suite globale | 191/191 ✓ |

## i18n

Nouvelles clés (fr + en) :
- `account.default_page_size`
- `account.default_page_size_hint`
- `auth.error.invalid_page_size`
