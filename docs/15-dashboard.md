# 15 - Dashboard

## Objectif

Fournir une vue d'ensemble simple du journal de trading avec les KPIs clés, des graphiques de performance, les trades (en cours et récents) et un calendrier de P&L journalier. Filtre par compte uniquement.

## Endpoints API

### GET /stats/overview

Retourne les KPIs et les trades récents (5 derniers trades fermés).

**Query params** : `account_id` (optionnel, filtre par compte)

Les filtres avancés (date_from, date_to, direction, symbols, setups) sont également supportés mais utilisés uniquement depuis la page Performance.

**Réponse** :
```json
{
  "success": true,
  "data": {
    "overview": {
      "total_trades": 10,
      "total_pnl": 500.00,
      "winning_trades": 7,
      "losing_trades": 2,
      "be_trades": 1,
      "win_rate": 70.00,
      "profit_factor": 3.50,
      "best_trade": 200.00,
      "worst_trade": -80.00,
      "avg_rr": 1.50
    },
    "recent_trades": [...]
  }
}
```

### GET /stats/charts

Retourne les données pour les graphiques (P&L cumulé, distribution W/L, P&L par symbole).

**Query params** : mêmes filtres que `/stats/overview`

### GET /stats/open-trades

Retourne les 5 trades actuellement ouverts (status = OPEN), ordonnés par date d'ouverture décroissante.

**Query params** : `account_id` (optionnel)

**Réponse** : `[{id, opened_at, remaining_size, symbol, direction, entry_price, size, account_name}]`

### GET /stats/daily-pnl

Retourne le P&L agrégé par jour (trades fermés uniquement).

**Query params** : mêmes filtres que `/stats/overview`

**Réponse** : `[{date, trade_count, total_pnl}]`

## Architecture frontend

### Composants

| Composant | Rôle |
|-----------|------|
| `KpiCards.vue` | Grille de 6 cartes avec les métriques clés |
| `CumulativePnlChart.vue` | Graphique en courbe (Chart.js via PrimeVue) |
| `WinLossChart.vue` | Graphique doughnut gains/pertes/BE |
| `PnlBySymbolChart.vue` | Graphique en barres P&L par symbole (vert/rouge) |
| `RecentTrades.vue` | Tabs "En cours" / "Récents" — trades ouverts et 5 derniers trades fermés |
| `PnlCalendar.vue` | Calendrier mensuel avec P&L journalier, navigation mois, couleurs vert/rouge/jaune |
| `DashboardView.vue` | Vue principale — filtre par compte (Select inline), pas de filtres avancés |

### Layout

- Header avec titre + Select de compte
- KPIs en grille 6 colonnes
- 3 charts en grille responsive (lg:3 cols, mobile stack)
- Dernière ligne en grille lg:3 cols :
  - Trades avec tabs (2/3 — `lg:col-span-2`) : onglet "En cours" (trades ouverts) et "Récents" (trades fermés)
  - Calendrier P&L journalier (1/3) : grille mensuelle, jours colorés selon le P&L **arrondi à l'unité** (vert = gain, rouge = perte, orange = 0), navigation mois précédent/suivant

### Calendrier : des semaines complètes

Chaque ligne du calendrier est une semaine entière, du lundi au dimanche. Quand le mois ne commence pas un lundi, la première ligne s'ouvre sur les derniers jours du mois précédent ; quand il ne finit pas un dimanche, la dernière ligne se termine sur les premiers jours du mois suivant. Plus de cases vides : une semaine à cheval sur deux mois se lit en entier.

- **Jours hors mois atténués** (`opacity-40`), mais **avec leur P&L et leur couleur** : le dashboard charge le P&L journalier de tout l'historique (`/stats/daily-pnl` sans filtre de date), ces jours ont donc leurs données sans requête supplémentaire.
- **Aucun jour ajouté** avant un mois qui commence un lundi, ni après un mois qui finit un dimanche.
- Le calcul passe par le constructeur `Date` (`new Date(année, mois, 1 - n)`, `new Date(année, mois + 1, n)`), qui bascule de lui-même sur le mois et l'année voisins — janvier ouvre sur décembre de l'année précédente sans cas particulier.
- L'infobulle d'un jour tradé (« 2 trade(s) : +120 ») passe par la clé `dashboard.trade_count` au lieu d'un texte en dur.

Chaque case porte `data-date` et `data-outside`, ce que les tests exploitent.

### Calendrier : la couleur suit le chiffre affiché

Une case affiche le P&L du jour arrondi à l'unité. Sa couleur se lit **sur ce chiffre arrondi**, plus sur le montant exact : un jour à +0,30 ou à −0,30 affiche « 0 » et passe en orange, comme un zéro pile. Avant, le même « 0 » sortait vert à +0,30 et rouge à −0,30.

- **Orange = le chiffre affiché vaut 0**, soit un résultat entre −0,49 et +0,49. Il s'affiche **sans signe** (« 0 », plus « +0 » ni « -0 »), infobulle comprise.
- **Un seul arrondi pour le chiffre et la couleur** (`roundedPnl()`, via `toFixed(0)`, qui arrondit la demi-unité en s'éloignant de zéro). `Math.round` aurait fait de −0,50 un zéro alors que la case affiche « -1 ».
- Le texte d'aide (`dashboard.daily_calendar_help`) disait « une case orange signale un résultat exactement nul » ; il précise désormais « nul une fois arrondi à l'unité (entre -0,49 et +0,49) ».

### Comportement au chargement

- **Reset des filtres** : les filtres du store sont remis à zéro (`setFilters({})`) au montage de la page. Ainsi, naviguer vers une autre page puis revenir affiche toujours les données non filtrées.
- **Overlay de chargement** : pendant le fetch, un overlay semi-transparent (`bg-white/60` / `bg-gray-900/60`) s'affiche par-dessus le contenu existant avec un spinner centré, plutôt que de masquer le contenu. Cela évite le flicker (flash des anciennes données → spinner → nouvelles données).

## Tests

| Type | Fichier | Tests |
|------|---------|-------|
| Integration | `StatsRepositoryTest.php` | getOpenTrades (3), getDailyPnl (3) |
| Unit | `StatsServiceTest.php` | 13 tests |
| Integration | `StatsFlowTest.php` | 15 tests |
| Frontend | `PnlBySymbolChart.spec.js` | 3 tests |
| Frontend | `PnlCalendar.spec.js` | 11 tests — dont couleur sur l'arrondi : +0,30 / −0,30 / 0 en orange affichés « 0 » (infobulle « 1 trade(s) : 0 »), ±0,50 affichés « +1 » / « -1 » en vert / rouge ; semaines complètes : 1re semaine ouverte sur le mois précédent (septembre 2026 → 31 août), dernière fermée sur le mois suivant (→ 1er-4 octobre), 35 cases sans case vide, rien avant un mois qui commence un lundi (juin 2026) ni après un mois qui finit un dimanche (mai 2026), P&L et infobulle d'un jour hors mois, recalcul à la navigation (août 2026 → 27 juillet … 6 septembre) |
| Unit | `stats-store.spec.js` | 6 tests |
