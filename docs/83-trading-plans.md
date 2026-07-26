# 83 - Plans de trading (filtrage des signaux)

> **Statut** : en cours (branche `feat/trading-plans`). Complète la v2 annoncée par la doc [70 - Robots](70-robots.md) : le « plan de trading », couche de décision entre « signal reçu » et « trade passé ».

## Objectif

Un **plan de trading** est un **cadre de décision réutilisable** : il définit *quand* et *où* un robot a le droit de prendre une nouvelle position. Le robot reçoit N signaux TradingView via son webhook ; le plan filtre ceux qui ne rentrent pas dans le cadre.

```
Plan de trading   →  définit le cadre (ce qui est « applicable »)
Robot             →  suit 0..N plans + pointe UN compte
                  →  reçoit N signaux via son webhook
                  →  ne prend que les signaux applicables à AU MOINS UN de ses plans
                  →  exécute le trade sur le compte
```

Le plan est **agnostique au compte** (aucune référence à un compte ni à un broker) : c'est un cadre pur, réutilisable par plusieurs robots sur plusieurs comptes.

## Périmètre : ce que le plan filtre (et ce qu'il ne filtre pas)

Le plan **ne gate que les signaux d'ouverture (`OPEN`)** : il décide de PRENDRE ou non une nouvelle entrée. Les signaux de gestion d'une position déjà ouverte par le robot — `MODIFY` / `CLOSE` / `CANCEL` — **passent toujours**, sans confrontation au plan. On ne veut jamais qu'un plan empêche de déplacer un SL, sécuriser, ou sortir d'une position vivante.

À l'ouverture, l'alerte fournit aussi les niveaux SL/BE/TP : ils sont **posés avec l'ordre** mais **ne sont pas re-validés** par le plan (le plan décide de l'entrée, pas des objectifs). La seule exception est le **risque max par trade** (filtre optionnel ci-dessous).

## Les 4 filtres (tous optionnels, combinés en ET dans un plan)

Chaque filtre est **inactif par défaut** ; un plan applique l'**intersection** des filtres qu'il définit. Un plan sans aucun filtre accepte tout.

| Filtre | Champ | Règle | Inactif si |
|---|---|---|---|
| **Sens** | `allowed_direction` | Le sens du signal doit être celui autorisé | `NULL` (les deux sens) |
| **Zones de prix** | `trading_plan_zones[]` | Pour le sens du signal : s'il existe ≥1 zone de ce sens, l'`entry_price` doit tomber dans au moins une | aucune zone pour ce sens |
| **Fenêtres horaires** | `trading_plan_windows[]` | L'heure du signal (en TZ du plan) doit tomber dans ≥1 fenêtre | aucune fenêtre |
| **Risque max/trade** | `max_risk_percent` | Le risque du signal (% du capital) ≤ plafond | `NULL`, ou risque non calculable |

### Détails

- **Zones multiples par sens.** Un plan peut lister N zones d'achat et M zones de vente (ex. DAX : achat `24500–24550` **et** `24000–24400`). Un signal `BUY` passe s'il tombe dans **au moins une** zone `BUY`. Bornes **inclusives**, ordre indifférent (`low`/`high` normalisés min/max). S'il n'y a **aucune** zone pour le sens du signal, il n'y a **pas** de contrainte de prix pour ce sens (le filtre sens, lui, reste indépendant).
- **Fenêtres.** Une fenêtre = un masque de jours (`days_mask`, bit 0 = lundi … bit 6 = dimanche) + `start_time` / `end_time` (même jour, `start < end`). L'heure du signal est convertie dans la **`timezone` du plan** (IANA, ex. `Europe/Paris`) avant comparaison. Le chevauchement de minuit n'est pas géré en v1 (créer deux fenêtres).
- **Risque max.** Risque monétaire du signal = `size × sl_points × valeur_du_point(symbole, compte)` ; pourcentage = `risque ÷ capital_courant_du_compte`. Comparé à `max_risk_percent`. **Si la valeur du point n'est pas configurée** pour le symbole (ou capital indisponible), le risque n'est pas calculable → le filtre est **ignoré** (pas de rejet), pour ne jamais bloquer un signal sur une incapacité technique. Le calcul vit dans le service webhook (qui a le compte + les réglages symbole) ; l'évaluateur reçoit un `riskPercent` déjà calculé.

## Robot ↔ plans : many-to-many + OR

- Un robot suit **0..N plans** via la table de liaison `robot_plans`.
- **0 plan** ⇒ aucun filtre, le robot exécute tout signal reçu (comportement v1 des robots).
- **≥1 plan** ⇒ un signal est **applicable s'il l'est pour au moins un** des plans (**OR**). Cas d'usage : un robot avec un plan « DAX » + un plan « Nasdaq », chaque signal matche son marché. Le rejet `OUT_OF_PLAN` n'intervient que si le signal échoue à **tous** les plans attachés.

## Adhérence sur ordre & trade manuels (le plan sans robot)

Le plan sert aussi **hors robot** : un **ordre** planifié ou un **trade** saisi à la main (ou synchronisé) peut être **rattaché à un plan** pour repérer, en stats, ceux pris hors du cadre. Différence fondamentale avec le robot : **aucun blocage**. Le robot rejette un signal hors plan (`OUT_OF_PLAN`) ; l'ordre/trade manuel est **toujours enregistré** — on arrive le plus souvent après coup, le garde-fou temps réel n'aurait pas de sens.

**L'info vit sur l'ordre en premier.** Un ordre est une ligne `positions` (type `ORDER`) ; à l'exécution la position bascule `ORDER → TRADE` (même ligne). Comme `plan_id` + le verdict sont **sur `positions`**, un ordre qui porte le plan **transmet automatiquement** l'adhérence au trade à l'exécution — aucune propagation à coder. Le champ sur le trade reste utile pour une saisie directe (sans ordre préalable) ou une synchro broker.

- **Champ** : `positions.plan_id` **optionnel** (FK `ON DELETE SET NULL` — on ne perd jamais l'historique si le plan disparaît). Sans plan rattaché, les trois colonnes restent `NULL`.
- **Verdict figé à l'écriture** : à la création de l'ordre (ou du trade), on évalue via le **même `PlanEvaluator`** que les robots et on stocke `positions.plan_adherence` (`IN_PLAN` / `OUT_OF_PLAN`) + `plan_adherence_reason` (raison courte, ex. « entry 24610 outside BUY zones », pour le tooltip). Modifier le **plan** ensuite **ne re-qualifie pas** l'existant (snapshot). Éditer un **trade** le re-évalue ; l'**exécution** d'un ordre ne re-évalue pas — le trade hérite du verdict de l'ordre tel quel (la décision a été prise à la pose de l'ordre).
- **Temps d'évaluation des fenêtres** : pour un **ordre**, l'instant de pose (`now`) converti dans la TZ du plan ; pour un **trade** manuel, son `opened_at` interprété comme heure locale du plan.
- **Sécurité** : `plan_id` doit être un plan **actif appartenant à l'utilisateur** (sinon `orders.error.invalid_plan` / `trades.error.invalid_plan`). Le verdict n'est jamais posé depuis le corps de la requête (pas de mass-assignment) — seul le service le calcule.
- **UI** : sélecteur de plan optionnel dans les formulaires **ordre et trade** (masqué si aucun plan / feature off), badge d'adhérence dans les listes ordres et trades (vert « Dans le plan » / ambre « Hors plan » + raison en tooltip), et filtre `Dans le plan / Hors plan / Sans plan`. Dans la liste des plans, les zones sont colorées par sens avec une flèche (↑ achat vert, ↓ vente rouge).
- **Migration** : `034_trade_plan_adherence.sql` (additive, idempotente via `INFORMATION_SCHEMA`, compatible MariaDB local + MySQL prod). Enum `PlanAdherence`.

## Pipeline d'ingestion (où le plan s'insère)

Dans `TradingViewWebhookService::process()`, après le gate `ROBOT_PAUSED` et la validation du payload, **uniquement pour l'action `OPEN`** :

```
signal OPEN validé
  └─ le robot a-t-il des plans ?
       ├─ non  → routage normal (broker → placeOrder)
       └─ oui  → applicable à ≥1 plan (OR) ?
                  ├─ oui → routage normal
                  └─ non → event REJECTED / reject_reason = OUT_OF_PLAN
                           (error_message = raison du 1er plan, ex.
                           « entry 24610 outside BUY zones »), aucun trade
```

Le rejet est tracé dans `tradingview_alert_events` (audit visible dans l'historique du robot) et incrémente `robots.total_errors` comme les autres rejets.

## Modèle de données (migration 033)

- **`trading_plans`** : `id, user_id, name, allowed_direction ENUM('BUY','SELL') NULL, timezone VARCHAR(64) NULL, max_risk_percent DECIMAL(6,3) NULL, status ENUM('ACTIVE','ARCHIVED'), timestamps`.
- **`trading_plan_zones`** : `id, plan_id FK, direction ENUM('BUY','SELL'), low_price DECIMAL(15,5), high_price DECIMAL(15,5)`.
- **`trading_plan_windows`** : `id, plan_id FK, days_mask SMALLINT UNSIGNED, start_time TIME, end_time TIME`.
- **`robot_plans`** : `robot_id, plan_id, PK(robot_id, plan_id)`, FK cascade des deux côtés.

Zones et fenêtres sont **remplacées en bloc** à chaque update du plan (pas de diff fin). Un plan **`ARCHIVED`** est un soft-delete ; **archiver est interdit** tant qu'un robot actif référence le plan (`plan.error.in_use`) — il faut d'abord le détacher, pour éviter qu'un robot repasse silencieusement « sans filtre ».

## Choix d'implémentation

- **`PlanEvaluator` pur** (aucune I/O) : `evaluate(plan, direction, entryPrice, riskPercent, now) → null | raison`. Testable exhaustivement en unitaire. Le calcul de risque et le chargement du plan restent hors de l'évaluateur.
- **Enums** : réutilisation de `Direction` (BUY/SELL) ; nouveau `PlanStatus` (ACTIVE/ARCHIVED) ; nouveau `WebhookRejectReason::OUT_OF_PLAN`.
- **Flag** : le plan est un **cadre autonome**, pas un sous-module des robots — le robot en est *un* consommateur, le trade manuel en est un autre (voir « Adhérence sur trade manuel » plus bas). Il a donc son **propre flag `plans_enabled`** (indépendant de `robots_enabled`), OFF par défaut comme les robots pour un déploiement prod contrôlé. Routes `/plans` derrière `auth + subscription + plans_enabled` ; `/features` expose `plans` ; la nav affiche l'entrée Plans dès que `plans_enabled` est actif, même robots désactivés.
  > ⚠️ **Activation (ops)** — comme `robots_enabled` (cf. doc 70) : le flag se résout `BDD > env PLANS_ENABLED > false`. Pour activer, soit toggle en base (`platform_settings.plans_enabled`) via le BO admin, soit variable d'env `PLANS_ENABLED=true` sur Railway (test/prod). **En local** : le row `platform_settings` est **wipé par tout run complet de la suite backend** (comme la démo) → le re-poser après les tests (`INSERT … ON DUPLICATE KEY UPDATE setting_value='true'`), sinon le menu Plans disparaît. Vérifier via `curl http://2a.journal.local/api/features`.
- **Sécurité** : toutes les requêtes en prepared statements ; ownership vérifié (`user_id`) sur chaque accès plan ; validation serveur systématique (sens, zones `low<high` & `>0`, fenêtres `start<end` & `days_mask≠0`, timezone IANA valide si fenêtres, `max_risk_percent>0`).

## Couverture des tests

_(complétée au fil de l'implémentation)_

| Test | Portée | Scénario |
|---|---|---|
| `PlanEvaluatorTest` | unit | sens, zones (multi/normalisation/sens opposé), fenêtres (jour/heure/TZ), risque, combinaison ET, plan vide |
| `TradingPlanServiceTest` | integ | CRUD, validation, remplacement des enfants, archive interdite si utilisé |
| `TradingViewWebhookFlowTest` (étendu) | integ | rejet sens, rejet zone, applicable OK, multi-plans OR, bypass CLOSE/MODIFY |
| `plans-service.spec` / `planForm.spec` | front | service CRUD ; conversions formulaire ↔ API (days_mask, zones, fenêtres) |
