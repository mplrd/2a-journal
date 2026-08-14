# 98 — Classement d'un trade dépourvu de pourcentage

## Le défaut

Les statistiques rangent chaque trade clos dans l'une de trois cases —
**gagnant**, **perdant**, **breakeven** — en comparant `trades.pnl_percent` au
seuil de breakeven du profil.

Ce seuil est un **pourcentage de la valeur d'entrée**. Un trade dont la valeur
d'entrée n'a pas pu être calculée porte donc `pnl_percent = NULL`, et en SQL
comparer NULL au seuil **ne répond ni vrai ni faux**. Le trade n'entrait dans
aucune des trois cases.

Il continuait pourtant d'être compté dans le `COUNT(*)`, qui est le
**dénominateur du taux de réussite**. Conséquence, sur les écrans concernés :

- le camembert affichait **moins de trades que le total annoncé** ;
- le **taux de réussite était tiré vers le bas** en silence, sans qu'aucun
  chiffre visible n'explique l'écart ;
- le **profit factor** ignorait le montant de ces trades, des deux côtés du
  rapport.

Le défaut était déjà décrit — sans être corrigé — dans un commentaire de
`BrokerOpenSyncService::bankRealizedFromExits()`.

## Par où un trade arrive dans cet état

Un seul chemin d'écriture produit un `pnl` renseigné sans pourcentage :
`ImportService::createImportedTrade()`
(`api/src/Services/Import/ImportService.php`). Quand la valeur d'entrée est
nulle — prix ou taille absent du fichier importé alors que le P&L total, lui,
est bien présent — il écrit `NULL`.

**C'est le bon comportement et il n'a pas été modifié.** NULL dit honnêtement
« pourcentage inconnu ». L'alternative, écrire `0`, ferait passer un trade
gagnant de 300 € pour un breakeven : une donnée fausse, et irrécupérable à la
lecture puisque rien ne la distinguerait d'un vrai zéro.

> À noter : `TradeService::calculateRealizedMetrics()` et
> `BrokerOpenSyncService::bankRealizedFromExits()` écrivent `0.0` dans ce même
> cas de figure. Leur branche n'est atteignable que sur une position de valeur
> d'entrée nulle, où le P&L est nul lui aussi — le classement en breakeven y est
> donc juste. Ces deux chemins sont laissés en l'état.

## La correction

Elle est **entièrement en lecture**, dans `StatsRepository`. Le classement, qui
était recopié dans trois blocs SQL, est désormais énoncé une seule fois par
trois méthodes privées — `isWin()`, `isLoss()`, `isBreakeven()` :

```sql
CASE WHEN t.pnl_percent IS NULL
     THEN t.pnl > 0                     -- le signe du P&L décide
     ELSE t.pnl_percent > :be_threshold -- la bande de breakeven s'applique
END
```

Quand le pourcentage manque, **c'est le signe du montant qui tranche**. C'est ce
qui reste de l'information une fois le pourcentage perdu, et il suffit à
distinguer un gagnant d'un perdant.

`trades.pnl` n'est jamais NULL dans ces requêtes — `buildWhereClause()` en fait
le critère d'inclusion (« le trade a réalisé quelque chose »). Les trois
expressions sont donc **mutuellement exclusives et exhaustives** : leur somme
est toujours égale au nombre de trades.

Corriger en lecture répare aussi **les lignes déjà en base**, ce qu'une
correction du chemin d'écriture n'aurait pas fait.

### Portée

| Bloc SQL | Ce qu'il alimente |
|---|---|
| `getOverview()` | Cartes du tableau de bord : trades gagnants / perdants / BE, taux de réussite, profit factor |
| `getWinLossDistribution()` | Camembert répartition |
| `dimensionStatsSelect()` | Toutes les ventilations : par symbole, compte, type de compte, direction, setup, session, jour |

La bande de breakeven, elle, **est inchangée** : un trade qui porte un
pourcentage est classé exactement comme avant.

## Tests

`api/tests/Integration/Repositories/StatsRepositoryTest.php` :

- `testATradeWithoutPercentageIsClassifiedOnTheSignOfItsPnl` — +100 / −50 / 0
  sans pourcentage donnent bien 1 gagnant, 1 perdant, 1 BE ;
- `testTheOverviewBucketsAddUpToTheTotalWhenAPercentageIsMissing` — les trois
  cases somment au total, et le profit factor intègre les montants concernés ;
- `testADimensionBreakdownClassifiesATradeWithoutPercentage` — même règle sur
  les ventilations ;
- `testTheBreakevenThresholdStillAppliesToTradesThatHaveAPercentage` — un trade
  sans pourcentage n'est jamais absorbé par la bande de breakeven.

Le helper `createClosedTrade()` accepte désormais `'pnl_percent' => null` pour
écrire un vrai NULL en colonne.

## Ce que ça change à l'écran

Pour un utilisateur qui n'a aucun trade dans cet état — le cas courant —
**rien ne bouge**. Pour les autres, le camembert retrouve son compte et le taux
de réussite remonte à sa vraie valeur.

## Origine

Ticket support **#39**, où le défaut a été trouvé en analysant les données du
rapporteur. Ses trades n'étaient pas concernés : son écart venait du calendrier,
qui compte les **jours-trades** et non les trades (un trade sorti en plusieurs
fois, à des jours différents, apparaît à juste titre sur chacun de ces jours).
