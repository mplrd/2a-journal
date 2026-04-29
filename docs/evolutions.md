# Évolutions à prévoir

Liste des améliorations identifiées en cours de route mais sortant du scope d'une feature en cours. À planifier / prioriser quand les branches concernées seront mergées.

## Statut / calculs

### RR négatif sur un compte 100% shorts en profit

**Contexte** : sur un compte qui n'a que des trades SELL et qui est globalement profitable, le R:R affiché ressort négatif. Symptôme probable : un signe non inversé quelque part pour les SELL dans le calcul du R:R agrégé (StatsRepository ou StatsService).

**Diag 2026-04-24** :
- Code relu : `TradeService::calculateFinalMetrics` (l.568) calcule `rr = totalPnl / (size * slPoints)` où `totalPnl` agrège des `partial_exits.pnl` déjà signés via `directionMultiplier` (l.287) → OK.
- Agrégation : `StatsRepository::getOverview` et `dimensionStatsSelect` font simplement `AVG(t.risk_reward)` sur la valeur stockée → OK.
- Seeder démo : formule cohérente BUY/SELL, avg_rr SELL = +0.537 en démo.
- Tentative de reproduction en réimportant le compte réel : **non reproductible au 2026-04-24**.
- Guard test posé : `StatsFlowTest::testAvgRrPositiveWhenAllSellsProfitable` (crée 3 SELL gagnants via l'API, vérifie `avg_rr > 0` sur `/stats/overview` et `/stats/by-direction`).

**À faire si ça revient** :
- Screenshot + URL (filtres actifs dans la querystring).
- Snapshot du trade concerné : `SELECT pnl, risk_reward, direction, entry_price, avg_exit_price, size, sl_points FROM trades t JOIN positions p ON p.id=t.position_id WHERE ...`.
- Vérifier si `risk_reward` est négatif au niveau d'un trade (bug de persistence) ou si l'agrégation est en cause.

**Repéré le** : 2026-04-23.
**Statut** : en veille, non reproductible, filet en place.
**Priorité** : haute si ça revient (fausse les stats).

---

## UX

### Filtres "légers" en badges plutôt qu'en dropdowns

**Contexte** : sur des écrans avec peu d'options (Trades : compte + statut, ~3-5 valeurs), un dropdown PrimeVue est ergonomiquement lourd : ça demande un click + scroll + click. Pour des sélections fréquentes, un système de **badges cliquables** (toggle inline, multi ou single selon le cas) est plus rapide et visuellement plus moderne. Les `MultiSelect display="chip"` font déjà la moitié du chemin mais en mode dropdown.

**Cibles potentielles** :
- `TradesView` : filter compte + filter statut → barre de badges au-dessus de la grid
- `OrdersView` : idem
- `PositionsView` : filter compte
- Les filtres "lourds" du dashboard (`DashboardFilters` avec dates / direction / symbols / setups) gardent leur format dropdown — c'est un cas riche

**À faire** :
- Composant `<BadgeFilter>` réutilisable (single / multi)
- Sweep TradesView, OrdersView, PositionsView
- Garder DashboardFilters tel quel

**Repéré le** : 2026-04-29.
**Statut** : noté pour après la fin de l'audit UI charte.
**Priorité** : moyenne — gain UX réel sur les écrans listes.

---

### Sélecteur de plage de dates "à la Airbnb" sur Performance

**Contexte** : `PerformanceView` (et `DashboardFilters`) utilisent deux DatePicker séparés "Du" / "Au". Pour une sélection de période, un **range-picker unique** avec affichage calendrier 2-mois et raccourcis ("7 derniers jours", "Ce mois", "30 jours", "Ce trimestre", "YTD") est nettement plus fluide.

**Pistes techniques** :
- PrimeVue `DatePicker` supporte `selectionMode="range"` → mais le rendu reste 2 inputs en visu
- Évaluer un composant tiers (e.g. `vue-tailwind-datepicker`) ou builder un custom léger
- Garder la compat back-end : émet `{date_from, date_to}` comme aujourd'hui

**Cibles** :
- `DashboardFilters.vue` (déjà groupé visuellement, juste un swap)
- `PerformanceView` (en haut)
- À terme, le `closed_at` du `CloseTradeDialog` reste une date single, pas concerné

**À faire** :
- Composant `<DateRangePicker>` avec presets
- Sweep des 2 vues consommatrices
- i18n des presets

**Repéré le** : 2026-04-29.
**Statut** : noté pour après la fin de l'audit UI charte.
**Priorité** : moyenne — confort utilisateur sur les analyses temporelles.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
