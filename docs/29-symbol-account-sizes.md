# 29 — Valeur du point par (actif, compte)

## Contexte

L'utilisateur trade les mêmes actifs sur plusieurs comptes de tailles très différentes (ex. FTMO 10k vs FTMO 100k vs perso 25k). La **valeur du point** — c'est-à-dire ce qu'un point de variation du prix vaut en monnaie — n'est pas la même d'un compte à l'autre, parce que les contrats proposés par le broker, l'effet de levier et le capital qui soutient la position varient.

**Équivalence mathématique** : `0.5 lot × 1 €/pt = 1 lot × 0.5 €/pt`. On peut représenter le même risque soit par une taille de lot adaptée, soit par une valeur du point adaptée. Ce choix collapse les deux concepts en un seul **configurable par (symbole, compte)** : la valeur du point.

Avant cette feature :
- `symbols.point_value` était un scalaire unique par actif (ex. NASDAQ = 20 $/pt), défini au global
- `symbols.currency` stockait la devise associée à ce point value
- Résultat : impossible d'ajuster selon le compte utilisé pour le trade

## Demande

Rendre la **valeur du point configurable par couple (actif, compte)**. La devise d'affichage vient désormais du **compte** (`accounts.currency`), pas du symbole. L'onglet "Mes actifs" présente tout ça dans un **tableau à double entrée** fusionné avec les infos actif.

## Scope

### Dans le scope

- Nouvelle table `symbol_account_settings(symbol_id, account_id, point_value)` avec `UNIQUE(symbol_id, account_id)` et FK `ON DELETE CASCADE`
- Backfill dans la migration : pour chaque couple (symbole, compte) owned par le même user, INSERT avec `point_value` copié depuis `symbols.point_value` (compatibilité visuelle immédiate)
- Auto-matérialisation sur `GET /symbols/settings` : pour chaque couple sans row, INSERT avec `point_value` hérité de `symbols.point_value`
- API CRUD (3 endpoints)
- Onglet "Mes actifs" : tableau unifié (ticker/nom/type figés + 1 colonne par compte avec input + actions)
- Header de colonne compte : nom + currency du compte
- `SymbolForm` : **retrait** des champs `point_value` et `currency` (l'actif se résume à ticker/nom/type)
- Tests unit + integration + Vitest
- Migration purement additive (safe prod)

### Hors scope

- **Default lot size par (symbol, account)** : abandonné. `TradeForm` garde son default `size = 1` — l'équivalence mathématique permet de tout régler via `point_value`
- **Suppression des colonnes `symbols.point_value` et `symbols.currency`** : reportée à une migration future une fois que le prod ne les lit plus (backwards-safe)
- **`default_size` dans la matrice** : n'existe pas. Une seule input par cellule = `point_value`
- **Matrice par devise multi-actif / arbitrage currencies** : non demandé

## Conception

### Data model

```sql
-- 005_symbol_account_settings.sql (additif)
CREATE TABLE symbol_account_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    point_value DECIMAL(10,5) NOT NULL DEFAULT 1.00000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_sas_symbol_account (symbol_id, account_id),
    FOREIGN KEY (symbol_id) REFERENCES symbols(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
);

-- Backfill : pour chaque (symbol, account) owned par le même user
INSERT INTO symbol_account_settings (symbol_id, account_id, point_value)
SELECT s.id, a.id, s.point_value
FROM symbols s
JOIN accounts a ON a.user_id = s.user_id
WHERE s.deleted_at IS NULL AND a.deleted_at IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM symbol_account_settings WHERE symbol_id = s.id AND account_id = a.id
  );
```

Le scoping user se fait au service (on vérifie que symbol et account appartiennent à l'user du JWT avant toute écriture), pas via une colonne `user_id` sur la table (dérivable).

### Endpoints

| Méthode | URL | Rôle |
|---|---|---|
| GET | `/symbols/settings` | Matrice courante, auto-matérialise les couples manquants |
| PUT | `/symbols/{symbolId}/settings/{accountId}` | Upsert `{ point_value }` |
| DELETE | `/symbols/{symbolId}/settings/{accountId}` | Supprime la ligne (revient à la valeur d'hérétage au prochain GET) |

Response `GET /symbols/settings` :
```json
{
  "success": true,
  "data": {
    "settings": [
      { "symbol_id": 1, "account_id": 3, "point_value": 20 }
    ]
  }
}
```

### Règles d'accès

- Échec 404 (sans fuiter l'existence) si le symbol ou l'account n'appartient pas à l'user
- Échec 422 `symbols.error.invalid_point_value` si `point_value <= 0`
- `DELETE` idempotent (204 même si pas de row)

### UI — Matrice unifiée

Dans `AssetsTab.vue`, une seule table HTML avec :

| Ticker | Nom | Type | **FTMO 10k** (EUR) | **FTMO 100k** (EUR) | **Perso** (USD) | Actions |
|---|---|---|---|---|---|---|
| NASDAQ | Nasdaq 100 | INDEX | `[ 2.00 ]` | `[ 20.00 ]` | `[ 15.00 ]` | ✏️ 🗑️ |
| DAX | DAX 40 | INDEX | `[ 2.50 ]` | `[ 25.00 ]` | `[       ]` | ✏️ 🗑️ |

- Colonnes figées à gauche : ticker / nom / type
- Colonnes dynamiques : une par compte actif de l'user. Header = nom du compte + `({currency})` en petit
- Cellules : `InputNumber` avec upsert on blur (pas de bouton save explicite)
- Colonne Actions à droite : bouton édition (ouvre `SymbolForm` réduit à ticker/nom/type) + bouton suppression (dialog de confirmation)

### Prefill côté TradeForm

Aucun changement : le champ `size` reste saisi manuellement, default `1`. C'est le `point_value` per-account qui encode la différence de risque entre comptes.

### i18n

Retrait ou renommage :
- `symbols.point_value_per_account_hint` (nouveau, dans SymbolForm)
- `symbols.success.settings_saved`, `symbols.success.settings_cleared`
- `symbols.error.invalid_point_value` (déjà existait, conservé)
- Retirés : `sizes_matrix_*`, `accept_suggestion`, `size_saved`, `size_cleared`, `invalid_size`

## TDD

### Backend (41 tests)

**Unit `SymbolServiceTest`** :
- `setSetting` upsert / 404 si symbol ou account foreign / 422 si point_value <= 0
- `clearSetting` délègue / 404 si foreign
- `getSettingsMatrix` auto-matérialise puis retourne

**Integration `SymbolAccountSettingsRepositoryTest`** :
- Upsert insert / update (idempotent, pas de duplication)
- Delete / delete idempotent
- `findAllByUserId` scope sur l'owner
- `autoMaterializeForUser` crée 1 row par (symbol, account), inherit point_value, idempotent, n'écrase pas
- Cascade FK sur hard delete symbol / delete account

### Frontend (6 tests)

**Vitest `assets-tab-matrix.spec.js`** :
- Empty state sans symbol
- Rendu table unifié ticker/nom/type + cellules per-account
- Currency affichée dans les headers de colonnes comptes
- Prefill input depuis `point_value` saved
- Persist on blur quand valeur changée
- Pas d'appel API si valeur inchangée

## Livrables

**Backend** :
- `api/database/migrations/005_symbol_account_settings.sql`
- `api/database/schema.sql` (additif)
- `api/src/Repositories/SymbolAccountSettingsRepository.php`
- `api/src/Services/SymbolService.php` (étendu avec 3 méthodes de matrice)
- `api/src/Controllers/SymbolController.php` (3 nouvelles actions)
- `api/config/routes.php` (3 nouvelles routes auth+subscription)
- Tests Unit + Integration

**Frontend** :
- `frontend/src/services/symbols.js` (3 méthodes : settings/setSetting/clearSetting)
- `frontend/src/stores/symbolAccountSettings.js` (nouveau store Pinia)
- `frontend/src/components/account/AssetsTab.vue` (grille unifiée)
- `frontend/src/components/symbol/SymbolForm.vue` (retrait point_value + currency)
- `frontend/src/locales/{fr,en}.json`
- `frontend/src/__tests__/assets-tab-matrix.spec.js`

**Docs** :
- `docs/29-symbol-account-sizes.md` (ce fichier)

## Compatibilité prod

- Migration **purement additive** : nouvelle table, aucun ALTER sur tables existantes
- `symbols.point_value` / `symbols.currency` **restent en base** (pas de DROP). Seule l'UI ne les expose plus. Une migration future fera le cleanup quand rien ne lira plus ces colonnes
- Aucun endpoint existant modifié en comportement
- `TradeForm` inchangé (pas de regression sur la création de trade)
- `SymbolForm` : champs visuellement retirés, l'API accepte encore `point_value`/`currency` dans le body (via validation legacy) pour ne pas casser un client qui les enverrait
- Flow de déploiement : test env d'abord (test-journal.2a-trading-tools.com) → validation → prod
