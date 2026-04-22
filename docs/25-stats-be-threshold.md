# 25 — Seuil BE pour la classification des stats

## Contexte

Aujourd'hui, toutes les statistiques classent les trades en **win / loss / break-even** en se basant exclusivement sur `trades.pnl` :

- `pnl > 0` → win
- `pnl < 0` → loss
- `pnl = 0` → BE

Cette règle binaire pose problème dès que le **spread** entre dans l'équation : un trade clôturé "à break-even" dans la tête du trader sort en pratique en très léger profit (+1 €) ou très légère perte (-2 €) à cause du spread. Résultat, ces trades gonflent faussement les compteurs de wins / losses et dégradent la lisibilité des stats — notamment :

- le **win rate**,
- la **répartition win/loss/BE**,
- le **profit factor**,
- toutes les stats par dimension (symbole, direction, setup, période, compte, type de compte).

Le problème s'accentue avec l'import automatique depuis un broker ou une plateforme de trading : le `exit_type` n'est pas forcément remonté comme `BE`, et même s'il l'était, il n'est pas utilisé pour la classification statistique actuelle — seul `pnl` compte.

## Demande

Permettre à l'utilisateur de définir un **seuil de tolérance en % du prix** en dessous duquel un trade est considéré comme BE dans les statistiques, afin que les quasi-BE soient comptés comme tels plutôt que comme micro-wins ou micro-losses.

## Scope

### Dans le scope

- Ajout d'une **préférence utilisateur** "seuil BE" (en % du prix d'entrée).
- Application de ce seuil à la **classification win / loss / BE côté stats uniquement**.
- Mise à jour de toutes les requêtes du `StatsRepository` qui classent via `pnl`.

### Hors scope

- La **saisie manuelle** d'un trade reste inchangée : l'utilisateur peut toujours choisir `BE` dans le dialog de clôture, rien ne change côté UX de saisie.
- La **donnée brute** reste intacte : `trades.exit_type` et `trades.pnl` ne sont **jamais réécrits**. Le seuil est appliqué à la lecture au moment du calcul des stats — pas de mutation en base, pas de recalcul rétroactif.
- Pas de seuil par compte dans cette itération : préférence **globale user**. Pourra évoluer plus tard si besoin (spreads différents selon broker).

## Conception

### Unité du seuil : `pnl_percent`

Le seuil est exprimé en **pourcentage du prix d'entrée** (pour rester valide sur tous les instruments, toutes devises). On l'applique sur la colonne déjà stockée `trades.pnl_percent`, qui vaut `pnl / (entry_price × size) × 100`.

Choix délibéré : on ne compare pas `|avg_exit_price − entry_price| / entry_price` parce qu'un trade avec sortie partielle (TP) puis sortie finale quasi-entry n'est pas un BE global — c'est un **partial win**. Seul le `pnl_percent` final reflète correctement la classification attendue.

### Règle de classification

Soit `tol` = `users.be_threshold_percent`.

- **BE** : `-tol ≤ pnl_percent ≤ tol`
- **Win** : `pnl_percent > tol`
- **Loss** : `pnl_percent < -tol`

Les trois branches sont exclusives et couvrent tout l'axe. Quand `tol = 0`, le comportement est **rigoureusement identique à l'actuel** (pas de régression pour les users qui n'activent pas le seuil).

### Stockage

Nouvelle colonne sur la table `users` :

```sql
be_threshold_percent DECIMAL(6,4) NOT NULL DEFAULT 0
```

- Précision suffisante pour gérer des seuils fins (ex. `0.0200` = 0.02 %).
- Valeur par défaut `0` → comportement actuel préservé à la migration.
- Migration dédiée dans `api/database/migrations/`.

### API & UI

- Exposition dans `/api/users/profile` (GET + PUT) — même endpoint que les autres préférences.
- Édition dans la vue profil, section "Préférences statistiques" (ou équivalent selon l'organisation existante).
- Validation : nombre ≥ 0, borne haute raisonnable (ex. `≤ 5`) pour éviter les saisies absurdes.

### Impact stats

Méthodes de `StatsRepository` à mettre à jour (toutes celles qui classent via `pnl` dans un `CASE WHEN`) :

- `getOverview` (winning_trades, losing_trades, be_trades, win_rate, profit_factor)
- `getWinLossDistribution`
- `dimensionStatsSelect` (utilisé par `getStatsBySymbol`, `getStatsByDirection`, `getStatsBySetup`, `getStatsByPeriod`, `getStatsByAccount`, `getStatsByAccountType`)

Non concernées (pas de classification win/loss) : `getCumulativePnl`, `getPnlBySymbol`, `getRrDistribution`, `getHeatmap`, `getDailyPnl`, `getRecentTrades`, `getOpenTrades`, `getTradesForSessionStats`.

Le seuil est **lu une fois** dans `StatsService` (via `UserRepository`) puis passé en paramètre lié (`:be_threshold`) à chaque requête du repo.

## TDD

Cas à couvrir dans `StatsRepositoryTest` :

1. `tol = 0` → comportement identique à l'actuel (non-régression).
2. Trade à `pnl_percent = +0.01`, `tol = 0.02` → classé **BE**.
3. Trade à `pnl_percent = -0.01`, `tol = 0.02` → classé **BE**.
4. Trade à `pnl_percent = +0.10`, `tol = 0.02` → classé **win**.
5. Trade à `pnl_percent = -0.10`, `tol = 0.02` → classé **loss**.
6. Trade à `pnl_percent = 0.02` pile → classé **BE** (inclusif).
7. Vérifier que win_rate, profit_factor, distributions reflètent la nouvelle classification.

Cas à couvrir côté `StatsService` : bonne récupération du seuil user, bonne propagation jusqu'au repo.

Cas UI / profil : champ éditable, validation, persistance.

## Livrables

- Migration SQL + mise à jour `schema.sql`.
- Modèle user + repo : exposition du champ.
- `StatsRepository` + `StatsService` : prise en compte du seuil.
- Endpoint profil (lecture/écriture).
- Vue profil : champ d'édition.
- i18n fr + en (libellé champ, aide, erreurs validation).
- Tests (PHPUnit + Vitest).
- Mise à jour de `docs/18-performance.md` pour expliquer la règle de classification appliquée côté stats.
