# 113 — Le seeder de démo ne tue plus le démarrage de l'API

## Fonctionnalités

### Le défaut

Le 2026-09-24, le déploiement de l'API sur l'environnement de test est parti en
**boucle de crash**. Les logs de déploiement répètent la même séquence, une fois
par seconde :

```
==> Running migrations...      Nothing to migrate. All 44 migrations already applied.
==> Bootstrapping admin...     already ADMIN. Nothing to do.
==> Seeding demo data...       Demo user already exists (id=209, active). Cleaning up...
PHP Fatal error: Uncaught PDOException: SQLSTATE[23000]: Integrity constraint violation:
1451 Cannot delete or update a parent row: a foreign key constraint fails
(`trading_plans`, CONSTRAINT `fk_plan_symbol` FOREIGN KEY (`symbol_id`) REFERENCES `symbols` (`id`)
ON DELETE RESTRICT) in /var/www/api/database/seed-demo.php:51
```

Deux défauts se cumulaient, et le second est le plus grave :

1. **Le nettoyage du seeder s'en remettait aux cascades.** Quand le compte démo
   existe déjà, `seed-demo.php` le supprime pour le recréer. Il ne supprimait que
   la ligne `users`, en comptant sur les cascades pour le reste. Or le compte
   démo possède à la fois des **symboles** et un **plan de trading qui pointe sur
   l'un d'eux**, et `fk_plan_symbol` est en `ON DELETE RESTRICT`
   ([migration 042](../api/database/migrations/042_trading_plan_symbol.sql)) :
   supprimer le symbole est refusé tant que le plan existe. Que la cascade y
   arrive ou non **dépend du moteur**, voir plus bas.
2. **Un seeder qui échoue empêchait l'API de démarrer.** `docker/entrypoint.sh`
   tourne sous `set -e` : l'erreur fatale du seeder tuait le script avant le
   `php -S` final. Le conteneur redémarrait, rejouait le seeder, replantait. Des
   données de démonstration bloquaient donc le service entier.

C'est un piège **pour tous les environnements** : l'entrypoint joue le seeder à
chaque démarrage, en test comme en production. Les deux bases contiennent un
compte démo avec un plan de trading. Autrement dit, la prod était à un
redémarrage d'une boucle de crash — ce qui correspond au symptôme rencontré le
2026-09-21 (404 Railway, services relancés à la main), sans que ce lien ait pu
être vérifié dans les logs, qui ne remontaient plus si loin.

### Ce qui change

- **Le seeder supprime explicitement les plans de trading du compte démo** avant
  de supprimer le compte. Plus aucune dépendance à l'ordre des cascades.
- **Un seeder en échec ne bloque plus le démarrage** : l'entrypoint journalise et
  continue, comme le fait déjà `bootstrap-admin.php`. L'API démarre, avec des
  données de démo éventuellement périmées — ce qui est toujours préférable à une
  API qui ne démarre pas.

## Choix d'implémentation

### Ce n'est pas une différence MariaDB / MySQL, c'est une version de MySQL

Première hypothèse, fausse : « MariaDB tolère, MySQL refuse ». Vérifié à la main
le 2026-09-24, sur la même forme de données (le plan et le symbole appartiennent
tous deux au compte démo) :

| Moteur | `DELETE FROM users` avec plan + symbole |
|---|---|
| MariaDB 11.4.9 (base locale du journal, port 3307) | accepté |
| MySQL 8.4.7 (local, base jetable montée pour l'essai) | accepté |
| MySQL 9.7.2 (Railway, test et prod) | **refusé, erreur 1451** |

La conclusion pratique : aucune machine de développement ne reproduit la panne
aujourd'hui. Il ne faut donc pas écrire de code qui dépende de la façon dont le
moteur ordonne ses cascades — d'où la suppression explicite, qui est correcte
partout.

### Pourquoi supprimer les plans plutôt qu'assouplir la contrainte

`fk_plan_symbol` est délibérément en `RESTRICT` : la migration 042 explique que
`NULL` veut dire « tous les actifs », donc un `SET NULL` transformerait
silencieusement un plan ciblé en plan universel. La contrainte est bonne, c'est
le nettoyage du seeder qui doit s'y plier. C'est aussi la seule contrainte
`RESTRICT` du schéma : la correction est locale et complète.

## Couverture des tests

| Fichier | Test | Scénario |
|---|---|---|
| `api/tests/Integration/Database/SeedDemoTest.php` | `testSeedsTwiceInARowAsAContainerRestartDoes` | Le seeder joué deux fois de suite, comme à chaque redémarrage de conteneur, sort en 0 et passe bien par le nettoyage |
| | `testLeavesExactlyOneDemoUserOwningATradingPlan` | Un seul compte démo après deux passages, et il possède toujours un plan pointant sur l'un de ses symboles — sans quoi le test ne couvrirait plus le scénario |

**Limite assumée** : ces tests ne peuvent pas virer au rouge sur une machine de
développement, puisque ni MariaDB 11.4 ni MySQL 8.4 ne refusent la cascade. Ils
gardent le reste : qu'un second amorçage réussisse et laisse une base propre. La
preuve du correctif est le démarrage de l'API sur l'environnement de test, qui
tourne sur MySQL 9.7.
