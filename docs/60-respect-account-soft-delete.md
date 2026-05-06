# Étape 60 — Respect du soft-delete des comptes dans les stats et listings

## Résumé

Bug fonctionnel : la suppression d'un compte est implémentée en **soft-delete** (`accounts.deleted_at = NOW()`), mais aucune des requêtes d'agrégation ou de listing de trades/positions ne filtrait sur ce flag. Conséquence : un compte « supprimé » disparaissait de la liste des comptes (UI) mais ses trades continuaient d'être pris en compte dans toutes les stats globales, et restaient visibles dans l'historique des trades en navigation « Tous comptes ».

Source du besoin : retour utilisateur — « si je supprime un compte, on dirait que les données de ce compte sont encore prise en compte dans les stats ».

## Surface impactée

Cinq endroits de code ont été corrigés :

| Fichier | Méthode | Couverture |
|---|---|---|
| `api/src/Repositories/StatsRepository.php` | `buildWhereClause` (helper privé) | Couvre 16 méthodes d'un coup : `getOverview`, `getCumulativePnl`, `getWinLossDistribution`, `getPnlBySymbol`, `getStatsBySymbol`, `getStatsByDirection`, `getStatsBySetup`, `getStatsForSetupCombination`, `getStatsByPeriod`, `getRrDistribution`, `getHeatmap`, `getTradesForSessionStats`, `getStatsByAccount`, `getStatsByAccountType`, `getRecentTrades`, `getDailyPnl` |
| `api/src/Repositories/StatsRepository.php` | `getOpenTrades` | Panneau « En cours » du dashboard (a son WHERE en propre, hors helper) |
| `api/src/Repositories/TradeRepository.php` | `findAllByUserId` | Listing principal de l'historique des trades (count + select) |
| `api/src/Repositories/PositionRepository.php` | `findAllByUserId` | Listing des positions |
| `api/src/Repositories/PositionRepository.php` | `findAggregatedByUserId` | Agrégat des positions ouvertes (PRU multi-trades) |

## Pattern technique : EXISTS plutôt que JOIN

Pour exclure les trades/positions liés à un compte soft-deleted, deux options étaient possibles :

1. **EXISTS** dans la WHERE : `AND EXISTS (SELECT 1 FROM accounts a WHERE a.id = p.account_id AND a.deleted_at IS NULL)`
2. **INNER JOIN explicite** sur `accounts` avec la condition dans la clause `ON`

Choix retenu : **EXISTS**, pour deux raisons :

- **Centralisation** : 16 des 19 requêtes corrigées passent déjà par `StatsRepository::buildWhereClause`. Une seule ligne dans le helper couvre tout — et toute future requête de stats hérite gratuitement de l'invariant. Avec un INNER JOIN il aurait fallu modifier les 16 requêtes individuellement, et chaque future requête devrait penser à l'ajouter (risque d'oubli).
- **Cohérence avec les autres soft-delete du projet** : `CustomFieldValueRepository` filtre déjà sur `cfd.deleted_at IS NULL` dans la WHERE plutôt que via JOIN.

**Côté performances**, les deux approches sont équivalentes : MariaDB réécrit `EXISTS` sur clé primaire en semi-join (`FirstMatch(a)` dans le plan d'exécution). L'`INNER JOIN` aurait abouti au même plan. La table `accounts` est de toute façon minuscule (quelques dizaines de lignes par utilisateur) et `accounts.id` est PK → lookup en O(log n) ou via cache buffer.

## Périmètre volontairement exclu

Les méthodes suivantes **ne** filtrent **pas** sur `deleted_at IS NULL`, par choix :

- `TradeRepository::findById`, `PositionRepository::findById` : accès direct par ID (lien sauvegardé, contexte admin). Le 404 sur compte supprimé serait cassant pour les workflows de restauration.
- `TradeRepository::findByIdsForUser` : check d'ownership en bulk, ne sert pas à l'affichage. Si le trade n'apparaît pas dans la liste (parce que son compte est supprimé), aucun bulk action n'est déclenchable dessus de toute façon.
- `TradeRepository::sumRealizedPnlForAccount[Since]` : prend un `account_id` explicite en paramètre. Le service appelant a déjà résolu un compte vivant côté `AccountRepository`.
- CRUD bas niveau (`update`, `delete`, `create`) : opérations ciblées par ID, hors scope.

## Tests

Six tests d'intégration ajoutés (cycle TDD strict — RED puis GREEN) :

| Fichier de test | Test | Couvre |
|---|---|---|
| `StatsRepositoryTest.php` | `testGetOverviewExcludesTradesFromSoftDeletedAccount` | Le helper `buildWhereClause` |
| `StatsRepositoryTest.php` | `testGetStatsByAccountExcludesSoftDeletedAccount` | Méthode qui JOIN explicitement `accounts` |
| `StatsRepositoryTest.php` | `testGetOpenTradesExcludesSoftDeletedAccount` | Méthode hors helper |
| `TradeRepositoryTest.php` | `testFindAllByUserIdExcludesTradesFromSoftDeletedAccount` | Listing trades |
| `PositionRepositoryTest.php` | `testFindAllByUserIdExcludesPositionsFromSoftDeletedAccount` | Listing positions |
| `PositionRepositoryTest.php` | `testAggregatedExcludesSoftDeletedAccount` | Agrégat positions ouvertes |

Suite complète : 1115 tests passants, zéro régression.

## Convention pour les futures requêtes

Toute nouvelle requête d'agrégation ou de listing dans `StatsRepository` qui passe par `buildWhereClause` est **automatiquement** correcte vis-à-vis du soft-delete des comptes.

En revanche, toute nouvelle requête **hors helper** (un nouveau dashboard, un export, etc.) doit explicitement ajouter le filtre :

```sql
AND EXISTS (SELECT 1 FROM accounts a WHERE a.id = p.account_id AND a.deleted_at IS NULL)
```

ou, si la requête JOIN déjà `accounts a`, ajouter `AND a.deleted_at IS NULL` à la clause `ON` ou `WHERE`.
