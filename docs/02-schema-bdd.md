# Etape 2 — Schéma de base de données

## Fonctionnalités

Le schéma définit la structure complète de la base de données du journal de trading.

### Tables (10)

| Table | Rôle |
|-------|------|
| `users` | Utilisateurs avec préférences (timezone, devise, locale, thème) et soft delete |
| `accounts` | Comptes de trading (broker ou propfirm), liés à un utilisateur |
| `symbols` | Données de référence des instruments tradés (indices, forex, crypto, actions, commodités) |
| `positions` | Table de base partagée entre ordres et trades (direction, prix, SL, BE, targets) |
| `orders` | Extension positions : ordres en attente avec statut et expiration |
| `trades` | Extension positions : trades actifs/clôturés avec P&L, RR, durée |
| `partial_exits` | Sorties partielles et hits de Take Profit |
| `status_history` | Audit trail générique de tous les changements de statut |
| `share_links` | Liens de partage publics avec contrôle de confidentialité |
| `refresh_tokens` | Stockage des tokens JWT de rafraîchissement |

### Données de seed

6 symboles pré-insérés : NASDAQ, DAX, SP500, CAC40, EURUSD, BTCUSD.

### Contraintes et intégrité

- **10 clés étrangères** avec actions `ON DELETE CASCADE` ou `SET NULL` selon le cas
- **Contraintes UNIQUE** : `users.email`, `symbols.code`, `orders.position_id`, `trades.position_id`, `share_links.token`, `refresh_tokens.token`
- **Soft delete** (`deleted_at`) sur `users` et `accounts`
- **ENUMs** alignés avec les PHP Enums du backend (7 enums + 2 spécifiques BDD)

## Choix d'implémentation

### Pattern Table-per-Subtype pour positions/orders/trades

La table `positions` contient les champs communs (direction, prix, SL, targets...) avec un discriminateur `position_type` (`ORDER` ou `TRADE`). Les tables `orders` et `trades` étendent `positions` via une FK UNIQUE (`position_id`).

**Avantages** : pas de colonnes NULL inutiles, requêtes spécialisées efficaces, extension facile.

### ENUM natifs MariaDB

Les colonnes à valeurs fixes utilisent le type `ENUM` natif de MariaDB plutôt que des VARCHAR avec contraintes CHECK. Cela garantit l'intégrité au niveau BDD et correspond aux `enum` PHP déclarés dans `api/src/Enums/`.

| Colonne | Valeurs |
|---------|---------|
| `accounts.account_type` | BROKER, PROPFIRM |
| `accounts.mode` | DEMO, LIVE, CHALLENGE, FUNDED |
| `positions.direction` | BUY, SELL |
| `positions.position_type` | ORDER, TRADE |
| `orders.status` | PENDING, EXECUTED, CANCELLED, EXPIRED |
| `trades.status` | OPEN, SECURED, CLOSED |
| `trades.exit_type` | BE, TP, SL, MANUAL |
| `partial_exits.exit_type` | BE, TP, SL, MANUAL |
| `status_history.entity_type` | ORDER, TRADE, ACCOUNT, POSITION |
| `status_history.trigger_type` | MANUAL, SYSTEM, WEBHOOK, BROKER_API |
| `symbols.type` | INDEX, FOREX, CRYPTO, STOCK, COMMODITY |

### Précision des décimaux

Chaque type de donnée numérique a une précision adaptée :

| Usage | Type | Capacité |
|-------|------|----------|
| Prix, capitaux | `DECIMAL(15,5)` | Jusqu'à 9 999 999 999.99999 |
| Tailles/quantités | `DECIMAL(10,4)` | Jusqu'à 999 999.9999 |
| P&L monétaire | `DECIMAL(15,2)` | Jusqu'à 9 999 999 999 999.99 |
| Pourcentages P&L | `DECIMAL(8,4)` | Jusqu'à 9999.9999% |
| Ratios (RR) | `DECIMAL(8,4)` | Valeurs fractionnaires |
| Propfirm splits | `DECIMAL(5,2)` | 0-100% |

### Targets en JSON

Les Take Profits sont stockés en `JSON` dans `positions.targets` plutôt que dans une table séparée. Chaque TP contient : id (UUID), label, points, price, size, reached, reached_at. Ce choix simplifie les opérations CRUD sur les positions tout en gardant la flexibilité.

### Indexation

Index sur toutes les FK, plus des index spécifiques pour les requêtes fréquentes :
- `status` sur `orders` et `trades` (filtrage par statut)
- `opened_at` et `closed_at` sur `trades` (requêtes par plage de dates)
- Index composite `(entity_type, entity_id)` sur `status_history` (audit par entité)
- `expires_at` sur `refresh_tokens` (nettoyage des tokens expirés)

### Charset et collation

Toutes les tables utilisent `utf8mb4` / `utf8mb4_unicode_ci` pour le support Unicode complet (emojis inclus) avec comparaisons insensibles à la casse.

## Couverture des tests

Cette étape ne comporte pas de tests automatisés : le schéma est un script SQL pur exécuté directement sur MariaDB.

### Vérification manuelle effectuée

| Vérification | Résultat |
|--------------|----------|
| `SHOW TABLES` — 10 tables créées | OK |
| `SELECT COUNT(*) FROM symbols` — 6 symboles insérés | OK |
| Requête `information_schema.KEY_COLUMN_USAGE` — 10 FK présentes | OK |

Les tests d'intégration des étapes suivantes (Auth JWT, CRUD Accounts...) valideront implicitement le schéma en effectuant des opérations CRUD réelles sur ces tables.

## Fichiers

| Fichier | Rôle |
|---------|------|
| `api/database/schema.sql` | Script DDL complet + seed data |
| `api/src/Enums/*.php` | 7 enums PHP correspondant aux ENUMs BDD |
