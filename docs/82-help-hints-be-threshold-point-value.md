# 82 — Infobulles d'aide : seuil de BE & valeur du point

## Contexte

Deux tickets support (priorité HIGH) demandaient des clarifications contextuelles
directement dans l'interface, plutôt que dans la documentation :

- **#14** — rappeler où se règle le seuil de breakeven (BE) et ce qu'il signifie,
  au niveau du graphe de répartition gains / pertes / BE.
- **#11** — expliquer la notion de « valeur du point », en particulier sa
  spécificité crypto, là où l'utilisateur la saisit.

La réponse retenue dans les deux cas : une **icône d'information** (`i`) avec une
**infobulle** au survol, sans nouvelle page ni formulaire.

## Implémentation

### #14 — Graphe gains / pertes / BE (`WinLossChart`)

- Fichier : `frontend/src/components/dashboard/WinLossChart.vue`
- L'icône est placée dans le slot `header-actions` de `ChartCard`
  (le composant partagé `ChartCard.vue` n'est **pas** modifié).
- Clé i18n : `dashboard.win_loss_be_help`.

> « Le seuil de BE se règle dans Mon compte › Préférences. Les trades dont le
> résultat est compris dans ce seuil sont comptés comme breakeven. »

### #11 — Matrice « Valeur du point » (`AssetsTab`)

- Fichier : `frontend/src/components/account/AssetsTab.vue`
- L'icône est placée à côté de l'en-tête groupé « Valeur du point »
  (`data-testid="header-group-point-value"`), onglet Mon compte › Actifs.
- Clé i18n : `symbols.point_value_help`.

> « Valeur du point = gain/perte par point de variation du prix, pour 1 contrat,
> dans la devise du compte. Pour les cryptos, elle dépend de la taille de contrat
> de ton broker — vérifie la spécification du contrat (souvent : 1 point = la
> taille de contrat × 1 unité de devise). En cas de doute, calcule-la sur un trade
> connu : (P&L réalisé ÷ nombre de points parcourus) ÷ taille. »

## Accessibilité

Chaque icône porte `role="img"` et un `aria-label` reprenant le texte de
l'infobulle, afin que l'information reste accessible sans survol (lecteurs
d'écran) — c'est aussi ce que vérifient les tests.

## i18n

Clés ajoutées dans `fr.json` et `en.json` (synchronisées) :

| Clé | fr | en |
|-----|----|----|
| `dashboard.win_loss_be_help` | ✅ | ✅ |
| `symbols.point_value_help` | ✅ | ✅ |

## Tests (TDD)

- `frontend/src/components/dashboard/__tests__/WinLossChart.spec.js`
  (no-data, rendu, titre, présence du hint BE + `aria-label`).
- `frontend/src/components/account/__tests__/AssetsTab.spec.js`
  (présence du hint valeur du point + `aria-label`, mention crypto/contrat).

Les deux suites sont vertes (6 tests).

## Portée

Changement **front-only** : aucune migration, aucun changement d'API, aucune
donnée persistée. Pas de risque sécurité/privacy (texte statique, pas de donnée
utilisateur).
