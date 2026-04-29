# Étape 44 — Sprint 4 charte : empty states + KPI hero dashboard

## Résumé

Quatrième sprint de l'audit UI (`docs/charte-graphique/AUDIT-UI.html` §2.1 et §2.3). Deux refontes de hiérarchie visuelle :

1. **Dashboard KPIs** — les 6 cards égales sont remplacées par 1 hero P&L (col-span-2) + 4 mini cards. La métrique principale du journal devient lisible au premier coup d'œil. Best/worst déplacés en sous-info de la hero card.
2. **Empty states** — `Trades` / `Orders` / `Positions` vides affichaient une grid avec headers + paginator vide, sans aucune indication d'action. Ils ont maintenant un panneau dédié avec icône, titre, description et CTA.

## Fonctionnalités

### Composant `<EmptyState>`

Nouveau dans `frontend/src/components/common/EmptyState.vue`. Props :
- `icon` (string, défaut `pi pi-inbox`) — icône PrimeIcon
- `title` (required) — titre court
- `description` (optional) — texte d'accompagnement

Slot par défaut pour le CTA (typiquement un `<Button>`). Le composant respecte la palette charte : fond `surface-1`, bordure dashed `gray-200`/`gray-700`, icône en cercle `gray-50` / `gray-700/40`.

### KPI hero dashboard

`KpiCards.vue` recompose le rendu :

```
[ P&L total (col-span-2) ........... ]  [ Win rate ] [ Profit factor ] [ R:R ] [ Trades ]
[   -3 407,51                          ]
[   ▲ best +1277  ▼ worst -650        ]
```

- Card hero : padding p-6, label uppercase tracking-wider, valeur `text-3xl md:text-4xl` en mono-tabular, sous-info best/worst avec icônes flèches
- 4 mini cards inchangées (KpiCard wrapper)
- Best/Worst n'a plus sa propre card → recyclé dans la hero

Layout responsive : `grid-cols-2` mobile, `md:grid-cols-4`, `lg:grid-cols-6`. Hero `col-span-2` à tous breakpoints.

### Empty states sur les 3 vues paginées

`TradesView`, `OrdersView`, `PositionsView` :

```vue
<EmptyState
  v-if="!store.loading && store.totalRecords === 0"
  icon="pi pi-..."
  :title="t('xxx.empty_title')"
  :description="t('xxx.empty')"
>
  <Button :label="t('xxx.create')" icon="pi pi-plus" @click="..." />
</EmptyState>

<DataTable v-else ...>
```

`PositionsView` n'a pas de CTA "Create position" (les positions naissent d'un trade ou d'un ordre) — pas de `<Button>` dans le slot, juste le message.

L'ancien fallback `<p>{{ t('xxx.empty') }}</p>` n'était pas utilisé (les keys étaient orphelines dans les locales). Les keys `empty_title` (titre court) et `empty` (description, déjà existant, juste re-formulée pour fluidité) sont maintenant consommées.

## Choix d'implémentation

### Pourquoi pas un wrapper `<EmptyDataTable>` au lieu d'EmptyState générique ?

L'empty state n'est pas spécifique aux DataTables — on pourrait l'utiliser sur le dashboard quand pas de trade clos, dans `Performance` quand pas de données, etc. Le composant générique est plus utile à long terme. Si on voit du copier-coller, on consolidera.

### Pourquoi best/worst dans la hero P&L plutôt qu'une 6e mini card ?

L'audit ne montre que 5 KPIs dans son mockup. Best/worst sont contextuels au P&L global (extrêmes de la distribution) : on les regroupe avec leur métrique parente. Visuellement ça allège : 5 cards (2+1+1+1+1=6 cols) plutôt que 7.

### Pourquoi `col-span-2` sur la hero et pas un layout en `aspect-ratio` figé ?

`col-span-2` profite du rythme de la grille (les 4 minis font 1 col chacune), garde le tout aligné, et reste responsive : sur mobile (cols-2) la hero prend toute la largeur, sur tablet (cols-4) elle prend la moitié, sur desktop (cols-6) elle prend 1/3. Pas de math d'aspect-ratio à maintenir.

### Pourquoi le delta tendance proposé par l'audit n'est pas inclus ?

L'audit montre `▼ -3.5% sur la période` sous la valeur P&L (delta vs période précédente). Aujourd'hui `getOverview` ne retourne pas ce delta — il faudrait étendre la query SQL pour comparer N et N-1 sur la même fenêtre de filtres. Hors scope Sprint 4 (qui touche purement le rendu). Tracé pour plus tard si intérêt — `evolutions.md` à enrichir si besoin.

## Couverture des tests

| Surface | Tests |
|---------|-------|
| Frontend — suite globale | 191/191 ✓ |
| Build frontend | OK |

Pas de test ajouté : composants présentationnels purs (pas de logique métier). Validation visuelle sur dashboard + un trade/order/position vides.

## Suite (Sprint 5)

Dark mode polish — `feat/dark-mode-polish` :
- Échelle de surfaces 4 niveaux (surface-base / -1 / -2 / -3)
- Modal "Modifier" : border + shadow plus marquée en dark
- Inputs `surface-2` pour ne pas être plus foncés que le modal
