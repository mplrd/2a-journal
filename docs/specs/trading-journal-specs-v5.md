# Trading Journal - Spécifications Fonctionnelles & Brief Technique

## Table des matières
1. [Introduction](#1-introduction)
2. [Spécifications Fonctionnelles](#2-spécifications-fonctionnelles)
3. [Brief Technique](#3-brief-technique)
4. [Architecture API](#4-architecture-api)
5. [Modèle de Données](#5-modèle-de-données)
6. [Plan de Développement](#6-plan-de-développement)

---

## 1. Introduction

### 1.1 Contexte
Application web de journal de trading permettant aux traders de suivre, analyser et optimiser leurs performances de trading. Le projet repart de zéro avec une architecture professionnelle et maintenable.

### 1.2 Objectifs
- Centraliser le suivi de tous les trades
- Automatiser les calculs (Stop Loss, Break Even, Take Profit)
- Fournir des statistiques et analyses de performance
- Permettre le partage facile des trades
- Permettre l'intégration avec des outils externes (TradingView, brokers, propfirms)

### 1.3 Internationalisation (i18n)
L'application est conçue pour être **multilingue** :
- Tous les textes UI utilisent des clés de traduction
- Les enums/statuts sont des constantes (pas de chaînes en dur)
- Langue par défaut : Français (fr)
- Prévu : Anglais (en)

### 1.4 Convention de nommage
| Élément | Langue | Exemple |
|---------|--------|---------|
| Documentation | Français | Ce document |
| Code source | Anglais | `TradeService.php` |
| Base de données | Anglais | `accounts`, `entry_price` |
| Enums/Constantes | Anglais (UPPER_CASE) | `PENDING`, `EXECUTED` |
| Messages techniques | Anglais | Logs, erreurs internes |
| Messages utilisateur | Via clés i18n | Multilingue |

---

## 2. Spécifications Fonctionnelles

### 2.1 Gestion des Utilisateurs

#### 2.1.1 Authentification
| Fonctionnalité | Description | Priorité |
|----------------|-------------|----------|
| Inscription | Email, mot de passe, confirmation email | P1 |
| Connexion | Email/mot de passe + option "Se souvenir" | P1 |
| Déconnexion | Invalidation du token | P1 |
| Mot de passe oublié | Reset par email | P1 |
| Profil utilisateur | Modification des infos personnelles | P2 |
| Préférences | Timezone, devise par défaut, thème, langue | P2 |

#### 2.1.2 Rôles (évolution future)
- **User** : Accès à ses propres données
- **Admin** : Gestion des utilisateurs, statistiques globales

---

### 2.2 Gestion des Comptes de Trading

#### 2.2.1 Compte de Trading
Un utilisateur peut avoir plusieurs comptes de trading (démo, réel, différents brokers/propfirms).

| Champ | Type | Description |
|-------|------|-------------|
| name | string | Nom du compte |
| account_type | enum | BROKER / PROPFIRM |
| broker | string | Nom du broker ou propfirm |
| mode | enum | DEMO / LIVE / CHALLENGE / FUNDED |
| currency | string | EUR, USD, etc. |
| initial_capital | decimal | Capital de départ |
| current_capital | decimal | Capital actuel (stocké, recalculé à chaque trade) |
| is_active | boolean | Compte actif/archivé |

> **Types spécifiques Propfirm :**
> - CHALLENGE : Phase d'évaluation
> - FUNDED : Compte financé

#### 2.2.2 Fonctionnalités
- CRUD comptes de trading
- Archivage (soft delete)
- Statistiques par compte
- **Transfert de position** : Réassigner une position à un autre compte (correction d'erreur de saisie)

---

### 2.3 Gestion des Positions (Ordres & Trades)

#### 2.3.1 Cycle de vie d'une position

```
[PENDING Order] ──→ [OPEN Trade] ──→ [CLOSED Trade]
       │                  │
       ├─→ [CANCELLED]    └─→ [SECURED Trade] ──→ [CLOSED Trade]
       │
       └─→ [EXPIRED]
```

**Transitions de statut :**
- **PENDING → EXECUTED** : L'ordre est déclenché, crée un Trade OPEN
- **PENDING → CANCELLED** : Annulation manuelle
- **PENDING → EXPIRED** : Date d'expiration atteinte (système)
- **OPEN → SECURED** : Break Even atteint ou sortie partielle effectuée (profit sécurisé)
- **OPEN → CLOSED** : Clôture directe (SL, TP complet, ou manuelle)
- **SECURED → CLOSED** : Clôture finale de la position restante

**Tracking des changements de statut** : Chaque transition est horodatée dans une table d'audit dédiée avec l'utilisateur responsable.

#### 2.3.2 Données communes (Position)
Toutes les positions partagent ces informations de base :

| Champ | Type | Obligatoire | Description |
|-------|------|-------------|-------------|
| account_id | FK | Oui | Compte de trading |
| direction | enum | Oui | BUY / SELL |
| symbol | string | Oui | Instrument (NASDAQ, DAX, EUR/USD...) |
| entry_price | decimal | Oui | Prix d'entrée |
| size | decimal | Oui | Taille de position totale |
| setup | string | Oui | Signal/raison d'entrée |
| sl_points | decimal | Oui | Stop Loss en points |
| sl_price | decimal | Auto | Stop Loss en prix |
| be_points | decimal | Non | Break Even en points |
| be_price | decimal | Auto | Break Even en prix |
| be_size | decimal | Non | Taille à sortir au BE |
| targets | JSON | Non | Liste des Take Profits |
| notes | text | Non | Notes |

#### 2.3.3 Ordre en Attente (extension)
Données spécifiques aux ordres non encore exécutés :

| Champ | Type | Description |
|-------|------|-------------|
| created_at | datetime | Date de création de l'ordre |
| expires_at | datetime | Expiration de l'ordre (optionnel) |
| status | enum | PENDING / EXECUTED / CANCELLED / EXPIRED |

#### 2.3.4 Trade (extension)
Données spécifiques aux trades actifs et clôturés :

| Champ | Type | Description |
|-------|------|-------------|
| source_order_id | FK | Lien vers l'ordre d'origine (si applicable) |
| opened_at | datetime | Date/heure d'ouverture |
| closed_at | datetime | Date/heure de fermeture (si clôturé) |
| remaining_size | decimal | Taille encore en position |
| be_reached | boolean | Break Even touché ? |
| avg_exit_price | decimal | Prix de sortie pondéré |
| pnl | decimal | Profit/Loss réalisé |
| pnl_percent | decimal | % du capital |
| risk_reward | decimal | Risk/Reward ratio |
| status | enum | OPEN / SECURED / CLOSED |
| exit_type | enum | BE / TP / SL / MANUAL (si clôturé) |
| duration_minutes | integer | Durée du trade |

#### 2.3.5 Structure d'un Objectif (Take Profit)

```json
{
  "id": "uuid",
  "label": "TP1",
  "points": 50,
  "price": 18300,
  "size": 0.1,
  "reached": false,
  "reached_at": null
}
```

#### 2.3.6 Historique des Statuts (Audit Trail)
Table d'audit générique pour tracer tous les changements de statut :

| Champ | Type | Description |
|-------|------|-------------|
| entity_type | enum | Type d'objet (ORDER, TRADE, ACCOUNT, POSITION) |
| entity_id | int | ID de l'objet concerné |
| previous_status | string | Ancien statut (null si création) |
| new_status | string | Nouveau statut |
| user_id | FK | Utilisateur ayant effectué le changement |
| changed_at | datetime | Horodatage |
| trigger_type | enum | MANUAL / SYSTEM / WEBHOOK / BROKER_API |
| details | JSON | Contexte (prix, raison, IP...) |

---

### 2.4 Partage de Trade

#### 2.4.1 Bloc de Partage
Génération d'un récapitulatif formaté du trade pour partage :

**Format texte (copier/coller) :**
```
📈 BUY NASDAQ @ 18240

🛑 STOP: 18180 (60 pts)
🔒 BE: 18270 (30 pts) - 33%

🎯 TP1: 18300 (60 pts) - 33%
🎯 TP2: 18360 (120 pts) - 33%
🎯 TP3: 18420 (180 pts) - 33%

💬 Divergence haussière sur RSI
```

#### 2.4.2 Fonctionnalités de partage
| Fonctionnalité | Description | Priorité |
|----------------|-------------|----------|
| Copier texte | Copie le récap dans le presse-papier | P1 |
| Copier sans emojis | Version sobre pour certaines plateformes | P1 |
| Générer image | Card visuelle du trade (PNG) | P2 |
| Lien public | URL de partage temporaire ou permanent | P3 |
| Export PDF | Fiche détaillée du trade | P3 |

#### 2.4.3 Personnalisation du partage
- Choix des infos à inclure (masquer SL, masquer taille...)
- Templates de format (Discord, Telegram, Twitter...)
- Branding personnel (nom/pseudo, logo)

---

### 2.5 Calculs Automatiques

#### 2.5.1 Règles de calcul

**Stop Loss :**
```
Si BUY  → sl_price = entry_price - sl_points
Si SELL → sl_price = entry_price + sl_points
```

**Break Even :**
```
Si BUY  → be_price = entry_price + be_points
Si SELL → be_price = entry_price - be_points
```

**Take Profit :**
```
Si BUY  → tp_price = entry_price + tp_points
Si SELL → tp_price = entry_price - tp_points
```

**Risk/Reward :**
```
risk_reward = pnl / (initial_size × sl_points × point_value)
```

#### 2.5.2 Synchronisation Points ↔ Prix
- Modification des points → Recalcul automatique du prix
- Modification du prix → Recalcul automatique des points
- Changement de direction (BUY↔SELL) → Recalcul de tous les prix

---

### 2.6 Statistiques & Analytics

#### 2.6.1 Statistiques Globales
| Métrique | Calcul |
|----------|--------|
| Nombre total de trades | COUNT(*) |
| Trades gagnants | COUNT(pnl > 0) |
| Trades perdants | COUNT(pnl < 0) |
| Trades BE | COUNT(pnl = 0) |
| Win Rate | Winners / Total × 100 |
| P&L Total | SUM(pnl) |
| P&L Moyen | AVG(pnl) |
| Plus gros gain | MAX(pnl) |
| Plus grosse perte | MIN(pnl) |
| RR Moyen | AVG(risk_reward) |
| Profit Factor | Somme gains / Somme pertes |
| Drawdown Max | Calcul de la série perdante max |

#### 2.6.2 Statistiques par Dimension
- **Par actif** : Performance par instrument
- **Par direction** : BUY vs SELL
- **Par setup** : Efficacité des setups
- **Par période** : Jour, semaine, mois, année
- **Par session** : Asie, Europe, US
- **Par compte** : Comparaison des comptes
- **Par type de compte** : Broker vs Propfirm

#### 2.6.3 Graphiques
- Évolution du P&L cumulé (ligne)
- Répartition win/loss (donut)
- P&L par actif (barres)
- Heatmap des performances par jour/heure
- Courbe d'equity
- Distribution des trades par RR

---

### 2.7 Filtres & Recherche

#### 2.7.1 Critères de filtrage
- Période (date début/fin)
- Compte de trading
- Type de compte (Broker/Propfirm)
- Actif (symbol)
- Direction (BUY/SELL)
- Setup
- Statut (gagné/perdu/BE)
- Plage de P&L
- Plage de RR

#### 2.7.2 Recherche
- Recherche textuelle dans : symbol, setup, notes
- Sauvegarde des filtres favoris

---

### 2.8 Export des Données

| Format | Contenu |
|--------|---------|
| CSV | Trades avec tous les champs |
| JSON | Structure complète |
| PDF | Rapport de performance |
| Excel | Feuille de calcul formatée |

---

### 2.9 Intégrations (Phase 2)

#### 2.9.1 Webhook TradingView
- Réception d'alertes TradingView
- Création automatique d'ordres/trades
- Format du payload configurable
- Mapping des alertes vers les signaux

#### 2.9.2 Connecteurs Brokers
Intégration avec les APIs des brokers pour :
- Import automatique des trades exécutés
- Synchronisation des positions ouvertes
- Récupération des prix en temps réel

**Brokers cibles :**
- Interactive Brokers
- MetaTrader 4/5 (via bridge)
- Trading 212
- IG Markets

#### 2.9.3 Connecteurs Propfirms
Spécificités des propfirms à gérer :
- Règles de drawdown (daily, max, trailing)
- Objectifs de profit par phase
- Limites de lot size
- Jours de trading minimum
- Tracking du profit split

**Propfirms cibles :**
- FTMO
- The Funded Trader
- MyForexFunds
- Topstep

#### 2.9.4 Dashboard Propfirm
Vue dédiée pour les comptes propfirm :
- Progression vers l'objectif
- Drawdown consommé / restant
- Jours restants (challenge)
- Alertes de sécurité (proche limite)

---

### 2.10 Interface Utilisateur

#### 2.10.1 Pages principales
| Page | Description |
|------|-------------|
| Dashboard | Vue d'ensemble, stats clés, trades récents |
| Orders | Liste et gestion des ordres en attente |
| Active Trades | Positions ouvertes avec P&L latent |
| History | Trades clôturés avec filtres |
| Statistics | Analytics et graphiques détaillés |
| Accounts | Gestion des comptes de trading |
| Settings | Préférences utilisateur |

#### 2.10.2 Composants clés
- **PositionForm** : Saisie avec calculs temps réel et prévisualisation
- **ShareBlock** : Récapitulatif avec boutons de copie/export
- **TradeList** : Tableau avec tri, filtre, pagination
- **StatsPanel** : KPIs et graphiques
- **ToastNotification** : Feedback utilisateur

---

## 3. Brief Technique

### 3.1 Stack Technologique

#### Backend
| Composant | Technologie | Justification |
|-----------|-------------|---------------|
| Langage | PHP 8.2+ | Requis, moderne, typé |
| Architecture | MVC custom | Léger, contrôle total |
| Base de données | MySQL 8.0 / MariaDB | Requis, performant |
| ORM | PDO + Repository Pattern | Flexible, sécurisé |
| Auth | JWT (Firebase JWT) | Stateless, scalable |
| Validation | Respect/Validation | Robuste, expressif |

#### Frontend
| Composant | Technologie | Justification |
|-----------|-------------|---------------|
| Framework | Vue.js 3 | Léger, réactif, composition API |
| State Management | Pinia | Moderne, intégré Vue 3 |
| Routing | Vue Router 4 | Standard Vue |
| HTTP Client | Axios | Interceptors, robuste |
| UI Components | PrimeVue ou Headless UI | Complet, accessible |
| Styling | Tailwind CSS | Utilitaire, maintenable |
| Build | Vite | Rapide, moderne |
| Charts | Chart.js ou ApexCharts | Performant, flexible |
| **i18n** | **vue-i18n** | **Internationalisation** |

#### Environnement de développement
| Composant | Technologie |
|-----------|-------------|
| Serveur local | WAMP (Windows) |
| Serveur Web | Apache |
| BDD | MySQL / MariaDB |
| Node.js | Pour le build frontend |

---

### 3.2 Stratégie d'Internationalisation (i18n)

#### 3.2.1 Enums & Constantes
Tous les statuts sont des enums, jamais des chaînes en dur :

```php
<?php
// PHP - Backend Enums (src/Enums/)

namespace App\Enums;

enum OrderStatus: string {
    case PENDING = 'PENDING';
    case EXECUTED = 'EXECUTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
}

enum TradeStatus: string {
    case OPEN = 'OPEN';
    case SECURED = 'SECURED';
    case CLOSED = 'CLOSED';
}

enum ExitType: string {
    case BE = 'BE';
    case TP = 'TP';
    case SL = 'SL';
    case MANUAL = 'MANUAL';
}

enum Direction: string {
    case BUY = 'BUY';
    case SELL = 'SELL';
}

enum AccountType: string {
    case BROKER = 'BROKER';
    case PROPFIRM = 'PROPFIRM';
}

enum AccountMode: string {
    case DEMO = 'DEMO';
    case LIVE = 'LIVE';
    case CHALLENGE = 'CHALLENGE';
    case FUNDED = 'FUNDED';
}

enum TriggerType: string {
    case MANUAL = 'MANUAL';
    case SYSTEM = 'SYSTEM';
    case WEBHOOK = 'WEBHOOK';
    case BROKER_API = 'BROKER_API';
}
```

```javascript
// JavaScript - Frontend Constants (src/constants/)

export const OrderStatus = {
  PENDING: 'PENDING',
  EXECUTED: 'EXECUTED',
  CANCELLED: 'CANCELLED',
  EXPIRED: 'EXPIRED'
}

export const TradeStatus = {
  OPEN: 'OPEN',
  SECURED: 'SECURED',
  CLOSED: 'CLOSED'
}

export const ExitType = {
  BE: 'BE',
  TP: 'TP',
  SL: 'SL',
  MANUAL: 'MANUAL'
}

export const Direction = {
  BUY: 'BUY',
  SELL: 'SELL'
}

export const AccountType = {
  BROKER: 'BROKER',
  PROPFIRM: 'PROPFIRM'
}

export const AccountMode = {
  DEMO: 'DEMO',
  LIVE: 'LIVE',
  CHALLENGE: 'CHALLENGE',
  FUNDED: 'FUNDED'
}

export const TriggerType = {
  MANUAL: 'MANUAL',
  SYSTEM: 'SYSTEM',
  WEBHOOK: 'WEBHOOK',
  BROKER_API: 'BROKER_API'
}
```

#### 3.2.2 Structure des fichiers de traduction

```
frontend/src/locales/
├── fr.json          # Français (par défaut)
├── en.json          # Anglais
└── index.js         # Configuration i18n
```

**fr.json (exemple) :**
```json
{
  "common": {
    "save": "Enregistrer",
    "cancel": "Annuler",
    "delete": "Supprimer",
    "edit": "Modifier",
    "create": "Créer",
    "search": "Rechercher",
    "loading": "Chargement...",
    "noData": "Aucune donnée"
  },
  "auth": {
    "login": "Connexion",
    "logout": "Déconnexion",
    "register": "Inscription",
    "forgotPassword": "Mot de passe oublié"
  },
  "status": {
    "order": {
      "PENDING": "En attente",
      "EXECUTED": "Exécuté",
      "CANCELLED": "Annulé",
      "EXPIRED": "Expiré"
    },
    "trade": {
      "OPEN": "En cours",
      "SECURED": "Sécurisé",
      "CLOSED": "Clôturé"
    },
    "exitType": {
      "BE": "Break Even",
      "TP": "Take Profit",
      "SL": "Stop Loss",
      "MANUAL": "Manuel"
    }
  },
  "direction": {
    "BUY": "Achat",
    "SELL": "Vente"
  },
  "triggerType": {
    "MANUAL": "Manuel",
    "SYSTEM": "Système",
    "WEBHOOK": "Webhook",
    "BROKER_API": "API Broker"
  },
  "account": {
    "type": {
      "BROKER": "Broker",
      "PROPFIRM": "Propfirm"
    },
    "mode": {
      "DEMO": "Démo",
      "LIVE": "Réel",
      "CHALLENGE": "Challenge",
      "FUNDED": "Financé"
    }
  },
  "validation": {
    "required": "Ce champ est requis",
    "invalidEmail": "Email invalide",
    "positive": "Doit être positif"
  },
  "messages": {
    "success": {
      "created": "Créé avec succès",
      "updated": "Mis à jour avec succès",
      "deleted": "Supprimé avec succès"
    },
    "error": {
      "generic": "Une erreur est survenue",
      "notFound": "Ressource non trouvée"
    }
  }
}
```

#### 3.2.3 Utilisation dans les composants

```vue
<template>
  <span>{{ $t('status.trade.' + trade.status) }}</span>
  <button>{{ $t('common.save') }}</button>
</template>
```

#### 3.2.4 Messages d'erreur API
L'API retourne des clés de traduction, pas du texte :

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message_key": "validation.required",
    "field": "entry_price"
  }
}
```

---

### 3.3 Architecture des Dossiers

```
trading-journal/                # Racine du projet
├── api/                        # Backend PHP (API REST)
│   ├── public/
│   │   ├── index.php
│   │   └── .htaccess
│   ├── config/
│   │   ├── app.php
│   │   ├── database.php
│   │   ├── routes.php
│   │   └── cors.php
│   ├── src/
│   │   ├── Core/
│   │   │   ├── Router.php
│   │   │   ├── Request.php
│   │   │   ├── Response.php
│   │   │   ├── Controller.php
│   │   │   ├── Model.php
│   │   │   ├── Database.php
│   │   │   └── Validator.php
│   │   ├── Enums/
│   │   │   ├── OrderStatus.php
│   │   │   ├── TradeStatus.php
│   │   │   ├── ExitType.php
│   │   │   ├── Direction.php
│   │   │   ├── AccountType.php
│   │   │   ├── AccountMode.php
│   │   │   └── TriggerType.php
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Repositories/
│   │   ├── Middlewares/
│   │   ├── Exceptions/
│   │   └── Helpers/
│   ├── database/
│   │   └── schema.sql          # Script de création BDD
│   ├── tests/
│   ├── vendor/
│   ├── composer.json
│   └── .env
│
└── frontend/                   # Frontend Vue.js
    ├── src/
    │   ├── assets/
    │   ├── components/
    │   │   ├── common/
    │   │   ├── position/
    │   │   ├── trade/
    │   │   ├── order/
    │   │   ├── stats/
    │   │   ├── account/
    │   │   └── layout/
    │   ├── composables/
    │   ├── constants/          # Enums JS
    │   ├── locales/            # Traductions i18n
    │   │   ├── fr.json
    │   │   ├── en.json
    │   │   └── index.js
    │   ├── services/
    │   ├── stores/
    │   ├── utils/
    │   ├── views/
    │   ├── router/
    │   ├── App.vue
    │   └── main.js
    ├── package.json
    └── vite.config.js
```

---

### 3.4 Configuration Apache

#### .htaccess (api/public/.htaccess)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]

<IfModule mod_headers.c>
    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
    Header set Access-Control-Allow-Headers "Content-Type, Authorization"
</IfModule>

RewriteCond %{REQUEST_METHOD} OPTIONS
RewriteRule ^(.*)$ $1 [R=200,L]
```

---

## 4. Architecture API

### 4.1 Endpoints REST

#### Authentification
```
POST   /api/auth/register
POST   /api/auth/login
POST   /api/auth/logout
POST   /api/auth/refresh
POST   /api/auth/forgot
POST   /api/auth/reset
GET    /api/auth/me
```

#### Comptes de Trading
```
GET    /api/accounts
POST   /api/accounts
GET    /api/accounts/{id}
PUT    /api/accounts/{id}
DELETE /api/accounts/{id}
GET    /api/accounts/{id}/stats
```

#### Positions
```
GET    /api/positions
GET    /api/positions/{id}
PUT    /api/positions/{id}
DELETE /api/positions/{id}
POST   /api/positions/{id}/transfer
GET    /api/positions/{id}/history
```

#### Ordres
```
GET    /api/orders
POST   /api/orders
POST   /api/orders/{id}/execute
POST   /api/orders/{id}/cancel
```

#### Trades
```
GET    /api/trades
GET    /api/trades/active
GET    /api/trades/closed
POST   /api/trades
POST   /api/trades/{id}/close
PUT    /api/trades/{id}/targets/{targetId}
```

#### Partage
```
GET    /api/positions/{id}/share/text
GET    /api/positions/{id}/share/text-plain
GET    /api/positions/{id}/share/image
POST   /api/positions/{id}/share/link
GET    /api/share/{token}
```

#### Statistiques
```
GET    /api/stats/global
GET    /api/stats/by-symbol
GET    /api/stats/by-direction
GET    /api/stats/by-setup
GET    /api/stats/by-period
GET    /api/stats/by-account
GET    /api/stats/evolution
```

### 4.2 Format de Réponse

**Succès :**
```json
{
  "success": true,
  "data": { ... },
  "meta": { "page": 1, "total": 150 }
}
```

**Erreur :**
```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_ERROR",
    "message_key": "validation.required",
    "field": "entry_price"
  }
}
```

---

## 5. Modèle de Données

### 5.1 Schéma MySQL

```sql
-- USERS
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    timezone VARCHAR(50) DEFAULT 'Europe/Paris',
    default_currency VARCHAR(3) DEFAULT 'EUR',
    locale VARCHAR(5) DEFAULT 'fr',
    theme VARCHAR(20) DEFAULT 'light',
    email_verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ACCOUNTS
CREATE TABLE accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    account_type ENUM('BROKER', 'PROPFIRM') DEFAULT 'BROKER',
    broker VARCHAR(100),
    mode ENUM('DEMO', 'LIVE', 'CHALLENGE', 'FUNDED') DEFAULT 'DEMO',
    currency VARCHAR(3) DEFAULT 'EUR',
    initial_capital DECIMAL(15,2) DEFAULT 0,
    current_capital DECIMAL(15,2) DEFAULT 0,
    max_drawdown DECIMAL(10,2) NULL,
    daily_drawdown DECIMAL(10,2) NULL,
    profit_target DECIMAL(10,2) NULL,
    profit_split DECIMAL(5,2) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- POSITIONS
CREATE TABLE positions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    direction ENUM('BUY', 'SELL') NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    entry_price DECIMAL(15,5) NOT NULL,
    size DECIMAL(10,4) NOT NULL,
    setup VARCHAR(255) NOT NULL,
    sl_points DECIMAL(10,2) NOT NULL,
    sl_price DECIMAL(15,5) NOT NULL,
    be_points DECIMAL(10,2) NULL,
    be_price DECIMAL(15,5) NULL,
    be_size DECIMAL(10,4) NULL,
    targets JSON NULL,
    notes TEXT NULL,
    position_type ENUM('ORDER', 'TRADE') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ORDERS
CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id INT UNSIGNED NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    status ENUM('PENDING', 'EXECUTED', 'CANCELLED', 'EXPIRED') DEFAULT 'PENDING',
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TRADES
CREATE TABLE trades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id INT UNSIGNED NOT NULL UNIQUE,
    source_order_id INT UNSIGNED NULL,
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    remaining_size DECIMAL(10,4) NOT NULL,
    be_reached BOOLEAN DEFAULT FALSE,
    avg_exit_price DECIMAL(15,5) NULL,
    pnl DECIMAL(15,2) NULL,
    pnl_percent DECIMAL(8,4) NULL,
    risk_reward DECIMAL(8,4) NULL,
    duration_minutes INT UNSIGNED NULL,
    status ENUM('OPEN', 'SECURED', 'CLOSED') DEFAULT 'OPEN',
    exit_type ENUM('BE', 'TP', 'SL', 'MANUAL') NULL,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
    FOREIGN KEY (source_order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- STATUS_HISTORY
CREATE TABLE status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('ORDER', 'TRADE', 'ACCOUNT', 'POSITION') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    user_id INT UNSIGNED NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    trigger_type ENUM('MANUAL', 'SYSTEM', 'WEBHOOK', 'BROKER_API') DEFAULT 'MANUAL',
    details JSON NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PARTIAL_EXITS
CREATE TABLE partial_exits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trade_id INT UNSIGNED NOT NULL,
    exited_at DATETIME NOT NULL,
    exit_price DECIMAL(15,5) NOT NULL,
    size DECIMAL(10,4) NOT NULL,
    exit_type ENUM('BE', 'TP', 'SL', 'MANUAL') NOT NULL,
    target_id VARCHAR(36) NULL,
    pnl DECIMAL(15,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trade_id) REFERENCES trades(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SHARE_LINKS
CREATE TABLE share_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    view_count INT UNSIGNED DEFAULT 0,
    hide_sl BOOLEAN DEFAULT FALSE,
    hide_size BOOLEAN DEFAULT FALSE,
    hide_account BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- REFRESH_TOKENS
CREATE TABLE refresh_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SYMBOLS
CREATE TABLE symbols (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    type ENUM('INDEX', 'FOREX', 'CRYPTO', 'STOCK', 'COMMODITY') NOT NULL,
    point_value DECIMAL(10,5) DEFAULT 1,
    currency VARCHAR(3) DEFAULT 'USD',
    is_active BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO symbols (code, name, type, point_value, currency) VALUES
('NASDAQ', 'NASDAQ 100', 'INDEX', 20, 'USD'),
('DAX', 'DAX 40', 'INDEX', 25, 'EUR'),
('SP500', 'S&P 500', 'INDEX', 50, 'USD'),
('CAC40', 'CAC 40', 'INDEX', 10, 'EUR'),
('EURUSD', 'EUR/USD', 'FOREX', 10, 'USD'),
('BTCUSD', 'Bitcoin/USD', 'CRYPTO', 1, 'USD');
```

### 5.2 Diagramme des Relations

```
┌──────────┐       ┌──────────┐
│  users   │───1:N─│ accounts │
└──────────┘       └──────────┘
     │                   │
     └────────┬──────────┘
              │1:N
        ┌─────▼─────┐
        │ positions │
        └─────┬─────┘
       ┌──────┴──────┐
      1:1           1:1
  ┌────▼────┐  ┌────▼────┐
  │ orders  │  │ trades  │
  └─────────┘  └────┬────┘
                   1:N
          ┌────────▼────────┐
          │  partial_exits  │
          └─────────────────┘

┌─────────────────────┐
│   status_history    │ ◄─── Audit trail générique
└─────────────────────┘
```

---

## 6. Plan de Développement

> **Méthodologie TDD** : Chaque feature suit le cycle tests → code → refactor → doc.
> Chaque feature livrée produit une doc dans `docs/` (fonctionnalités, choix d'implémentation, couverture tests).

### Phase 1 - Fondations (2-3 semaines)
- Setup PHP + Vue 3, PHPUnit, Vitest
- Core MVC, Enums, JWT Auth
- Configuration i18n (vue-i18n)
- Composants de base

### Phase 2 - CRUD Core (2-3 semaines)
- CRUD Accounts, Positions, Orders, Trades
- Logging status_history
- Formulaire Position + Share Block

### Phase 3 - Statistiques (1-2 semaines)
- Service stats, Dashboard, Graphiques

### Phase 4 - Polish (1 semaine)
- Export, Responsive, Dark mode
- Documentation API
- Optimisations performances

### Phase 5 - Intégrations (Phase 2)
- Webhooks, Connecteurs brokers/propfirms

---

## Annexes

### Variables d'environnement

**api/.env**
```env
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/trading-journal/api/public

DB_HOST=localhost
DB_DATABASE=trading_journal
DB_USERNAME=root
DB_PASSWORD=

JWT_SECRET=change-me-in-prod
CORS_ALLOWED_ORIGINS=http://localhost:5173
```

**frontend/.env**
```env
VITE_API_URL=http://localhost/trading-journal/api/public
VITE_DEFAULT_LOCALE=fr
```

### Configuration i18n

**frontend/src/locales/index.js**
```javascript
import { createI18n } from 'vue-i18n'
import fr from './fr.json'
import en from './en.json'

export const i18n = createI18n({
  legacy: false,
  locale: import.meta.env.VITE_DEFAULT_LOCALE || 'fr',
  fallbackLocale: 'en',
  messages: { fr, en }
})
```

---

**Document créé le** : 31 janvier 2025  
**Version** : 5.0  
**Changelog** :
- v5.1 : Corrections incohérences (`signal` → `setup`, ajout `current_capital` et `refresh_tokens` en BDD, POSITION dans entity_type, SECURED/EXPIRED clarifiés, JS constants complétés, TDD intégré au plan)
- v5.0 : Code/BDD en anglais, enums i18n, documentation en français
- v4.0 : Retrait Docker, adaptation WAMP
- v3.0 : Table `status_history` générique
- v2.0 : Partage trade, propfirms
- v1.0 : Version initiale
