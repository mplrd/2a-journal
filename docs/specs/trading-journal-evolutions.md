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

### 22. ⚠️ CRITIQUE — Budget de requêtes cTrader : un compte FTMO désactivé pour hyperactivité
- **Déclencheur** : le 2026-08-07, FTMO a temporairement désactivé le compte de trading 7589848 « due to the amount of activity ». Seuil annoncé : **2 000 trades ou requêtes serveur par jour** (SL/TP modifications et annulations d'ordres incluses dans leur décompte).
- **Le compte n'est pas piloté par un EA** : le seul automatisme branché dessus est la synchro du journal, en lecture seule.
- **Décompte réel d'un cycle de synchro cTrader** — `BrokerSyncService::sync()` enchaîne cinq appels connecteur, chacun ouvrant **sa propre session WebSocket** et rejouant `ProtoOAApplicationAuthReq` + `ProtoOAAccountAuthReq` :

  | Appel | Requêtes émises |
  |---|---|
  | `fetchDeals` | AppAuth, AccountAuth, Reconcile, DealList (+ pagination), SymbolsList, SymbolById → **6** |
  | `fetchOpenPositions` | AppAuth, AccountAuth, Reconcile, SymbolById → **4** |
  | `fetchOpenOrders` | AppAuth, AccountAuth, Reconcile, SymbolById → **4** |
  | `fetchClosedOrders` | AppAuth, AccountAuth, OrderList → **3** |
  | `fetchBalance` | AppAuth, AccountAuth, TraderReq, AssetList → **4** |

  **≈ 21 requêtes par cycle**, dont **10 ne sont que des ré-authentifications** et **3 sont le même `ProtoOAReconcileReq`** émis à quelques secondes d'intervalle. S'ajoute un appel HTTPS de refresh OAuth par cycle (`openapi.ctrader.com`, hors serveur de trading).
- **Volume résultant** : `BROKER_SYNC_INTERVAL_MINUTES` vaut 15 par défaut → 96 cycles/jour → **2 016 requêtes/jour et par connexion**. Le seuil FTMO est à 2 000. Les synchros manuelles s'ajoutent par-dessus, et **plusieurs connexions ACTIVE pointant sur le même compte broker multiplient le tout** (rien n'empêche aujourd'hui d'en créer deux).
- **Réserve** : leurs exemples ne citent que des écritures (trades, SL/TP, annulations). Non confirmé qu'ils comptabilisent les lectures. Question posée au support le 2026-08-07 — **reporter leur réponse ici**, elle conditionne le dimensionnement.
- **Ce que le journal n'envoie jamais sur cTrader** (à garder vrai) : aucun ordre, aucune modification de SL/TP — les types `Amend*` ne sont même pas dans `CtraderConnector::PAYLOAD_TYPES` et `modifyOrder()` lève `NOT_IMPLEMENTED`. Seul chemin d'écriture existant : les webhooks TradingView (`TradingViewWebhookService`), donc uniquement si un robot est armé.
- **À faire**, par ordre de délai :
  1. ✅ **Immédiat, sans déploiement** : relever `BROKER_SYNC_INTERVAL_MINUTES` (60 → ~500 req/jour) ou désactiver la connexion concernée. **Fait le vendredi 2026-08-07**, le jour même de la désactivation du compte — action d'exploitation depuis le BO admin, sans déploiement. La valeur retenue n'est pas consignée ici ; la ligne `sync_request_budget` (point 3) donnera de toute façon le volume réel plutôt que le volume supposé.
  2. ✅ **Fond** (livré le 2026-08-09, branche `fix/ctrader-request-budget`, doc [90](../90-ctrader-budget-requetes.md)) : une session WebSocket unique par run au lieu de cinq, et un seul `ProtoOAReconcileReq` partagé entre deals / positions / ordres. Mesuré : **19 requêtes ramenées à 9** (l'estimation initiale de ~21→~8 était juste à une requête près, les tailles de lot étant aussi mises en cache). Reprenait l'entrée « Une synchro cTrader ouvre quatre sessions WebSocket » de `docs/evolutions.md`, marquée traitée.
  3. 🟡 **Garde-fous** — deux sur trois livrés le 2026-08-09 :
     - ✅ compter et journaliser les requêtes émises par run → ligne `sync_request_budget` (total + détail par type nommé, avec `connection_id`), émise même quand le run échoue ;
     - ✅ interdire plusieurs connexions sur un même compte broker → `BrokerConnectionService::assertBrokerAccountFree()`, à la création et à la reconfiguration. Contrôle applicatif et non contrainte SQL : l'identifiant du compte broker vit dans le blob chiffré ;
     - ❌ budget quotidien par connexion avec intervalle dérivé → **non traité**, demande un compteur journalier persisté (migration) et une surface admin. Écarté sciemment du lot.
- **Fichiers** : `api/src/Services/Broker/CtraderConnector.php`, `api/src/Services/Broker/BrokerSyncService.php`, `api/config/broker.php`, `scheduler/crontab`.
- **Priorité** : **critique** — c'est le seul point du backlog dont la conséquence est la perte d'un compte de trading réel, chez un prop firm.
- **Statut au 2026-08-09** : le fond et deux garde-fous sur trois sont livrés sur `fix/ctrader-request-budget`, **validés par les tests uniquement**. Rien n'a encore tourné contre un vrai cTrader : le flag broker et les identifiants réels rendent la vérification impossible en local, elle ne peut avoir lieu qu'en env de test. C'est la ligne `sync_request_budget` dans les logs qui permettra de la faire — sans elle, on ne pourrait que relire le code, exactement comme il a fallu le faire pour instruire l'incident.

### 23. Identifiants d'application partagés entre les connexions d'un même provider

- **Déclencheur** : un utilisateur ayant deux comptes cTrader saisit deux fois `client_id`, `client_secret`, `access_token` et `refresh_token`, alors que seul le `ctidTraderAccountId` diffère entre les deux connexions. Constaté le 2026-08-09.
- **Conséquences aujourd'hui** : double saisie ; une rotation de secret à répercuter manuellement sur chaque connexion ; et un refresh OAuth par connexion là où un seul suffirait — ce dernier point rejoignant directement l'évol #22 (budget de requêtes).
- **Principe retenu : une configuration portée par le provider, pas un cas particulier cTrader.** Un flag `shared` dans `BrokerCredentialMapper::SPEC`, à côté du flag `identity` déjà en place. Toute la feature en découle, et un provider qui ne déclare rien garde exactement son comportement actuel.

  | Provider | `shared` (niveau utilisateur) | `identity` (niveau connexion) |
  |---|---|---|
  | cTrader | `client_id`, `client_secret`, `access_token`, `refresh_token` | `ctid_trader_account_id` |
  | MetaApi | `api_token` | `metaapi_account_id` |
  | Ouinex | — | `service_api_key` |
  | BingX | — | `api_key` |

  Ouinex et BingX n'ont rien à partager : chez eux la clé d'API **est** le compte. Ils traversent le chantier sans changer de comportement — c'est le test de la généricité.
- **Modèle** : table `broker_credentials` (`user_id`, `provider`, `credentials_encrypted`, `credentials_iv`), unique sur le couple. `broker_connections` ne garde que son identifiant de compte. La fusion des deux se fait au moment de servir un connecteur, donc `ConnectorInterface` est inchangée.
- **Migration** : création de la table **puis purge des connexions existantes** — décision prise le 2026-08-09, personne n'utilise le broker en dehors de l'env de test. Conséquence assumée : `sync_logs` est en `ON DELETE CASCADE` sur `broker_connections`, l'historique des passes de synchro part avec. Les trades et positions sont rattachés aux **comptes**, pas aux connexions : ils survivent intégralement. Le curseur de synchro étant perdu, la passe suivante rebalaie l'historique sans réimporter (déduplication sur `external_id`).
- **UX — tout reste dans la modale de synchro**, décision du 2026-08-09 après arbitrage entre trois options. Le partage est un fait de stockage, il ne doit imposer aucune navigation supplémentaire : à la première connexion on saisit les tokens comme aujourd'hui ; aux suivantes ils arrivent déjà remplis et repliés, et il ne reste que le compte broker à choisir. **Un bandeau doit dire combien de connexions ces identifiants alimentent** — sans lui, modifier un token depuis la connexion n°2 changerait silencieusement la n°1, ce qui est précisément le piège du partage.
- **Fichiers** : `api/src/Services/Broker/BrokerCredentialMapper.php`, `BrokerConnectionService.php`, `BrokerSyncService.php`, nouvelle migration, `frontend/src/components/broker/`.
- **Priorité** : moyenne — confort de saisie, mais avec un gain réel sur le budget de requêtes et sur la rotation des secrets.
- **Statut au 2026-08-10 : livré** (`docs/91-broker-shared-credentials.md`), migration 036 appliquée en local. **Validé par les tests uniquement** — comme tout le domaine broker, le flag et les identifiants réels interdisent la vérification en local.
- **Découvert en chemin** : le partage du `refresh_token` cTrader créait une course. cTrader fait tourner ce token à chaque usage, donc deux connexions synchronisées dans le même tick de scheduler présentaient le même token, et la seconde échouait sur un token déjà consommé — basculement en `ERROR`, exactement le mode de panne de l'évol #22. Fermé par un saut du refresh quand les identifiants partagés ont moins de 300 s. Ce saut est aussi le premier gain concret de l'évol #22 : un appel de refresh pour tout le provider d'un utilisateur au lieu d'un par connexion, dans le cas concurrent. **Le cas général reste à traiter dans #22** : une connexion isolée qui synchronise toutes les 15 minutes redemande toujours un token à chaque passe, alors que rien n'a expiré.

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
