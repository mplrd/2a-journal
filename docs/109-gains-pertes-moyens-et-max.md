# 109 — Gains et pertes : moyenne et maximum

## Fonctionnalités

Page **Performance**, graphique **« Répartition gains / pertes »**, bouton
**« Voir le détail »**. La modale, désormais titrée du nom du graphique, affiche
sous le tableau par direction un bloc **« Montant des gains et des pertes »** :

```
                         Pertes (↓)  │  (↑) Gains
                          16 trades  │  29 trades

MOYENNE      -58.75  ▇▇▇▇▇▇▇▇▇▇▇▇▇▇ │ ▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇  +108.28
MAXIMUM          -120.00  ▇▇▇▇▇▇▇▇ │ ▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇  +750.00
```

- **Moyenne** : moyenne du P&L des trades perdants (à gauche, négative) et des
  trades gagnants (à droite).
- **Maximum** : la plus grosse perte (le P&L le plus négatif) et le plus gros
  gain.
- Chaque camp est coiffé de **son nombre de trades** : un montant se lit à côté
  du compte dont il est tiré.

### Lecture du graphique

Un graphique « papillon » : **les pertes à gauche de l'axe zéro, les gains à
droite**, le montant au bout de sa barre.

- **Chaque ligne a sa propre échelle** : le plus gros des deux côtés occupe toute
  la largeur, l'autre en est une fraction. La ligne Moyenne se lit donc « ma perte
  moyenne pèse un peu plus de la moitié de mon gain moyen », sans être écrasée par
  un gain maximum hors norme.
- **La couleur n'est jamais le seul repère** : côté de l'axe, flèche ↓ / ↑,
  libellé et signe disent aussi gain ou perte (voir Choix d'implémentation,
  § Accessibilité).
- La mention **« Trades breakeven exclus »** rappelle ce que les moyennes laissent
  de côté.

### Sur quels trades

**Exactement ceux que compte le camembert.** Un trade est gagnant, perdant ou
breakeven selon le **seuil de BE** du profil
([25](25-stats-be-threshold.md)), et selon le signe de son P&L quand il n'a pas
de pourcentage ([98](98-classement-trade-sans-pourcentage.md)).

Conséquences :

- un trade **breakeven** ne pèse ni sur le gain moyen ni sur la perte moyenne :
  un seuil de BE à 0,02 % sort les « +1 € » et « −2 € » des deux moyennes ;
- un trade encore ouvert mais **déjà partiellement sorti** compte avec le P&L
  qu'il a réalisé jusque-là, comme dans le camembert.

Le bloc **suit les filtres de la page** : compte(s), période, direction,
symboles, setups. Filtrer sur `SELL` donne les montants des seuls trades
vendeurs.

### Cas limites

- **Un camp sans trade** (aucune perte, par exemple) : pas de barre, un `-` contre
  l'axe, « 0 trade ». Jamais un faux `0.00`.
- **Pertes plus lourdes que les gains** : c'est la barre rouge qui prend toute la
  largeur.
- **Montants à cinq chiffres** (`+98765.43`) : ils tiennent dans la réserve prévue
  au bout d'une barre pleine.
- **Mobile** : le libellé de ligne passe au-dessus des barres, le papillon garde
  toute la largeur.
- Les autres modales « Voir le détail » (symbole, setup, session…) gardent leur
  titre et leur contenu.

### Limites

- **Pas de conversion de devise.** Le P&L est en devise du compte
  ([106](106-pnl-en-devise-du-compte.md)) : une sélection de comptes en devises
  différentes additionne des montants qui ne s'additionnent pas. C'est déjà le
  cas du total P&L et de « Meilleur / Pire trade ». Le R reste l'unité
  transverse.
- **L'historique en points** cohabite avec les trades en devise tant qu'il n'est
  pas repricé (106, § « Conséquence assumée ») : les moyennes mélangent les deux
  unités chez un utilisateur concerné.

## Choix d'implémentation

### Pas de nouvel endpoint

Les quatre montants sont ajoutés au bloc `win_loss` de **`GET /stats/charts`**,
celui qui alimente déjà le camembert et que la page charge avec ses filtres :

```json
"win_loss": {
  "win": 29, "loss": 16, "be": 3,
  "avg_win": 108.28, "avg_loss": -58.75,
  "max_win": 750.0, "max_loss": -120.0
}
```

L'ajout est **additif** : le tableau de bord (`KpiCards`, qui reçoit le même
bloc) ignore les nouveaux champs.

### Une seule requête, la même classification

Les agrégats s'ajoutent au `SELECT` de `StatsRepository::getWinLossDistribution()`
et reprennent `isWin()` / `isLoss()`, les expressions qui produisent déjà les
comptes. Un montant ne peut donc pas porter sur d'autres trades que le nombre
affiché à côté — c'était l'enjeu principal, le seuil de BE et le repli sur le
signe du P&L ayant chacun leur historique de décalages.

```sql
ROUND(AVG(CASE WHEN <gagnant> THEN t.pnl END), 2) AS avg_win,
ROUND(AVG(CASE WHEN <perdant> THEN t.pnl END), 2) AS avg_loss,
MAX(CASE WHEN <gagnant> THEN t.pnl END)           AS max_win,
MIN(CASE WHEN <perdant> THEN t.pnl END)           AS max_loss
```

- Un `CASE` **sans `ELSE`** vaut NULL hors de sa case, et `AVG` / `MAX` / `MIN`
  ignorent les NULL. Une case vide donne NULL, pas 0 : un 0 serait un montant
  faux, indiscernable d'un vrai zéro.
- Les moyennes sont arrondies à deux décimales en SQL, comme `avg_rr`. Les
  extrêmes ne le sont pas : ce sont des P&L réels.
- **Les pertes restent signées** : `max_loss` est un `MIN`, la valeur la plus
  négative.

### Global plutôt que par direction

Deux formes ont été proposées : des montants globaux ou une ventilation
BUY / SELL / Total. **Le global a été retenu** : le ticket demandait les montants
sur l'ensemble des trades, la ventilation par direction existe déjà dans le
premier tableau, et le filtre direction de la page donne les montants d'un seul
sens.

### Pourquoi un papillon plutôt qu'un tableau

La première version posait un `DataTable` 2 × 2 sous le tableau par direction :
l'information y était, mais sans hiérarchie, sans lien avec les comptes du
camembert, et sans rien qui aide à comparer un gain à une perte — alors que
c'est précisément la question du ticket. Elle a été reprise.

- **Polarité → barres divergentes** autour d'un axe zéro unique : pertes à
  gauche, gains à droite, dans le sens d'une droite numérique.
- **Une échelle par ligne** (petits multiples) plutôt qu'une échelle commune : un
  maximum à 750 ramènerait la ligne Moyenne (108 contre 59) à deux traits
  illisibles. Les montants restent écrits au bout des barres, rien ne se lit à la
  seule longueur.
- **Le montant au bout de sa barre**, pas dans une colonne lointaine : au premier
  jet en colonnes fixes, `-120.00` flottait loin de sa courte barre rouge.
- **Libellés de ligne dans une colonne à gauche**, alignés sur les barres ; l'axe
  est placé au centre de la zone restante (variables CSS `--label-col` /
  `--label-gap`) et tracé **une seule fois** à travers en-têtes et lignes. En
  dessous de 640 px, la colonne disparaît et le libellé passe au-dessus.
- **Marques** : barres de 12 px, extrémité arrondie côté donnée et carrée côté
  axe, 2 px de vide entre chaque barre et l'axe (hairline 1 px). Montants en
  police mono à chiffres tabulaires, conformément à la charte.
- **Réserve de 7 rem** (4,75 rem en mobile) côté extérieur de chaque moitié, pour
  que le montant d'une barre pleine ne déborde pas.

### Accessibilité

La paire rouge / vert de la charte (`#b5384a` / `#2d7952`) a été passée au
validateur de palette : **ΔE 3,5 en deutéranopie**, sous le plancher de 8. Elle
est indistinguable pour une partie des daltoniens. La palette de marque, utilisée
dans toute l'application, n'a pas été modifiée ; le composant garantit plutôt que
**la couleur ne porte jamais seule l'information** : côté de l'axe, flèche,
libellé « Gains » / « Pertes » et signe `+` / `-`. Les barres sont
`aria-hidden`, les montants restent du texte.

### Frontend

- **`WinLossAmounts.vue`** (nouveau, `components/performance/`) prend le bloc
  `win_loss` en prop, calcule les largeurs relatives de chaque ligne et rend le
  papillon.
- **`StatsDetailDialog.vue`** reçoit un **slot par défaut** rendu sous son
  tableau, et une prop **`header`** qui remplace le titre de dimension. La modale
  reste générique : elle ne connaît pas les montants.
- **`PerformanceView.vue`** ouvre la modale du camembert avec
  `openDetail('direction', { withWinLossAmounts: true })`, qui ajoute le bloc et
  titre la modale « Répartition gains / pertes » — elle ne contient plus
  seulement la ventilation par direction. Le tout est lié **au graphique, pas à
  la dimension** `direction` : une future modale par direction ouverte depuis un
  autre graphique n'en héritera pas, et chaque ouverture remet l'option à faux.

### Sécurité et données

- Aucune entrée nouvelle. Les filtres passent par
  `StatsService::validateFilters()`, qui refuse un compte qui n'appartient pas à
  l'utilisateur (403).
- Le périmètre reste `p.user_id` de l'utilisateur authentifié, comptes supprimés
  exclus (`buildWhereClause()`).
- Le seuil de BE est injecté en littéral numérique borné, comme avant.
- La route reste sous authentification et abonnement.
- La réponse ne porte que quatre agrégats, aucune donnée par trade.

## Couverture des tests

### Backend

`api/tests/Integration/Repositories/StatsRepositoryTest.php`

| Test | Scénario | Statut |
|---|---|---|
| `testGetWinLossDistributionAveragesAndExtremesEachBucket` | +100 / +200 / +50 / 0 / −50 / −30 → gain moyen 116.67, gain max 200, perte moyenne −40, perte max −50 | ✅ |
| `testGetWinLossDistributionAmountsLeaveBreakevenTradesOut` | seuil 0,02 % : les +1 et −2 sont BE et n'entrent dans aucune moyenne | ✅ |
| `testGetWinLossDistributionAmountsCountTradesWithoutPercentage` | un trade sans pourcentage, classé sur son signe, compte dans les montants | ✅ |
| `testGetWinLossDistributionAmountsAreNullForAnEmptyBucket` | aucun perdant → perte moyenne et perte max à NULL, gains renseignés | ✅ |
| `testGetWinLossDistributionAmountsAreNullWithoutAnyTrade` | aucun trade → structure complète, comptes à 0, montants à NULL | ✅ |

`api/tests/Integration/Stats/StatsFlowTest.php`

| Test | Scénario | Statut |
|---|---|---|
| `testChartsWinLossCarriesAverageAndLargestAmountsWithinTheFilters` | HTTP `GET /stats/charts?account_id=…` : le gain de +200 de l'autre compte ne remonte pas la moyenne | ✅ |

### Frontend

`frontend/src/components/performance/__tests__/WinLossAmounts.spec.js` — vraies
locales.

| Test | Scénario | Statut |
|---|---|---|
| `titles the block and says breakeven trades are left out` | titre et mention « Trades breakeven exclus » | ✅ |
| `heads each side with its count, losses on the left of zero and gains on the right` | « Pertes · 16 trades » avec ↓, « Gains · 29 trades » avec ↑ | ✅ |
| `writes a single trade in the singular` | « 1 trade », jamais « 1 trades » | ✅ |
| `reads the average and the largest amount of each side` | Moyenne / Maximum, `-58.75`, `+108.28`, `-120.00`, `+750.00` | ✅ |
| `colours a gain as a gain and a loss as a loss` | `text-success` / `text-danger` | ✅ |
| `scales each row on its own larger side so the smaller one reads as a share of it` | Moyenne : gain 100 %, perte 54,26 % ; Maximum : gain 100 %, perte 16 % | ✅ |
| `lets the loss side lead when losses weigh more` | perte −80 contre gain 40 → perte 100 %, gain 50 % | ✅ |
| `draws no bar and a dash for a side that holds no trade` | pertes à NULL → `-`, pas de barre, « 0 trade » | ✅ |
| `shows dashes and no bar while the distribution is not loaded` | `data` null → quatre `-`, aucune barre | ✅ |

`frontend/src/components/performance/__tests__/StatsDetailDialog.spec.js`

| Test | Scénario | Statut |
|---|---|---|
| `renders the extra content it is given below the table` | le contenu du slot vient après le tableau | ✅ |
| `renders the table alone when given nothing else` | sans slot, rien n'est ajouté | ✅ |
| `titles itself after its dimension by default` | sans `header` → « Par direction » | ✅ |
| `takes the title it is given over the dimension one` | `header` fourni → il l'emporte | ✅ |

`frontend/src/__tests__/performance-view.spec.js`

| Test | Scénario | Statut |
|---|---|---|
| `adds the average and largest amounts under the win / loss chart detail` | « Voir le détail » du camembert → modale `direction` titrée « Répartition gains / pertes », avec le bloc alimenté par `charts.win_loss` | ✅ |
| `leaves the amounts out of any other chart detail` | camembert puis « Win Rate & R:R par symbole » → modale `symbol`, titre par défaut, sans le bloc | ✅ |

### Suites complètes

- Backend : **2003 tests, 5100 assertions**, au vert.
- Frontend : **64 fichiers, 602 tests**, au vert.

### Vérifié en navigateur

Les tests Vitest ne voient pas la mise en page. Le rendu a été contrôlé par
captures (Edge headless, compte démo, build local) : thème clair et sombre à
1440 px, mobile à 400 px, et trois cas limites injectés dans le store — aucun
perdant, pertes plus lourdes que les gains, montants à cinq chiffres.

### Non couvert

- **Le mélange de devises** : pas de conversion FX, par conception (voir
  Limites).

## Origine

Ticket support **#40**, qui demandait le gain moyen et la perte moyenne sur
l'ensemble des trades. L'approche — un second bloc dans la modale de détail du
camembert — a été validée sur le ticket avant développement.
