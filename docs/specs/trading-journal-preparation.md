# Trading Journal - Préparation pour Claude Code

## Checklist avant de commencer

Ce document liste tout ce qu'il faut préparer/vérifier avant de lancer Claude Code sur le projet.

---

## 1. Environnement Local

### 1.1 WAMP
- [ ] WAMP installé et fonctionnel
- [ ] Apache démarré (icône verte)
- [ ] MySQL/MariaDB démarré
- [ ] PHP 8.2+ disponible (`php -v` dans terminal)

### 1.2 Vérifier les extensions PHP
Extensions requises (normalement incluses dans WAMP) :
- [ ] pdo_mysql
- [ ] json
- [ ] mbstring
- [ ] openssl

Vérifier avec : `php -m` ou dans phpMyAdmin > Variables serveur

### 1.3 Node.js
- [ ] Node.js installé (v18+ recommandé)
- [ ] npm disponible (`npm -v`)

### 1.4 Composer
- [ ] Composer installé globalement
- [ ] Accessible en ligne de commande (`composer -V`)

> **Si pas installé** : https://getcomposer.org/download/

---

## 2. Dossier Projet

### 2.1 Créer la structure de base

```bash
# Crée le dossier où tu veux
mkdir D:\Dev\trading-journal
cd D:\Dev\trading-journal

# Initialise git
git init

# Crée les sous-dossiers
mkdir api
mkdir frontend
mkdir .claude
```

### 2.2 Vérifier l'accès Apache
Si tu mets le projet dans `www` de WAMP :
- URL : `http://localhost/trading-journal/api/public/`

Si tu mets ailleurs (ex: `D:\Dev\`) :
- Il faudra configurer un VirtualHost (Claude Code peut t'aider)

---

## 3. Base de Données

### 3.1 Créer la BDD
Via phpMyAdmin (`http://localhost/phpmyadmin`) :

```sql
CREATE DATABASE trading_journal 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;
```

### 3.2 Utilisateur (optionnel mais recommandé)
Créer un user dédié plutôt que `root` :

```sql
CREATE USER 'trading_user'@'localhost' IDENTIFIED BY 'ton_mot_de_passe';
GRANT ALL PRIVILEGES ON trading_journal.* TO 'trading_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3.3 Noter les credentials
Tu en auras besoin pour le `.env` :
- Host : `localhost`
- Port : `3306`
- Database : `trading_journal`
- User : `root` ou `trading_user`
- Password : (ton mdp ou vide si root sans mdp)

---

## 4. Fichiers à Préparer

### 4.1 Le document de specs
Copie `trading-journal-specs-v5.md` à la racine du projet.

### 4.2 Skill Claude Code
Crée le fichier `.claude/skills/project.md` :

```markdown
# Trading Journal - Project Skill

## Stack
- Backend: PHP 8.2+ (MVC custom, NO framework)
- Frontend: Vue.js 3 + Vite + Tailwind CSS + PrimeVue
- Database: MySQL/MariaDB
- Auth: JWT (firebase/php-jwt)
- i18n: vue-i18n

## Conventions
- Source code: English
- Documentation: French
- Commits: English, conventional format (feat:, fix:, refactor:, etc.)
- Variables/functions: camelCase
- Classes: PascalCase
- Database tables/columns: snake_case
- Enums: UPPER_SNAKE_CASE

## Project Structure
```
trading-journal/
├── api/                    # PHP Backend
│   ├── public/index.php    # Single entry point
│   ├── config/             # Configuration files
│   ├── src/
│   │   ├── Core/           # MVC framework
│   │   ├── Enums/          # Status enums
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Repositories/
│   │   └── Middlewares/
│   ├── tests/
│   │   ├── Unit/
│   │   └── Integration/
│   └── .env
├── frontend/               # Vue.js Frontend
│   ├── src/
│   │   ├── components/
│   │   ├── composables/
│   │   ├── constants/      # JS enums (mirror backend)
│   │   ├── locales/        # i18n (fr.json, en.json)
│   │   ├── services/
│   │   ├── stores/
│   │   ├── views/
│   │   └── router/
│   └── .env
└── docs/                   # One doc per feature (French)
```

## Environment
- Local server: WAMP (Apache + MySQL)
- API URL: http://localhost/trading-journal/api/public
- Frontend dev URL: http://localhost:5173

## Database
- Name: trading_journal
- Charset: utf8mb4_unicode_ci
- Schema: see trading-journal-specs-v5.md section 5

## API Response Format
```json
// Success
{ "success": true, "data": {...}, "meta": {...} }

// Error (message_key for i18n)
{ "success": false, "error": { "code": "...", "message_key": "...", "field": "..." } }
```

## Methodology: TDD (Test-Driven Development)
Every feature MUST follow this cycle:
1. **Write tests first** (unit + integration)
2. **Write code** to make tests pass
3. **Refactor** while keeping tests green

### Backend Tests
- Framework: PHPUnit
- Location: api/tests/Unit/ and api/tests/Integration/
- Every Service and Repository must have test coverage
- Test naming: test{MethodName}_{scenario}_{expectedResult}

### Frontend Tests
- Framework: Vitest + Vue Test Utils
- Location: frontend/src/**/__tests__/
- Test composables, services, and critical components

## Documentation per Feature
After each feature is complete, deliver a doc in `docs/` in French:

### File format: `docs/{feature-name}.md`
### Required sections:
1. **Fonctionnalités** - What the feature does (user perspective)
2. **Choix d'implémentation** - Technical decisions and why
3. **Couverture des tests** - List of tests, what they cover, edge cases tested

### Example: `docs/authentication.md`
```markdown
# Authentification

## Fonctionnalités
- Inscription par email/mot de passe
- Connexion avec génération JWT
- ...

## Choix d'implémentation
- JWT stocké en httpOnly cookie plutôt que localStorage pour la sécurité
- Refresh token en BDD avec expiration 7 jours
- ...

## Couverture des tests
| Test | Scénario | Statut |
|------|----------|--------|
| testRegister_validData_returnsToken | Inscription OK | ✅ |
| testRegister_duplicateEmail_returns422 | Email déjà pris | ✅ |
| testLogin_wrongPassword_returns401 | Mauvais mdp | ✅ |
| ...
```

## Key Rules
1. All status values are enums (PENDING, OPEN, CLOSED, etc.) - never hardcoded strings
2. API returns translation keys, not text
3. Frontend translates via $t('key')
4. No framework backend - custom MVC only
5. Always validate input server-side
6. Use prepared statements (PDO) for all queries
7. **Tests first** - no feature is complete without tests
8. **No feature is done without its doc** in docs/
```

### 4.3 Structure finale avant lancement

```
trading-journal/
├── .git/
├── .claude/
│   └── skills/
│       └── project.md
├── api/                    # (vide)
├── frontend/               # (vide)
├── docs/                   # Doc fonctionnelle par feature
├── trading-journal-specs-v5.md
└── .gitignore
```

### 4.4 .gitignore de base

```gitignore
# Dependencies
/api/vendor/
/frontend/node_modules/

# Environment
.env
.env.local

# IDE
.idea/
.vscode/

# Build
/frontend/dist/

# OS
.DS_Store
Thumbs.db

# Logs
*.log
```

---

## 5. Git - Tu gardes la main

**Claude Code ne touche jamais à git sans ta demande explicite.**

- Il modifie les fichiers locaux
- Toi tu décides quand `git add`, `commit`, `push`
- Tu peux lui demander de faire des commits, mais il attendra ta validation

Workflow recommandé :
1. Claude Code génère/modifie du code
2. Tu testes
3. Tu commit toi-même (ou tu lui demandes de le faire)

---

## 6. Ordre de Développement Suggéré

> **Rappel TDD** : Chaque étape doit d'abord écrire les tests, puis le code, puis la doc.

### Étape 1 : Setup Backend
```
Lis trading-journal-specs-v5.md.
Initialise le backend PHP dans api/ :
- composer.json avec dépendances (firebase/php-jwt, respect/validation, phpunit)
- phpunit.xml
- .env.example
- public/index.php + .htaccess
- Core classes (Router, Request, Response, Database, Controller)
- Tous les Enums (OrderStatus, TradeStatus, ExitType, Direction, etc.)
```

### Étape 2 : Schema BDD
```
Crée api/database/schema.sql avec toutes les tables selon les specs v5 section 5.
```

> Puis tu exécutes ce SQL toi-même dans phpMyAdmin.

### Étape 3 : Auth (TDD)
```
Implémente l'authentification JWT en TDD :
1. Écris d'abord les tests (register, login, logout, refresh, me)
2. Implémente AuthController, AuthService, AuthMiddleware, UserRepository
3. Fais passer tous les tests
4. Livre la doc dans docs/authentication.md
```

### Étape 4 : Setup Frontend
```
Initialise le frontend Vue 3 dans frontend/ :
- Vite + Vue 3 + Vue Router + Pinia
- Tailwind CSS + PrimeVue
- Vitest + Vue Test Utils
- vue-i18n avec fr.json et en.json (structure selon specs)
- Constants JS (enums miroir du backend)
- Service API avec Axios
```

### Étape 5 : Premier CRUD complet - Accounts (TDD)
```
CRUD Accounts en TDD pour valider toute l'architecture :
1. Tests backend d'abord (Service + Repository + Controller)
2. Implémentation backend
3. Tests frontend (composables, services)
4. Implémentation frontend (View, Form, List)
5. Doc dans docs/accounts.md
```

Puis continuer en TDD : Positions → Orders → Trades → Stats...

---

## 7. Questions Probables

| Question | Ta réponse |
|----------|------------|
| Chemin du projet ? | `D:\Dev\trading-journal\` (adapte) |
| URL de l'API ? | `http://localhost/trading-journal/api/public` |
| Credentials BDD ? | Voir section 3.3 |
| Librairie UI ? | PrimeVue |
| Librairie charts ? | Chart.js |

---

## 8. Checklist Finale

Avant de lancer Claude Code :

- [ ] WAMP fonctionne (Apache + MySQL)
- [ ] PHP 8.2+ accessible en CLI
- [ ] Composer installé
- [ ] Node.js + npm installés
- [ ] Dossier projet créé avec git init
- [ ] `.claude/skills/project.md` créé
- [ ] `trading-journal-specs-v5.md` copié à la racine
- [ ] `.gitignore` créé
- [ ] BDD `trading_journal` créée dans MySQL
- [ ] Credentials BDD notés

---

## 9. Lancement

Dans le dossier du projet :

```bash
cd D:\Dev\trading-journal
claude
```

Première instruction :
```
Lis trading-journal-specs-v5.md et le skill dans .claude/skills/.
Initialise le backend PHP dans api/ avec la structure MVC, les Enums,
et la config PHPUnit. On suit une méthodologie TDD sur tout le projet.
```

---

**Bonne chance !** 🚀
