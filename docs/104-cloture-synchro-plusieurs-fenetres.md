# 104 — Clôture broker étalée sur plusieurs fenêtres de synchro

## Le problème

Une position fermée en plusieurs fois — des take profits partiels, puis le solde
au stop — pouvait finir avec un P&L totalement faux, et un calendrier encore plus
faux que le trade lui-même.

Constaté en production le 2026-08-28 sur un DAX : deux take profits encaissés
les jours précédents pour 670, le solde de 0,5 lot stoppé à −32, et

- la fiche du trade affichait **−32** au lieu de **+638** ;
- la case du 28 août dans le calendrier de P&L affichait **−702** alors que la
  journée n'avait réalisé que le stop, soit **−32**.

## La cause

Les deals sont récupérés depuis le curseur de synchro
(`BrokerSyncService` → `fetchDeals($credentials, $connection['sync_cursor'])`).
Une fenêtre de synchro ne décrit donc que les jambes tombées **pendant cette
fenêtre**. Les take profits pris l'avant-veille sont déjà en base : écrits dans
`partial_exits`, et cumulés dans `trades.pnl` par le rollup
(`BrokerOpenSyncService::bankRealizedFromExits`).

À la clôture, `transitionToClosed()` écrivait :

```php
'pnl' => $closed['pnl'] ?? null,   // le total de la FENÊTRE, pas de la position
```

Le total annoncé par la fenêtre de clôture ne couvrait que le stop. Il écrasait
le cumul : le trade retombait sur sa dernière jambe seule.

Le calendrier amplifiait ensuite l'incohérence. `StatsRepository::getDailyPnl()`
affiche par jour les jambes du jour, **plus** un résidu imputé à la date de
clôture :

```
résidu = trades.pnl − SUM(toutes les jambes)
```

Ce résidu existe pour rattraper ce qu'un broker annonce au niveau position
au-delà de ses propres jambes (swap, commissions) ; il vaut normalement 0. Ici
il valait `−32 − 638 = −670`, et il atterrissait sur le jour du stop :
`−32 + (−670) = −702`.

Deux colonnes suivaient le même sort :

- `pnl_percent` et `risk_reward` n'étaient pas réécrits du tout à la clôture. Ils
  gardaient la valeur calculée sur le cumul d'avant. Or gagnant / perdant / BE se
  classe sur `pnl_percent` seul (`StatsRepository::isWin`) : le trade pouvait
  afficher une perte et compter comme gagnant dans le taux de réussite ;
- `avg_exit_price` prenait le prix des seules jambes de clôture — le prix du
  stop — en ignorant les take profits.

## Le correctif

`BrokerOpenSyncService::realizedOnClose()` remplace l'écrasement :

```
pnl = Σ(jambes en base) + (total annoncé par la clôture − Σ(ses propres jambes))
```

Les jambes en base font la base. Le broker garde le dernier mot sur **ce qu'il
décrit réellement** : l'écart entre son total et ses propres jambes, c'est le
swap et les commissions portés au niveau position, et il s'ajoute par-dessus.

- Position fermée sur plusieurs fenêtres : `638 + (−32 − (−32)) = 638` ✔
- Position fermée en une fenêtre, frais annoncés en plus :
  `700 + (742,31 − 700) = 742,31` ✔ — comportement inchangé.

Les jambes de clôture sont désormais insérées **avant** le calcul, pour être
comptées dedans. `pnl_percent`, `risk_reward` et `avg_exit_price` (moyenne
pondérée sur toutes les jambes) sont recalculés dans la même écriture.

Aucun changement dans le calendrier : son résidu redevient nul de lui-même dès
que le total du trade colle à ses jambes.

## La reprise des données

Migration `044_repair_pnl_closed_over_several_sync_windows.sql`. Les positions
déjà clôturées de travers ne seront jamais rattrapées par une passe ultérieure —
elles sont fermées, plus rien ne les resynchronise.

Quatre garde-fous cumulés délimitent la signature exacte du bug :

1. trade `CLOSED`, position synchronisée d'un connecteur broker ;
2. au moins deux jambes, dont la somme des tailles couvre exactement la taille de
   la position — la preuve que les jambes décrivent la position entière et que
   leur somme **est** le vrai total ;
3. `pnl` s'écarte de cette somme de plus d'un centime ;
4. `pnl` est égal à la somme d'un **suffixe** des jambes (toutes celles à partir
   d'un certain moment) — exactement ce que produit l'écrasement par une fenêtre
   de clôture. Un écart de frais légitime ne satisfait pas ce test.

La migration est **additive** (elle crée une table, n'en modifie aucune),
**idempotente** (après reprise la détection ne matche plus, et l'`UPDATE` est
gardé par `t.pnl <=> r.old_pnl`) et **réversible** : la table
`trade_pnl_repairs` conserve l'avant et l'après de chaque ligne touchée,
volontairement sans clé étrangère pour survivre à la suppression d'un trade.

Pour lister ce qui a été repris après déploiement :

```sql
SELECT trade_id, old_pnl, new_pnl, old_avg_exit_price, new_avg_exit_price, repaired_at
FROM trade_pnl_repairs
ORDER BY repaired_at DESC;
```

Elle corrige au passage la limite que la migration 039 s'était explicitement
donnée (« sur un trade fermé, c'est le total annoncé par le broker qui fait
autorité ») : ce n'est vrai que si la position se ferme entièrement dans une
seule fenêtre de synchro.

## Vérification

Tests unitaires ajoutés à `BrokerOpenSyncServiceTest` :

- `testAClosingWindowDoesNotEraseWhatEarlierWindowsBanked` — reproduit le cas de
  production (400 + 270 encaissés, stop à −32) et attend 638 ;
- `testTheClosingRefreshesEveryFigureItLeavesBehind` — `pnl_percent`,
  `risk_reward` et `avg_exit_price` recalculés à la clôture ;
- `testTheBrokersClosingTotalIsNotReplacedByTheSumOfItsLegs` (existant) — les
  frais annoncés au niveau position restent portés.

Migration validée en local sur trois cas : la signature du bug (reprise), un
écart de frais légitime (intact), un trade saisi à la main (hors périmètre,
intact). Le calendrier passe de −702 à −32 sur le jour du stop, les jours de take
profit restent inchangés.
