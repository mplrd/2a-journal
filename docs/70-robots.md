# 70 - Robots de trading

> **Statut** : spec en cours de validation. Aucune implémentation tant que ce document n'est pas validé.

## Objectif

Introduire la notion de **robot de trading** comme entité de premier ordre dans l'application. Un robot :

- **expose un webhook** : c'est lui qui reçoit le signal d'un indicateur (TradingView aujourd'hui) ;
- est **lié à un compte** : c'est sur ce compte qu'il pourra passer des trades ;
- peut être **activé ou mis en pause** : un robot en pause reçoit toujours les signaux (tracés) mais ne passe aucun trade ;
- (v2) appliquera des **règles de filtrage internes** pour laisser passer ou rejeter un signal avant de le router vers le compte.

Cette brique remplace l'accès actuel « par compte » (bouton ⚡ sur la grille des comptes) par une **page dédiée « Mes robots »** dans le menu principal.

## Pourquoi

La feature webhooks TradingView (doc 66) a livré toute la plomberie d'ingestion, mais en accrochant le webhook **au compte** (`tradingview_webhooks.account_id`). C'est un raccourci : le webhook n'est pas un attribut du compte, c'est le **canal d'entrée d'un robot** qui, lui, décide quoi faire du signal.

Conséquences du modèle actuel, qui motivent le refactor :

- pas d'endroit pour « gérer ses robots » : il faut cliquer compte par compte ;
- pas de notion de pause réactivable (le statut webhook est `ACTIVE`/`REVOKED`, révoquer est définitif) ;
- aucune place pour héberger la future logique de filtrage des signaux.

La feature webhooks est **livrée mais jamais activée** (flag OFF en test comme en prod). On peut donc **réécrire librement** cette partie : pas de données réelles à préserver, pas de migration de backfill à soigner.

## Périmètre v1 (ce document) vs v2

| | v1 (cette spec) | v2 (plus tard) |
|---|---|---|
| Robot ↔ compte | 1 robot → 1 compte | (éventuel multi-comptes) |
| Canal d'entrée | webhook TradingView | autres sources possibles |
| Activation | ACTIVE / PAUSED | idem |
| **Filtrage des signaux** | **aucun** (le robot route tel quel) | **règles** (horaires, direction, symbole, limites de risque…) |
| Page « Mes robots » | oui (CRUD, historique, pause) | enrichie (config des règles) |

La v1 pose **l'entité robot et la page**, sans intelligence de filtrage. Le filtrage est explicitement repoussé en v2 — mais le modèle de données est conçu pour l'accueillir sans nouvelle migration lourde.

## Modèle de données

### Nouvelle table `robots`

```sql
CREATE TABLE robots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,        -- compte cible (1 robot → 1 compte en v1)
    name VARCHAR(120) NOT NULL,
    status ENUM('ACTIVE','PAUSED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    -- v2 : colonne(s) ou table dédiée pour les règles de filtrage
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
- robot `ACTIVE` → on continue le pipeline existant (broker connection, `OrderService::createFromWebhook`, `placeOrder`).
- (v2) robot `ACTIVE` → application des règles de filtrage avant le routage.

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

> Le renommage gère le fallback : l'`env_var` reste `TRADINGVIEW_WEBHOOKS_ENABLED` (déjà posé sur Railway) pour ne pas casser l'activation existante, seul la clé de réglage BDD et la clé `/features` changent. À confirmer en implémentation.

## Frontend

- **Nouvelle entrée de menu « Mes robots »** (visible si `features.robots === true`).
- **`RobotsView`** : liste de tous les robots de l'utilisateur (nom, compte cible, statut, dernier signal, compteurs), bouton « Créer un robot ».
- **Création** : formulaire (nom + sélection du compte cible) → modale one-shot avec URL webhook + secret + template JSON (inchangé sur le fond, cf. doc 66).
- **Détail robot** : historique des signaux reçus (events), bouton pause/reprise, archivage.
- **Retrait du bouton ⚡** sur `AccountsView` (et du `TradingViewWebhooksPanel` par compte) : tout passe par la page Robots.

## Découpage en commits (branche `feat/robots`)

La branche vit séparément, mergée vers develop seulement à un jalon cohérent. Commits atomiques envisagés :

1. `docs(robots)` : cette spec (ce commit).
2. `feat(robots)` backend : migration (table `robots` + `tradingview_webhooks.robot_id`), enums statut, `RobotRepository`, `RobotService`, réécriture du pipeline d'ingestion, routes `/robots`, retrait `/accounts/{id}/webhooks`. Tests.
3. `feat(settings)` : renommage du flag `tradingview_webhooks_enabled` → `robots_enabled`.
4. `feat(robots)` frontend : page « Mes robots », menu, CRUD, retrait du bouton ⚡ + panel par compte. Tests.
5. `docs(robots)` : mise à jour finale de la doc + doc 66 (renvoi vers 70).

## Limitations / suite (v2)

- **Filtrage des signaux** : le cœur de la valeur ajoutée, repoussé en v2 (règles : horaires, direction, symbole, limites de risque du compte…).
- **Multi-comptes** : 1 robot → 1 compte en v1.
- **Sources non-TradingView** : le robot est conçu pour pouvoir accueillir d'autres canaux d'entrée, mais v1 = webhook TV uniquement.
- Reprise des limitations broker de la doc 66 (sandbox non testés, etc.) : inchangées, le robot ne fait que router vers le même `placeOrder`.
