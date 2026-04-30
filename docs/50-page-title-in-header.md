# Étape 50 — Titre de page dans le header de l'application

## Résumé

Le titre de la page courante (Dashboard, Performance, Comptes, …) est désormais affiché dans le header global, au format **« Journal · {{titre}} »**, et n'est plus dupliqué en `<h1>` dans chaque vue. Les vues gagnent en densité utile (~40px de hauteur récupérés par page) sans perte de contexte.

## Fonctionnalités

### Métadonnée `titleKey` sur chaque route authentifiée

Chaque route enfant d'`AppLayout` reçoit un `meta.titleKey` qui pointe vers la clé i18n existante (`dashboard.title`, `performance.title`, …) :

```js
{
  path: '',
  name: 'dashboard',
  component: () => import('@/views/DashboardView.vue'),
  meta: { titleKey: 'dashboard.title' },
}
```

7 routes concernées : `dashboard`, `accounts`, `positions`, `orders`, `trades`, `performance`, `account`. Les routes guest (login, register, forgot/reset, verify-email, subscribe) ne sont pas dans `AppLayout`, elles conservent leur propre `<h1>` interne.

### Header `AppLayout.vue`

Bandeau de gauche enrichi :

```
[≡] [logo] Journal · Performance
```

- `Journal` (label de marque) reste dans le `<RouterLink>` → "/" comme bouton "retour à l'accueil".
- Séparateur `·` (gris léger) entre marque et titre de page.
- Titre de page lu via un `computed` sur `route.meta.titleKey` :

```js
const pageTitle = computed(() => {
  const key = route.meta?.titleKey
  return key ? t(key) : ''
})
```

Réactif au switch de locale (`t()` capture la locale courante).

#### Responsive

Sur mobile (< 640px / `sm`), le label « Journal » et le séparateur sont masqués (`hidden sm:inline`) — seul le logo + le titre de page restent. Le logo continue à porter la marque, le titre de page reste visible pour ne pas perdre le contexte de navigation.

### Vues nettoyées

Les 7 vues routées perdent leur `<h1>` (ou `<h2>` pour `AccountView`) en tête de template :

| Vue | Avant | Après |
|---|---|---|
| `DashboardView` | h1 + `BadgeFilter` (bandeau `justify-between`) | `BadgeFilter` seule (bandeau `justify-end`) |
| `AccountsView` | h1 + bouton "+ Compte" | bouton seul |
| `PositionsView` | h1 seul (wrapper `flex justify-between`) | wrapper supprimé |
| `OrdersView` | h1 + bouton "+ Ordre" | bouton seul |
| `TradesView` | h1 + bouton "+ Trade" | bouton seul |
| `PerformanceView` | h1 + `mb-4` au-dessus des filtres | `DashboardFilters` directement |
| `AccountView` | h2 au-dessus des onglets | onglets directement |

### Test obsolète retiré

`account-view.spec.js > renders page title` checkait `wrapper.find('h2').exists()` — devenu obsolète : le titre n'est plus rendu par `AccountView`. Test supprimé. Le rendu est désormais une responsabilité d'`AppLayout` (testée implicitement par le fait que le header monte sans erreur dans les autres tests qui consomment `AppLayout`).

## Choix d'implémentation

### Pourquoi `meta.titleKey` (clé i18n) plutôt que `meta.title` (texte)

Le router est instancié une seule fois au boot, avant l'init i18n. Stocker un texte traduit y serait gelé sur la locale initiale. En stockant la **clé**, la résolution `t(key)` se fait dans le composant qui l'affiche, donc la locale courante est toujours respectée — y compris après `switchLocale()`.

### Pourquoi `Journal · Page` et pas remplacer "Journal" par le titre de page

Conserver le label "Journal" donne deux ancrages :
- la marque (réassurance, identité produit) ;
- la nature de l'outil (un journal, pas un wallet ou un broker).

Le séparateur `·` est plus léger qu'un `›` ou un `/` — il fonctionne comme un délimiteur typographique sans suggérer une hiérarchie de breadcrumb (qui pourrait laisser croire que `Journal` est un parent navigable indépendant alors que c'est juste la marque).

### Pourquoi masquer le label "Journal" en mobile et garder le titre de page

Sur petits écrans, l'espace horizontal est rare et déjà occupé par burger + logo + actions à droite (locale, theme, avatar). Ce qui sert le plus l'utilisateur dans cet espace contraint, c'est **le contexte de la page courante** (« où je suis ? »), pas la répétition de la marque que le logo véhicule déjà visuellement. La règle « le logo porte la marque, le texte porte le contexte » est appliquée uniquement en mobile.

### Pourquoi pas de fallback sur le `route.name`

Si une future route oublie de déclarer `meta.titleKey`, le titre de page disparaît silencieusement plutôt que d'afficher un identifiant technique (`dashboard`, `accounts`, …). Préférable à montrer un `name` à l'utilisateur. Un test de garde côté router pourrait être ajouté plus tard si besoin.

## Couverture des tests

| Surface | Tests |
|---|---|
| Frontend — suite globale | 191/191 ✓ |
| Build frontend | OK |

Test visuel manuel non effectué côté Claude (pas d'accès navigateur). À valider par le owner :
- le titre de page apparaît bien dans le header pour les 7 routes authentifiées ;
- le passage de locale FR ↔ EN met à jour le titre en live ;
- en viewport mobile, "Journal" est masqué et le titre de page reste visible ;
- les actions des vues (`+ Compte`, `+ Ordre`, `+ Trade`, filtre comptes du Dashboard) restent alignées à droite.

## Évolutions retirées de `docs/evolutions.md`

- (rien — cette feature vient d'une demande utilisateur post-merge step 49)
