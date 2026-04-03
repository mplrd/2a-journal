# Évolutions à traiter

Retours et améliorations à intégrer après l'implémentation initiale.

## UX / Frontend

### ~~1. Partage de position (copie texte)~~ ✅
- ~~La spec prévoit une section de partage dans la modale de création d'ordre~~
- ~~Non implémenté lors de l'étape 7~~
- **Résolu** : ShareService backend (2 endpoints GET text/text-plain sur positions), ShareDialog frontend (emojis/sans emojis, clipboard), bouton partage sur OrdersView et TradesView. Format adapté selon type (ordre vs trade) et statut (open vs closed). 436 backend + 84 frontend tests verts. Voir `docs/10-share-position.md`

### ~~2. Tooltips sur les boutons des grids~~ ✅
- ~~Les boutons d'action dans les DataTable (edit, delete, cancel, execute, transfer...) doivent avoir des tooltips~~
- ~~Applicable à : AccountsView, PositionsView, OrdersView~~
- **Résolu** : `v-tooltip.top` ajouté sur tous les boutons d'action des 4 vues (AccountsView, PositionsView, OrdersView, TradesView). Directive `Tooltip` enregistrée dans `main.js` (manquait initialement).

### ~~13. Refonte header : menu burger + menu compte utilisateur~~ ✅
- ~~Le header devient surchargé avec l'accumulation des liens et actions~~
- ~~Menu burger (hamburger) pour les entrées de navigation principales (Positions, Orders, Trades, Symbols...)~~
- ~~Menu "compte" sur l'avatar/nom utilisateur : logout, sélecteur de langue, préférences...~~
- ~~Responsive friendly~~
- **Résolu** : Layout refactorisé — header full-width en haut, sidebar CSS persistant en dessous (ouvert par défaut sur desktop, overlay sur mobile, état persisté en localStorage). Popover utilisateur (avatar initiales, logout), sélecteur FR/EN dans le header. Colonne "Compte" ajoutée dans les grids Positions/Ordres/Trades. Directive Tooltip enregistrée dans main.js. 91 frontend tests verts. Voir `docs/13-nav.md`

### ~~3. Setup en badges multi-sélection~~ ✅
- ~~Le champ "setup" ne doit pas être un simple texte libre~~
- ~~Un trade peut être pris sur plusieurs setups en convergence → multi-tags/badges~~
- ~~Quand l'utilisateur tape un setup qui n'existe pas → auto-création (insert)~~
- ~~Nécessite : table `setups` (ou stockage JSON), composant chips/tags avec autocomplete~~
- ~~Impacte : OrderForm, PositionForm, schema BDD potentiellement~~
- **Résolu** : table `setups` per-user avec CRUD (GET/POST/DELETE /setups), seed de 8 setups par défaut à l'inscription. Champ `positions.setup` migré en TEXT stockant un JSON array. Validation array dans OrderService/TradeService/PositionService avec auto-création via `INSERT IGNORE`. Frontend : AutoComplete PrimeVue `multiple` dans OrderForm/TradeForm/PositionForm, Tags dans les grids, share preview avec join ` | `. 477 backend tests, build frontend OK. Voir `docs/12-setup-badges.md`

### ~~4. Sélecteur de langue dans le header~~ ✅
- ~~Actuellement la locale est fixée par `VITE_DEFAULT_LOCALE` (défaut `fr`)~~
- ~~Ajouter un sélecteur fr/en dans le AppLayout header~~
- ~~Persister le choix (localStorage ou préférence user en BDD)~~
- **Résolu** : dropdown FR/EN dans AppLayout, persistance localStorage, chargement au démarrage via `locales/index.js`, 5 tests Vitest. Voir `docs/03-auth-jwt.md` § Préférences utilisateur

## Modèle de données

### ~~5. Repenser le "mode" des comptes~~ ✅
- ~~Actuellement : `mode` = DEMO | LIVE | CHALLENGE | VERIFICATION | FUNDED~~
- ~~Problème : "DEMO" est un **type de compte**, pas un mode/étape~~
- **Résolu** : `account_type` (BROKER_DEMO, BROKER_LIVE, PROP_FIRM) + `stage` nullable (CHALLENGE, VERIFICATION, FUNDED). Validation conditionnelle : stage requis pour PROP_FIRM, interdit pour les autres. 441 backend + 84 frontend tests verts. Voir `docs/05-crud-accounts.md`

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
- **Résolu** : endpoint `PATCH /auth/locale`, détection langue navigateur à l'init (fallback `en`), locale envoyée à l'inscription, watcher AppLayout applique la locale du profil au login. 412 backend + 78 frontend tests verts. Voir `docs/03-auth-jwt.md` § Préférences utilisateur

## Partage (suite de l'évol #1)

### 9. Générer une image de partage (card visuelle PNG)
- La spec prévoit une card visuelle du trade exportable en PNG (priorité P2)
- Endpoint : `GET /positions/{id}/share/image`
- Impacte : backend (génération image), frontend (bouton dans ShareDialog)

### 10. Lien de partage public
- URL temporaire ou permanente pour partager un trade sans authentification (priorité P3)
- Endpoints : `POST /positions/{id}/share/link`, `GET /share/{token}`
- Nécessite : table `share_links` (token, expiration, position_id)

### ~~11. Partage direct vers messageries/réseaux~~ ✅
- ~~Boutons de partage rapide : WhatsApp, Telegram, Twitter/X, Discord...~~
- ~~Utilise le texte déjà généré par ShareService (avec/sans emojis selon la plateforme)~~
- ~~Via liens deep-link natifs de chaque plateforme (web share API ou URLs d'intent)~~
- **Résolu** : 5 boutons de partage dans ShareDialog (WhatsApp, Telegram, X/Twitter, Discord, Email). Deep links natifs, troncature Twitter à 280 chars, Discord via clipboard, Email via mailto. Preview live du texte de partage dans OrderForm et TradeForm (composable `useSharePreview`, bouton copier). 7 tests dédiés, 91 frontend tests verts. Voir `docs/11-share-platforms.md`

### 12. Personnalisation du partage
- Choix des infos à inclure/masquer (SL, taille...)
- Templates de format adaptés par plateforme
- Branding personnel (nom/pseudo, logo)

## Import / Connecteurs broker

### ~~18. Import de trades (fichier)~~ ✅ (partiellement)
- **Import fichier** : upload manuel avec templates de mapping par broker + mode custom
- **Templates livrés** : cTrader (XLSX/CSV), FTMO (CSV/XLSX), FXCM (XML SpreadsheetML)
- **Fonctionnalités génériques livrées** : déduplication headers, fusion multi-lignes, séparateur de milliers, `opened_at` optionnel, accept dynamique frontend
- Preview avant import, détection de doublons (hash external_id), mapping symboles broker ↔ journal, rollback
- Tables : `import_batches` (audit trail), `symbol_aliases` (mapping symboles)
- Voir `docs/19-import-history.md`, `docs/20-import-ftmo.md`, `docs/21-import-fxcm.md`
- **Connecteurs API** : cTrader (WebSocket JSON + OAuth2) et MetaApi (MT4/MT5 REST) livrés. Voir `docs/22-broker-connectors.md`
- **Reste à faire** : templates MT4/MT5 pour import fichier, sync automatique planifiée (cron)

### 20. Import "transaction log" (matching achats/ventes)
- **Contexte** : certaines plateformes (brokers actions, exchanges crypto) exportent un historique de transactions individuelles (achat OU vente) et non des trades complets (entrée + sortie + PnL)
- **Plateformes identifiées** : SwissBorg (XLSX), Fortuneo (CSV), Ouinex (CSV), BingX (à venir)
- **Principe** : nouveau mode d'import qui reconstitue des positions à partir de transactions isolées
  1. Parser le fichier et filtrer les opérations pertinentes (Achat/Vente, Buy/Sell — ignorer dividendes, dépôts, conversions, frais...)
  2. Grouper par symbole/instrument
  3. Matcher les entrées avec les sorties en FIFO (ou LIFO, configurable) pour reconstituer des positions avec PnL calculé
  4. Gérer les positions partiellement fermées (partie encore ouverte)
- **Différence avec l'import actuel** : l'import classique (cTrader, FTMO, FXCM) attend des trades complets avec entry_price + exit_price + PnL déjà calculés ; le mode transaction log calcule le PnL à partir des prix d'achat et de vente
- **Fichiers de référence disponibles** dans `trading-journal-exports/` : ouinex, swissborg, fortuneo
- **Priorité** : moyenne — à traiter après la stabilisation de l'import classique

### 21. UFunded — export limité, à surveiller
- UFunded (propfirm) n'exporte qu'un **PDF** (account statement), pas de CSV/XLSX
- Plateforme custom avec intégration TradingView, ni cTrader ni MT4/MT5
- Le PDF contient des trades complets (Transaction ID, Direction, Size, Symbol, Price, Settled PnL) mais le parsing PDF est trop fragile pour un import fiable
- **À surveiller** : vérifier si UFunded propose une API ou un export CSV à l'avenir

## Architecture / UX

### 19. Widgets autonomes avec chargement indépendant
- Repenser chaque bloc de l'app (KPI cards, charts, calendrier, trades récents, etc.) comme un widget autonome
- Chaque widget se charge en AJAX indépendamment : son propre état loading (skeleton/spinner), son propre fetch, sa propre gestion d'erreur
- Avantages : affichage progressif (le plus rapide s'affiche en premier), pas de loader global qui bloque toute la page, meilleure résilience (un widget en erreur n'empêche pas les autres)
- Impacte : refactoring des views (Dashboard, Performance) pour éclater le `Promise.all` monolithique en fetches indépendants par widget
- Pattern : composable `useAsyncWidget(fetchFn)` retournant `{ data, loading, error, refresh }`

## Sécurité / Auth

### 14. Authentification à deux facteurs (2FA/MFA)
- TOTP (Google Authenticator, Authy) ou WebAuthn
- Activation optionnelle dans les préférences du compte
- Codes de récupération en cas de perte du device

### 15. Audit log des événements d'authentification
- Historique des connexions (date, IP, user-agent)
- Alertes de connexion depuis un nouvel appareil/IP
- Vue dédiée dans le profil utilisateur

### 16. Blocage de l'app sans vérification d'email
- Actuellement : un bandeau d'avertissement non bloquant s'affiche sur le dashboard si l'email n'est pas vérifié
- Quand l'abonnement payant sera en place : bloquer totalement l'accès à l'app après login, rediriger vers une page "Vérifiez votre email" tant que l'email n'est pas confirmé
- Justification : avec paiement, on doit garantir l'identité de l'utilisateur

### 17. Gestion des sessions actives
- Liste des sessions ouvertes (device, IP, date)
- Possibilité de révoquer une session à distance
- Déconnexion de toutes les sessions

## Traductions

### ~~6. Vérifier les clés manquantes~~ ✅
- ~~Un label non traduit a été repéré en test manuel~~
- ~~Identifier et corriger la/les clé(s) manquante(s)~~
- ~~Vérifier la parité fr/en (déjà OK selon le dernier audit qualité)~~
- **Résolu** : audit i18n complet (skill `/check-i18n`), ajout clé `common.no_options` + prop `emptyMessage` sur les Select PrimeVue (OrderForm, TransferDialog)
