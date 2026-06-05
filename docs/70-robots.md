# 70 - Robots de trading

> **Statut** : v1 livrée (entité robot + page « Mes robots » + flag `robots_enabled`). Le « plan de trading » (filtrage des signaux) reste en v2.

## Objectif

Introduire la notion de **robot de trading** comme entité de premier ordre dans l'application. Un robot :

- **expose un webhook** : c'est lui qui reçoit le signal d'un indicateur (TradingView aujourd'hui) ;
- est **lié à un compte** : c'est sur ce compte qu'il pourra passer des trades ;
- peut être **activé ou mis en pause** : un robot en pause reçoit toujours les signaux (tracés) mais ne passe aucun trade ;
- (v2) **suivra un « plan de trading »** : le robot reçoit N signaux via le webhook et ne prend que ceux **applicables au cadre du plan**. Le plan est le cadre de décision, le robot est l'exécutant.

### Vision cible (le robot, le plan, le compte)

```
Plan de trading   →  définit le cadre (ce qui est « applicable »)
Robot             →  suit UN plan + pointe UN compte cible
                  →  reçoit N signaux via son webhook
                  →  ne prend que ceux applicables au plan
                  →  exécute le trade sur le compte
```

**En v1 (cette spec), le plan n'existe pas encore** : le robot exécute **tout** signal reçu (modulo son statut actif/pause). Le « plan de trading » sera une entité ajoutée plus tard, qui viendra se brancher comme couche de décision entre « signal reçu » et « trade passé » — sans remettre en cause l'entité robot posée ici.

Cette brique remplace l'accès actuel « par compte » (bouton ⚡ sur la grille des comptes) par une **page dédiée « Mes robots »** dans le menu principal.

## Pourquoi

La feature webhooks TradingView (doc 66) a livré toute la plomberie d'ingestion, mais en accrochant le webhook **au compte** (`tradingview_webhooks.account_id`). C'est un raccourci : le webhook n'est pas un attribut du compte, c'est le **canal d'entrée d'un robot** qui, lui, décide quoi faire du signal.

Conséquences du modèle actuel, qui motivent le refactor :

- pas d'endroit pour « gérer ses robots » : il faut cliquer compte par compte ;
- pas de notion de pause réactivable (le statut webhook est `ACTIVE`/`REVOKED`, révoquer est définitif) ;
- aucune place pour héberger la future logique de filtrage des signaux.

La feature webhooks est **livrée mais jamais activée** (flag OFF en test comme en prod). On peut donc **réécrire librement** cette partie : pas de données réelles à préserver, pas de migration de backfill à soigner.

## Périmètre v1 (ce document) vs v2

| | v1 (livrée) | À compléter |
|---|---|---|
| Robot ↔ compte | 1 robot → 1 compte | (éventuel multi-comptes) |
| Canal d'entrée | webhook TradingView | autres sources possibles |
| Activation | ACTIVE / PAUSED | idem |
| **Gestion d'ordre** | **OPEN seul** (`placeOrder`) | **MODIFY / CLOSE / CANCEL** (cf. § Gestion d'ordre) |
| **Plan de trading** | **aucun** (le robot exécute tout signal reçu) | **entité `trading_plans`** : le robot suit un plan, ne prend que les signaux applicables |
| Page « Mes robots » | oui (CRUD, historique, pause) | enrichie (gestion d'ordre + association robot ↔ plan) |

La v1 pose **l'entité robot et la page**, sans plan. Le « plan de trading » est explicitement repoussé en v2 — mais le modèle est conçu pour l'accueillir (le robot référencera un `plan_id` nullable) sans refonte.

## Modèle de données

### Nouvelle table `robots`

```sql
CREATE TABLE robots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,        -- compte cible (1 robot → 1 compte en v1)
    name VARCHAR(120) NOT NULL,
    status ENUM('ACTIVE','PAUSED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    -- v2 : plan_id BIGINT UNSIGNED NULL → FK vers trading_plans (le robot suit un plan)
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_robot_user (user_id),
    KEY idx_robot_account (account_id),
    CONSTRAINT fk_robot_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_robot_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);
```

- **`status`** : `ACTIVE` (passe les trades), `PAUSED` (reçoit + trace les signaux mais ne passe rien — réactivable), `ARCHIVED` (équivalent de l'ancien `REVOKED`, définitif, garde l'historique).

### `tradingview_webhooks` : le webhook appartient au robot

`account_id` (NOT NULL + FK) est **remplacé** par `robot_id`. Le webhook n'est plus qu'un détail technique (canal d'entrée) appartenant au robot. Le compte cible se lit via `robot.account_id`.

```sql
-- migration : on réécrit le rattachement
--   tradingview_webhooks.account_id  →  tradingview_webhooks.robot_id
-- Comme la feature n'est jamais entrée en service, pas de backfill data :
-- on peut DROP/recréer la colonne (les tables sont vides en pratique).
```

> Décision (validée) : feature jamais active → **pas de migration data-safe**. La migration peut recréer la structure proprement plutôt que de préserver d'éventuelles lignes.

### `tradingview_alert_events` : inchangé sur le fond

Reste la table d'audit/dédup des signaux entrants. `webhook_id` continue de pointer le webhook ; `account_id` peut être dérivé/conservé pour l'affichage cross-comptes de la page Robots.

## Backend

### `RobotService` — le chef d'orchestre

Le pipeline d'ingestion (aujourd'hui dans `TradingViewWebhookService::process()`) gagne une étape : après résolution du webhook → on remonte au **robot** → on vérifie son `status`.

- robot `PAUSED` → event tracé `REJECTED/ROBOT_PAUSED`, aucun trade.
- robot `ACTIVE` → on continue le pipeline (broker connection puis l'action demandée, cf. ci-dessous).
- (v2) robot `ACTIVE` **avec un plan** → le signal est confronté au plan ; non applicable → event `REJECTED/OUT_OF_PLAN`, aucun trade ; applicable → routage normal.

### Gestion d'ordre : ouvrir / modifier / fermer / annuler (livré)

Le robot est un **relais de gestion d'ordre**, pas seulement d'ouverture. Principe directeur : **on impose NOTRE structure de payload, mais chaque champ doit rester remplissable par un indicateur TradingView** (message JSON statique + placeholders `{{...}}`, une action par message).

Le payload porte un champ **`action`** (défaut `OPEN` → rétrocompat) :

| `action` | Sens | Champs requis (hors secret) | Connecteur |
|---|---|---|---|
| `OPEN` (défaut) | Ouvrir un ordre | symbol, direction, entry_price, size, sl_points (+ targets…) | `placeOrder` ✅ |
| `MODIFY` | Déplacer SL/TP d'un ordre/position vivant | `client_order_id` + au moins un de : `sl_price`, `tp_price` | `modifyOrder` (BingX ✅, 3 autres `NOT_IMPLEMENTED`) |
| `CLOSE` | Fermer une position (size optionnel = partiel) | `client_order_id` | `closePosition` ✅ |
| `CANCEL` | Annuler un ordre pending | `client_order_id` | `cancelOrder` ✅ |

> **MODIFY utilise des prix absolus** (`sl_price`/`tp_price`), pas des points : au moment d'amender on n'a pas de référence d'entrée pour convertir des points, et une modif consiste à poser des niveaux concrets. L'indicateur envoie les prix.

**Corrélation signal → ordre (`client_order_id` partagé).** À l'`OPEN`, l'indicateur fournit un identifiant stable (ex. `{{ticker}}-{{strategy.order.id}}`) ; on le persiste (`orders.client_order_id`, migration 029) avec l'id broker (`orders.broker_order_id`). Sur `MODIFY`/`CLOSE`/`CANCEL`, l'indicateur réémet le **même** `client_order_id` → on retrouve l'ordre vivant (PENDING/EXECUTED) scopé au compte. Non trouvé → event `REJECTED/ORDER_NOT_FOUND`.

**Implémenté :**
- Enum `WebhookAction` + validation par action ; `extractClientOrderId` distinct d'`alert_id` (dédup).
- Pipeline `process()` : `match(action)` → `handleOpen/handleModify/handleClose/handleCancel` ; nouveaux `reject_reason` `ORDER_NOT_FOUND` / `UNSUPPORTED_ACTION`.
- `OrderRepository::setBrokerCorrelation` (persist à l'OPEN) + `findLiveByClientOrderId` (résolution scopée compte, statut non terminal).
- CLOSE/CANCEL reflètent l'état local (`orders` → CANCELLED + `status_history` trigger WEBHOOK).
- `modifyOrder` réel **BingX** (PUT amend SL/TP) ; cTrader/MetaApi/Ouinex throw `NOT_IMPLEMENTED` (à implémenter quand le broker sera ciblé).
- Tests : `TradingViewWebhookFlowTest` (OPEN corrélation, MODIFY, ORDER_NOT_FOUND, CLOSE, CANCEL, action inconnue), `BingxConnectorTest` (modify PUT + gardes), `ConnectorOrderMethodsTest` (NOT_IMPLEMENTED sur les 3 autres).

> ⚠️ Réserve héritée doc 66 : **aucun connecteur validé en sandbox réelle** — `placeOrder` et désormais `modifyOrder`/`close`/`cancel` suivent les specs publiques, jamais exercés contre un vrai broker. Validation sandbox broker par broker = prérequis avant activation prod. Partial close BingX limité (exige un ordre opposé reduceOnly).

CRUD robot (`RobotService` + `RobotController`), routes sous `/robots` (et plus `/accounts/{id}/webhooks`) :

```
GET    /api/robots                      liste des robots de l'utilisateur (tous comptes)
POST   /api/robots                      crée un robot { name, account_id } → renvoie webhook url + secret (one-shot)
GET    /api/robots/{id}                 détail d'un robot
PATCH  /api/robots/{id}/status          ACTIVE ↔ PAUSED (et ARCHIVED)
GET    /api/robots/{id}/events          historique des signaux reçus (paginé)
DELETE /api/robots/{id}                 archive le robot (status ARCHIVED)
```

Toutes derrière `AuthMiddleware + requireSubscription + robotsFeatureFlag`.

### Flag d'activation : `robots_enabled`

Le flag `tradingview_webhooks_enabled` (doc 66, géré via `PlatformSettingsService`) est **renommé `robots_enabled`**. Même mécanique (BDD admin > env > false), nouveau nom aligné sur l'UI. Il pilote :

- l'endpoint `/features` (clé `robots` côté SPA) ;
- le `FeatureFlagMiddleware` de l'ingestion + du CRUD robots ;
- l'affichage de l'entrée de menu « Mes robots ».

Renommage **complet**, env_var comprise : la clé de réglage BDD, la clé `/features`, **et** l'`env_var` passent de `TRADINGVIEW_WEBHOOKS_ENABLED` → `ROBOTS_ENABLED`.

> ⚠️ **Action ops requise** : après déploiement, re-poser la variable `ROBOTS_ENABLED` sur les environnements Railway (test + prod). L'ancienne `TRADINGVIEW_WEBHOOKS_ENABLED` n'est plus lue → sans la nouvelle variable (et sans toggle BDD), le flag retombe à `false` (feature OFF). C'est sans danger (OFF = caché), mais à ne pas oublier pour réactiver en test.

## Frontend

- **Nouvelle entrée de menu « Mes robots »** (visible si `features.robots === true`).
- **`RobotsView`** : liste de tous les robots de l'utilisateur (nom, compte cible, statut, dernier signal, compteurs), bouton « Créer un robot ».
- **Création** : formulaire (nom + sélection du compte cible) → modale one-shot avec URL webhook + secret + template JSON (inchangé sur le fond, cf. doc 66).
- **Détail robot** : historique des signaux reçus (events), bouton pause/reprise, archivage.
- **Retrait du bouton ⚡** sur `AccountsView` (et du `TradingViewWebhooksPanel` par compte) : tout passe par la page Robots.

## Découpage en commits (branche `feat/robots`)

La branche vit séparément, mergée vers develop seulement à un jalon cohérent. Commits atomiques :

1. ✅ `docs(robots)` : cette spec.
2. ✅ `feat(robots)` backend : migration 028 (table `robots` + `tradingview_webhooks.robot_id`, drop des colonnes account/status/compteurs côté webhook), enum `RobotStatus`, reject reason `ROBOT_PAUSED`, `RobotRepository`, `RobotService` (+ controller), réécriture du pipeline `TradingViewWebhookService` (résolution robot + gate statut + compteurs sur le robot), routes `/robots`, suppression de `AccountWebhookService`/`Controller` et de l'enum orphelin `WebhookStatus`. Tests : `RobotServiceTest` (9), `TradingViewWebhookFlowTest` mis à jour (10).
3. ⬜ `feat(settings)` : renommage du flag `tradingview_webhooks_enabled` → `robots_enabled` (env_var comprise).
4. ⬜ `feat(robots)` frontend : page « Mes robots », menu, CRUD, retrait du bouton ⚡ + panel par compte. Tests.
5. ⬜ `docs(robots)` : mise à jour finale de la doc + doc 66 (renvoi vers 70).

## À compléter / suite

- **Gestion d'ordre complète** : MODIFY / CLOSE / CANCEL en plus d'OPEN (cf. § Gestion d'ordre). C'est la prochaine complétion du robot — décision retenue : champ `action` + corrélation par `client_order_id` partagé, livré d'un bloc.
- **Plan de trading** : le cœur de la valeur ajoutée, repoussé plus tard. Entité `trading_plans` qui définit le cadre ; le robot référence un `plan_id` et ne prend que les signaux applicables. La v1 exécute tout signal reçu.
- **Multi-comptes** : 1 robot → 1 compte en v1.
- **Sources non-TradingView** : le robot est conçu pour pouvoir accueillir d'autres canaux d'entrée, mais v1 = webhook TV uniquement.
- **Validation sandbox broker** (héritée doc 66) : aucun connecteur exercé contre un broker réel. Bloque l'activation prod, et la gestion d'ordre (modify/close/cancel) élargit la surface à valider.
