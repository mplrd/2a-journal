# Étape 97 — Une seule synchronisation par connexion et par tour

## Résumé

Une connexion pouvait être synchronisée **deux fois dans le même tour** de scheduler, doublant sa consommation de requêtes chez le broker pour ré-importer ce qui venait de l'être. La réservation prend désormais l'intervalle en compte, ce qui la rend « une par connexion et par tour » au lieu de « une à la fois ».

## Le constat

Relevé le 2026-08-14 en vérifiant que le correctif de la [93](93-course-refresh-token-partage.md) avait bien pris. Chaque passe laissait **trois** lignes dans `sync_logs` pour **deux** connexions :

```
06:32:02  conn 19  SUCCESS
06:32:02  conn 20  SUCCESS
06:32:02  conn 19  SUCCESS   ← la même, une seconde fois
```

Le compteur quotidien livré la veille ([95](95-budget-quotidien-de-requetes-broker.md)) l'a chiffré sans ambiguïté — sur 4 passes ce jour-là :

| Connexion | `requests_today` | Attendu |
|---|---|---|
| 20 | 36 | 4 × 9 ✅ |
| 19 | **72** | 4 × 18 ❌ |

Exactement le double. La connexion payait deux cycles complets pour le même travail.

## La cause

Chaque worker appelle `findDueForAutoSync()` **au démarrage** et parcourt la liste entière — c'est le principe de la parallélisation ([89](89-broker-sync-parallelisation.md)) : personne ne découpe le travail à l'avance, la réservation le répartit.

Mais `claimForSync()` ne répond qu'à « quelqu'un tient-il cette connexion **en ce moment** ? ». Le worker qui arrive en second, après avoir traité sa propre entrée, trouve la connexion **libre à nouveau** — le premier a terminé et l'a relâchée — et la synchronise par-dessus. La réservation empêchait la simultanéité, pas la répétition.

### Pourquoi le défaut est devenu visible maintenant

Il existait déjà, mais il coûtait presque rien : ce troisième passage **échouait tôt**, sur le refresh token consommé par l'autre worker. C'était le `FAILED` récurrent que la [93](93-course-refresh-token-partage.md) est venue corriger.

En réglant la course, on a transformé un échec gratuit en une **synchronisation complète et réussie**. Les données restent justes — la synchro est idempotente — mais le budget de requêtes, lui, paie plein tarif. Corriger un défaut a rendu l'autre coûteux : ils se masquaient l'un l'autre.

### Ce que ça coûtait

À l'intervalle de 2 h en vigueur, 216 requêtes/jour pour la connexion concernée : sans danger. À 15 minutes, 96 passes × 18 = **1 728 requêtes/jour** — au-dessus du plafond de 1 500 posé la veille, et dans le voisinage des 2 000 de FTMO, dont le support a confirmé le 2026-08-14 qu'ils comptent **toutes** les requêtes, lectures incluses.

C'est le plafond quotidien qui protégeait de ce défaut, ce qui en dit long sur son utilité.

## La correction

`claimForSync()` accepte un intervalle optionnel et refuse alors une connexion déjà synchronisée dans cette fenêtre :

```sql
AND (sync_requested_at IS NOT NULL
     OR last_sync_at IS NULL
     OR last_sync_at < UTC_TIMESTAMP() - INTERVAL {interval} MINUTE)
```

C'est **exactement le prédicat** de `findDueForAutoSync()`. Une connexion ne peut donc jamais être retenue par la liste puis refusée par la réservation pour une autre raison que « quelqu'un est arrivé avant ».

Tout reste dans le même `UPDATE` conditionnel : pas de lecture puis écriture, donc aucune fenêtre entre la décision et l'acte.

### Le clic manuel n'est pas touché

Une demande explicite (`sync_requested_at`) **bat l'intervalle** : quelqu'un regarde un spinner, sa synchro doit partir même quelques secondes après une passe automatique.

Et elle ne vaut qu'une fois : la réservation consomme `sync_requested_at` dans la même instruction, donc le worker suivant du même tour ne trouve ni demande en attente ni `last_sync_at` périmé, et s'arrête. Sans cela, un clic aurait autorisé une synchro supplémentaire **par worker**.

Le contrôleur n'appelle de toute façon que `requestSync()`, jamais `sync()` : le chemin manuel passe donc toujours par le drapeau.

### Omettre l'intervalle conserve l'ancien comportement

`claimForSync($id, $ttl)` sans troisième argument se comporte comme avant. C'est ce que fait `BrokerSyncService` quand aucun intervalle ne lui est configuré — le service ne le reçoit que du scheduler.

## Tests

| Fichier | Portée |
|---|---|
| `tests/Integration/Repositories/BrokerConnectionRepositoryTest.php` | 6 tests : refus d'une connexion synchronisée dans l'intervalle, acceptation au-delà, acceptation si jamais synchronisée, **la demande manuelle bat l'intervalle**, la demande manuelle est consommée donc un second worker ne la réutilise pas, et sans intervalle rien ne change |
| `tests/Unit/Services/Broker/BrokerSyncServiceTest.php` | 2 tests : l'intervalle configuré est bien transmis à la réservation, et son absence laisse l'appel inchangé |

Suites complètes : **1127 unitaires**, **754 intégration**.

## À vérifier après déploiement

`sync_logs` doit montrer **exactement une ligne par connexion et par passe** — deux lignes pour deux connexions, plus trois. Et le compteur doit se remettre d'aplomb :

```sql
SELECT id, requests_today, requests_counted_on FROM broker_connections WHERE status = 'ACTIVE';
```

Les deux connexions doivent afficher **le même ordre de grandeur** (9 requêtes par passe chacune), là où l'une en affichait le double.
