# Évolutions à traiter

Retours et améliorations à intégrer après l'implémentation initiale.

## UX / Frontend

### 1. Partage d'ordre (modale de création)
- La spec prévoit une section de partage dans la modale de création d'ordre
- Non implémenté lors de l'étape 7
- Cf. `docs/specs/trading-journal-specs-v5.md` pour le détail

### ~~2. Tooltips sur les boutons des grids~~ ✅
- ~~Les boutons d'action dans les DataTable (edit, delete, cancel, execute, transfer...) doivent avoir des tooltips~~
- ~~Applicable à : AccountsView, PositionsView, OrdersView~~
- **Résolu** : `v-tooltip.top` ajouté sur tous les boutons d'action des 4 vues (AccountsView, PositionsView, OrdersView, TradesView)

### 3. Setup en badges multi-sélection
- Le champ "setup" ne doit pas être un simple texte libre
- Un trade peut être pris sur plusieurs setups en convergence → multi-tags/badges
- Quand l'utilisateur tape un setup qui n'existe pas → auto-création (insert)
- Nécessite : table `setups` (ou stockage JSON), composant chips/tags avec autocomplete
- Impacte : OrderForm, PositionForm, schema BDD potentiellement

### ~~4. Sélecteur de langue dans le header~~ ✅
- ~~Actuellement la locale est fixée par `VITE_DEFAULT_LOCALE` (défaut `fr`)~~
- ~~Ajouter un sélecteur fr/en dans le AppLayout header~~
- ~~Persister le choix (localStorage ou préférence user en BDD)~~
- **Résolu** : dropdown FR/EN dans AppLayout, persistance localStorage, chargement au démarrage via `locales/index.js`, 5 tests Vitest

## Modèle de données

### 5. Repenser le "mode" des comptes
- Actuellement : `mode` = DEMO | LIVE | CHALLENGE | VERIFICATION | FUNDED
- Problème : "DEMO" est un **type de compte**, pas un mode/étape
- Le concept d'étapes (CHALLENGE → VERIFICATION → FUNDED) ne concerne que les comptes propfirm
- Proposition :
  - `account_type` : BROKER_DEMO | BROKER_LIVE | PROP_FIRM
  - `stage` (nullable, propfirm uniquement) : CHALLENGE | VERIFICATION | FUNDED
  - Ou autre approche à définir
- Impacte : schema BDD, enums, AccountService, AccountForm, i18n

### ~~7. Symboles : dropdown/autocomplete au lieu de texte libre~~ ✅
- ~~La table `symbols` contient 6 instruments de référence (NASDAQ, DAX, SP500, CAC40, EURUSD, BTCUSD)~~
- ~~Actuellement le champ symbol dans OrderForm/PositionForm est un input texte libre~~
- ~~Devrait être un dropdown ou autocomplete alimenté par la table `symbols`~~
- ~~Point value et devise du symbole nécessaires pour les calculs de R/R (cf. spec)~~
- ~~Endpoint GET /symbols (public ou auth) à créer pour alimenter le frontend~~
- **Résolu** : table `symbols` convertie en per-user ("Mes actifs") avec CRUD complet, seeding à l'inscription (6 symboles par défaut), page dédiée SymbolsView, bouton '+' inline dans OrderForm/TradeForm/PositionForm. Voir `docs/09-symbols-user.md`

## Bugs / Fixes

### ~~8. Synchroniser la locale avec le profil utilisateur en BDD~~ ✅
- ~~Le sélecteur de langue (évol #4) persiste le choix en `localStorage` uniquement~~
- ~~Le champ `locale` de la table `users` n'est jamais mis à jour lors du changement de langue~~
- **Résolu** : endpoint `PATCH /auth/locale`, détection langue navigateur à l'init (fallback `en`), locale envoyée à l'inscription, watcher AppLayout applique la locale du profil au login. 412 backend + 78 frontend tests verts

## Traductions

### ~~6. Vérifier les clés manquantes~~ ✅
- ~~Un label non traduit a été repéré en test manuel~~
- ~~Identifier et corriger la/les clé(s) manquante(s)~~
- ~~Vérifier la parité fr/en (déjà OK selon le dernier audit qualité)~~
- **Résolu** : audit i18n complet (skill `/check-i18n`), ajout clé `common.no_options` + prop `emptyMessage` sur les Select PrimeVue (OrderForm, TransferDialog)
