# Étape 95 — Budget quotidien de requêtes par connexion

## Résumé

Une connexion qui a dépensé son quota de requêtes chez son broker dans la journée **ne synchronise plus** jusqu'au lendemain. Plafond par défaut **1500**, réglable depuis le back-office admin, `0` = illimité. Migration **041**.

C'est le dernier garde-fou de l'évolution **#22**, écarté du lot du 2026-08-09 parce qu'il demandait une migration et une surface admin.

## Pourquoi

Le 2026-08-07, FTMO a désactivé le compte de trading 7589848 « due to the amount of activity », seuil annoncé **2 000 requêtes serveur par jour**. Aucun EA n'était branché dessus : seule la synchronisation du journal l'interrogeait, en lecture seule.

Le refactor du 09/08 ([doc 90](90-ctrader-budget-requetes.md)) a ramené un cycle de 19 requêtes à 9, et la mesure réelle du 13/08 donne **648 requêtes/jour et par connexion** au rythme actuel. On est donc largement sous le plafond — mais rien n'empêchait jusqu'ici un intervalle mal réglé, une boucle de synchro ou une multiplication de connexions de le franchir sans que personne ne le voie.

C'est le seul point du backlog dont la conséquence — la perte d'un compte financé chez une prop firm — ne se rattrape pas par un correctif.

## Le compteur

Deux colonnes sur `broker_connections` :

| Colonne | Rôle |
|---|---|
| `requests_today` | Requêtes dépensées |
| `requests_counted_on` | **Le jour auquel ce compteur appartient** |

Porter le jour dans la ligne rend la remise à zéro **implicite** : un incrément un jour différent écrase au lieu d'additionner. Pas de tâche de purge, pas de fenêtre glissante à entretenir. `UTC_DATE()` des deux côtés, comme `syncing_since` et `refreshed_at`, pour que la frontière de journée ne dépende pas du fuseau de session.

L'incrément est un `UPDATE` conditionnel unique, pas un lire-puis-écrire : plusieurs workers peuvent terminer des synchros à quelques secondes d'écart, et un lire-puis-écrire perdrait un incrément — sous-compter est précisément ce que ce garde-fou ne peut pas se permettre.

## Par connexion, pas par provider — et MetaTrader s'y branchera

Le plafond d'une prop firm porte sur un **compte de trading**, quel que soit le protocole qui l'interroge. Le compteur est donc porté par la connexion.

`BrokerSyncService` alimente ce compteur avec ce que le connecteur déclare avoir mis sur le fil, via le contrat déjà existant :

```php
if (method_exists($connector, 'getRequestCounts')) { … }   // ['total' => int, 'by_type' => array]
```

**Rien ici n'est spécifique à cTrader.** MetaTrader couvre les mêmes brokers, donc les mêmes plafonds : le jour où `MetaApiConnector` exposera `getRequestCounts()`, il alimentera ce compteur et respectera ce plafond sans une ligne de plus.

Défaut corrigé au passage : la ligne `sync_request_budget` était étiquetée `job="ctrader"` en dur. Elle porte désormais le provider de la connexion — des requêtes MetaTrader classées sous le nom de cTrader auraient été pires que pas de ligne du tout.

Un provider **sans** compteur de requêtes (Ouinex, BingX aujourd'hui) ne fait jamais monter le compteur, donc le plafond ne mord jamais pour lui. C'est la forme voulue : ce garde-fou borne une dépense **mesurée**, pas une dépense supposée.

## Atteindre le plafond n'est pas une panne

C'est **notre** décision d'arrêter de demander, prise pour protéger le compte. Le traitement est celui d'un report de rate limit :

- pas de ligne `FAILED` dans `sync_logs` — le contrôle a lieu **avant** toute réservation et toute écriture de log, donc une connexion plafonnée ne coûte ni réservation ni ligne par minute ;
- pas d'`incrementFailures`, pas de disjoncteur — compter une pause volontaire comme un échec finirait par désactiver une connexion parfaitement saine ;
- le superviseur la compte en `deferred` ;
- une ligne `daily_budget_reached` part dans les logs, avec la dépense et le plafond.

La synchronisation repart d'elle-même au basculement de jour UTC.

## Réglage

| Où | Clé |
|---|---|
| Back-office admin | `broker_daily_request_budget` (priorité) |
| Variable d'env | `BROKER_DAILY_REQUEST_BUDGET` |
| Défaut | **1500** |

`0` désactive le plafond. La valeur laisse de la marge sous les 2 000 de FTMO tout en restant très au-dessus des 648 mesurés. **Le clic manuel de synchronisation y est soumis aussi** : il dépense la même allocation que le scheduler.

## Tests

| Fichier | Portée |
|---|---|
| `tests/Integration/Repositories/BrokerConnectionRepositoryTest.php` | 5 tests : compteur vierge, cumul dans la journée, **remplacement** de la veille au lieu d'addition, cloisonnement par connexion, et `0` requête qui n'écrit rien |
| `tests/Unit/Services/Broker/BrokerSyncServiceTest.php` | 5 tests : refus au plafond **sans réservation ni sync_log**, passage sous le plafond, `0` = illimité, comptage de ce que le run a dépensé, et la ligne budget étiquetée du provider de la connexion |
| `tests/Unit/Services/Broker/BrokerSyncSchedulerServiceTest.php` | 1 test : report sans échec, sans disjoncteur, sans log d'erreur — y compris sur une connexion déjà au seuil de désactivation |

Suites complètes : **1103 unitaires**, **748 intégration**.

## À vérifier après déploiement

Rien ne doit changer : à 648 requêtes/jour, le plafond ne mord pas. Le témoin est l'absence de ligne `daily_budget_reached` et la ligne `sync_request_budget` désormais étiquetée `job="ctrader"` **parce que la connexion est cTrader**, et non parce que c'était écrit en dur.
