# Étape 30 — Solde courant calculé (Balance)

## Résumé

La colonne `current_capital` de la table `accounts` existait déjà mais n'était jamais mise à jour après la création du compte (zombie field). Résultat : chaque compte apparaissait comme dormant côté UI, peu importe l'activité de trading.

Cette évolution transforme `current_capital` en valeur calculée **à la lecture**, à partir du capital initial et de la somme des P&L des trades clôturés (status = `CLOSED`). Zéro changement de schéma, zéro duplication de concept, zéro nouveau chemin d'écriture. La colonne s'affiche côté UI sous le libellé « Balance / Solde ».

## Fonctionnalités

- Dans la liste « Mes Comptes » et dans le détail d'un compte, la valeur retournée par l'API pour `current_capital` correspond à :
  `initial_capital + Σ(pnl)` sur les trades où `status = 'CLOSED'` rattachés au compte via la position.
- Les trades `OPEN` sont ignorés (pas d'estimation de P&L latent).
- Affichage côté UI : colonne « Balance » entre « Capital initial » et « Broker ». Coloration verte si `current_capital > initial_capital`, rouge si inférieur, neutre si égal.

## Choix d'implémentation

### Calcul à la lecture plutôt qu'à l'écriture
Deux approches possibles :
1. Mettre à jour `accounts.current_capital` à chaque clôture / partial exit / import.
2. Calculer à la volée via `LEFT JOIN` sur `trades` dans les SELECT du repository.

Choix n°2 pour plusieurs raisons :
- **Source de vérité unique** : `trades.pnl` est la donnée primaire, `current_capital` en est une projection.
- **Pas de dérive** possible entre les deux champs (import, correction manuelle, cancel-replace sur un trade, etc.).
- **Aucun nouveau chemin d'écriture** à maintenir (partial exits, imports FTMO/FXCM, connecteurs brokers, seeders démo).
- Coût en lecture négligeable (index sur `trades.position_id`, petite cardinalité par compte).

### Nom du champ inchangé
Le champ reste `current_capital` en base et dans le payload API. Le libellé UI est « Balance / Solde » car c'est le terme attendu par l'utilisateur (pas d'equity : l'app n'a pas de marché live pour calculer le P&L latent).

### Implémentation SQL
Subquery agrégée jointe en `LEFT JOIN` pour conserver les comptes sans trade :

```sql
LEFT JOIN (
    SELECT p.account_id, SUM(t.pnl) AS total
    FROM trades t
    INNER JOIN positions p ON p.id = t.position_id
    WHERE t.status = 'CLOSED'
    GROUP BY p.account_id
) pnl ON pnl.account_id = a.id
```

Le `COALESCE(pnl.total, 0)` garantit que les comptes sans trade retournent bien `initial_capital`.

Appliqué à la fois dans `findById` et `findAllByUserId`.

## Couverture des tests

| Test | Scénario | Attendu |
|------|----------|---------|
| `testCurrentCapitalEqualsInitialCapitalWhenNoTrades` | Compte créé, aucun trade | `current_capital == initial_capital` |
| `testCurrentCapitalSumsClosedTradesPnl` | 3 trades clôturés (+250, -100, +50) sur capital 10000 | `current_capital == 10200` |
| `testCurrentCapitalIgnoresOpenTrades` | 1 CLOSED (+300) + 1 OPEN sur capital 5000 | `current_capital == 5300` |
| `testCurrentCapitalIsScopedPerAccount` | 2 comptes avec trades distincts | chaque compte a son propre solde |
| `testFindByIdReturnsComputedCurrentCapital` | findById sur un compte avec trade clôturé | champ calculé présent dans la réponse |

Fichier : `api/tests/Integration/Repositories/AccountRepositoryTest.php`.
Les 13 tests de ce fichier passent en vert. Suite complète PHPUnit : 924 tests OK.

## Côté frontend

Fichier : `frontend/src/views/AccountsView.vue`.
- Nouvelle colonne `current_capital` avec header `t('accounts.balance')`.
- Helper `balanceClass(account)` qui renvoie la classe Tailwind à appliquer selon la comparaison avec `initial_capital`.

### i18n
| Clé | FR | EN |
|-----|----|----|
| `accounts.balance` | Solde | Balance |
