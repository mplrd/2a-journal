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

- **Pas de conversion de devise.** `broker_balance` est dans la devise de règlement du broker (USDT pour BingX USDT-M), mais s'affiche tel quel dans le « Solde », à côté de la colonne `currency` du compte. **Pour un compte broker, régler la devise du compte sur USDT** (le champ est éditable). Une vraie conversion FX selon `account.currency` est hors périmètre.
- **Ajustements manuels neutralisés** sur un compte synchronisé : `COALESCE` prenant `broker_balance` en premier, un ajustement n'affecte plus le solde affiché tant que le broker fournit une valeur. Cohérent avec « broker = source de vérité » ; masquer l'action « Corriger le solde » pour ces comptes serait une amélioration UX ultérieure.
