# 78 - Affichage du solde courtier synchronisé (redescente de `broker_balance`)

## Contexte / problème

La sync broker (BingX) persistait `accounts.broker_balance` (migration 024, doc 76) mais **personne ne l'affichait** : aucun endpoint ne renvoyait la colonne, aucun composant front ne la lisait. Le « Solde » de l'UI restait calculé uniquement par le journal :

```
Solde (current_capital) = initial_capital + Σ(PnL trades CLOS) + Σ(ajustements)
```

Résultat concret constaté en test env : un compte BingX avec **100 USDT réels sur le wallet Futures** (vérifié en appelant directement l'API BingX) affichait **Solde 0** — parce que `initial_capital=0`, aucun trade, aucun ajustement, et `broker_balance` (bien rempli à 100.00) n'était **branché à rien côté lecture**. Une colonne write-only : synchroniser sans redescendre la donnée n'a aucun intérêt.

## Décision

**Quand un compte est lié à un broker et a un solde synchronisé, ce solde courtier est la source de vérité et remplace le solde calculé.** (Choix retenu vs un champ « solde courtier » séparé.)

## Correctif

### Backend — `AccountRepository`

Dans `findById()` et `findAllByUserId()`, le `current_capital` devient :

```sql
COALESCE(a.broker_balance, a.initial_capital + COALESCE(pnl.total,0) + COALESCE(adj.total,0)) AS current_capital
```

- `broker_balance` renseigné (compte synchronisé) → **il prime**.
- `broker_balance` NULL (compte manuel, jamais synchronisé) → **calcul inchangé** (comptes manuels non impactés).

Le payload expose aussi `broker_balance` et `broker_balance_synced_at` pour que l'UI puisse signaler la source et l'heure de sync.

Le check drawdown (`findByIdForDdCheck`) ne calcule pas `current_capital` (il lit juste la config DD) → non impacté.

### Frontend — `AccountsView.vue`

Le solde affiché (`current_capital`) reflète automatiquement le solde courtier (aucun changement de binding nécessaire). Ajout d'un repère visuel : quand `broker_balance` est présent, une **icône ⟳** à côté du solde, avec un tooltip « Solde synchronisé depuis le courtier le {date} » (desktop + tuile mobile). Helpers `isBrokerSynced()` / `brokerSyncedTooltip()`.

i18n : `accounts.broker_synced_at` ajoutée dans `fr.json` et `en.json`.

## Fichiers

**Modifiés**
- `api/src/Repositories/AccountRepository.php` — `COALESCE(broker_balance, …)` + colonnes `broker_balance`/`broker_balance_synced_at` dans les 2 SELECT.
- `api/tests/Integration/Repositories/AccountRepositoryTest.php` — 4 tests : override (list + findById), fallback quand NULL, exposition `broker_balance`/`synced_at` dans le payload.
- `frontend/src/views/AccountsView.vue` — helpers + icône sync (desktop + mobile).
- `frontend/src/locales/{fr,en}.json` — clé `accounts.broker_synced_at`.

## Tests

- `vendor/bin/phpunit --filter AccountRepositoryTest` → **20 verts** (4 nouveaux).
- Suite Account complète → **158 verts**, 0 régression.
- Frontend `vitest run` → **386 verts** (les 10 « unhandled errors » sont pré-existantes : directive `tooltip` dans `setups-tab.spec`, `api.get` dans `customFields.js` — sans rapport).

## Limites connues / à suivre

- **Pas de conversion de devise** (signalement seulement). `broker_balance` est dans la devise de règlement du broker (USDT pour BingX USDT-M). On ne convertit pas vers `account.currency` — à la place, **un mismatch est signalé** (cf. complément ci-dessous). **Pour un compte broker, régler la devise du compte sur USDT** reste recommandé (champ éditable). Une vraie conversion FX est hors périmètre.

## Complément : devise du solde courtier + alerte de mismatch (point 1)

`broker_balance` était une valeur nue, sans devise → impossible de détecter proprement un écart avec `accounts.currency`. Ajouts :

- **Migration 031** : colonne additive `accounts.broker_balance_currency VARCHAR(10) NULL`.
- **Connector** : `BingxConnector::getBalanceCurrency()` renvoie l'asset de la ligne de solde retenue (`USDT` pour la ligne USDT-M ; `extractEquity` trace la devise via `currencyFromRow`). Exposé au `BrokerSyncService` via le pattern `method_exists` (comme `setKnownSymbols`/`getSeenSymbols`), pas de changement de `ConnectorInterface`.
- **Persistance** : `AccountRepository::updateBrokerBalance($id, $balance, ?$currency)` écrit aussi `broker_balance_currency` ; les 2 SELECT le renvoient.
- **Front** : icône ⚠️ `pi-exclamation-triangle` à côté du solde quand `account.currency` ≠ `broker_balance_currency` (helper `hasCurrencyMismatch`), tooltip i18n `accounts.broker_currency_mismatch`. Aucune conversion — simple alerte.

## Complément : devises stablecoin sélectionnables (USDT/USDC)

Conséquence directe de l'alerte ci-dessus : pour la **lever**, l'utilisateur doit pouvoir régler la devise de son compte sur **USDT**. Or la devise était verrouillée à 3 caractères partout (validation `strlen === 3` / regex `^[A-Z]{3}$`, colonnes `VARCHAR(3)`, `<InputText :maxlength="3">`, et un `<Select>` de préférences sans stablecoins) → impossible de saisir « USDT » (4 lettres).

- **Migration 032** : `users.default_currency` et `accounts.currency` élargies en `VARCHAR(10)` (`symbols.currency` laissée telle quelle).
- **Validation** : `AccountService` et `AuthService` passent de « exactement 3 » à `^[A-Z]{3,5}$` (fiat ISO 3 lettres + stablecoins 4-5).
- **Front** : liste partagée `frontend/src/constants/currencies.js` (`CURRENCIES`, fiat majors + USDT/USDC). `PreferencesTab` (devise par défaut user) et `AccountForm` (devise du compte, ex-`InputText` → `Select`) la consomment.

Après ça : régler le compte BingX sur **USDT** fait correspondre `account.currency` et `broker_balance_currency` → plus d'alerte ⚠️.
- **Ajustements manuels neutralisés** sur un compte synchronisé : `COALESCE` prenant `broker_balance` en premier, un ajustement n'affecte plus le solde affiché tant que le broker fournit une valeur. Cohérent avec « broker = source de vérité ». → L'action **« Corriger le solde » est désormais masquée** pour les comptes synchronisés (`isBrokerSynced` : `broker_balance !== null`), en grille desktop et dans le menu d'actions mobile.
