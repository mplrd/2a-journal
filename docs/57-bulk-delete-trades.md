# Étape 57 — Suppression en lot des trades + filtre par plage de dates

## Résumé

L'utilisateur peut désormais nettoyer son historique de trades en bloc plutôt qu'un par un. Sur la vue **Trades**, deux ajouts conjoints :

1. **Filtre par plage de dates** (`date_from` / `date_to`) sur la date d'ouverture du trade. Cohérent avec le filtre existant côté Performance.
2. **Sélection multiple + suppression en lot** : checkbox de sélection sur chaque ligne (et "select all" sur la page courante), barre d'action rouge en haut quand au moins une ligne est sélectionnée, modale de confirmation avec récap exhaustif (nb de trades, plage de dates min→max, comptes concernés, P&L cumulé impacté).

La suppression backend est **transactionnelle** : tous les trades du batch sont supprimés ensemble ou aucun (en cas d'erreur DB sur n'importe quel item, `ROLLBACK` complet).

Source du besoin : `docs/retours-beta-tests.md` ticket E-07 (évoqué oralement par l'utilisateur en revue, reformulé en spec précise dans le tracker).

## Comportement

### Filtres

- Composant **`DateRangePicker.vue`** (commun, déjà utilisé sur `DashboardFilters`) : un seul bouton avec popover qui contient des presets (7 derniers jours, 30 derniers jours, mois en cours, trimestre, year-to-date) + un calendrier inline range. Bien plus compact que 2 inputs séparés.
- Format YYYY-MM-DD côté API (validé par regex `^\d{4}-\d{2}-\d{2}$`). La conversion Date → string YYYY-MM-DD est faite côté Vue dans `applyFilters` via un helper `ymd()`.
- Filtres optionnels et combinables : si seul `date_from` est rempli, on filtre depuis cette date sans borne haute (et inversement).
- À chaque changement, un `watch([filterDateFrom, filterDateTo])` debounce 200ms appelle `applyFilters()` ; la sélection courante est vidée pour éviter d'opérer sur des items hors filtre.

### Layout filtres + bouton "Nouveau"

Les 3 filtres (compte, statut, période) et le bouton **Nouveau trade** tiennent sur **une seule ligne** (avec wrap responsive). Chaque filtre est en pile (`flex-col`) avec son label au-dessus, ce qui permet aux composants larges (BadgeFilter avec plusieurs comptes) de respirer sans pousser les autres à la ligne suivante. Le bouton est poussé à droite via `ml-auto`.

```html
<div class="flex items-end gap-4 flex-wrap mb-4">
  <div class="flex flex-col gap-1">
    <span>{{ t('trades.account') }}</span>
    <BadgeFilter ... />
  </div>
  <div class="flex flex-col gap-1">
    <span>{{ t('trades.status') }}</span>
    <BadgeFilter ... />
  </div>
  <div class="flex flex-col gap-1 min-w-[220px]">
    <span>{{ t('trades.date_range') }}</span>
    <DateRangePicker v-model:from="..." v-model:to="..." />
  </div>
  <div class="ml-auto">
    <Button :label="t('trades.create')" icon="pi pi-plus" />
  </div>
</div>
```

### Sélection multiple

- Colonne `<Column selectionMode="multiple">` PrimeVue → checkbox par ligne + checkbox "select all" dans le header.
- "Select all" couvre **uniquement la page courante** (pas l'ensemble du résultat filtré). Choix volontaire MVP : éviter qu'un user supprime 5000 trades en un clic par mégarde. Pour vider un challenge complet : augmenter `per_page` à 100, sélectionner tout, supprimer ; répéter.
- DataTable utilise `dataKey="id"` pour que la sélection survive aux re-rendus / paginations.

### Barre d'action

Visible quand `selectedTrades.length > 0`. Bordure + fond rouge clair pour signaler la dangerosité. Compteur de sélection à gauche, bouton "Supprimer la sélection" à droite (`severity="danger" outlined`).

### Modale de confirmation (`Dialog` PrimeVue)

Contenu (récap calculé côté frontend à partir des `selectedTrades`) :
- **Nombre de trades** sélectionnés.
- **Plage de dates** min→max (`opened_at` truncated à YYYY-MM-DD).
- **Comptes concernés** (déduplication par nom).
- **P&L cumulé impacté** = somme des `pnl` non-null. Coloré en vert/rouge selon le signe.
- **Avertissement** rappelant l'irréversibilité et le cascade sur les sorties partielles.

Footer : bouton "Annuler" + bouton "Supprimer définitivement" (severity danger, loading spinner pendant la requête).

## Backend

### Filtre date sur `findAllByUserId`

`api/src/Repositories/TradeRepository.php` — ajout de 2 conditions optionnelles dans le builder de WHERE :

```php
if (!empty($filters['date_from'])) {
    $where .= ' AND t.opened_at >= :date_from';
    $params['date_from'] = $filters['date_from'] . ' 00:00:00';
}
if (!empty($filters['date_to'])) {
    $where .= ' AND t.opened_at <= :date_to';
    $params['date_to'] = $filters['date_to'] . ' 23:59:59';
}
```

`TradeService::list` whiteliste `date_from` / `date_to` via une regex `^\d{4}-\d{2}-\d{2}$` ; les valeurs malformées sont silencieusement ignorées (pas de 400 sur un mauvais query param).

`TradeController::index` propage les nouveaux query params depuis la requête.

### `TradeRepository::findByIdsForUser(int $userId, array $tradeIds): array`

Lookup ownership + collecte de `position_id` en un seul round-trip :

```sql
SELECT t.id, t.position_id
FROM trades t
INNER JOIN positions p ON p.id = t.position_id
WHERE p.user_id = ? AND t.id IN (?, ?, ...)
```

Les trades inexistants ou appartenant à un autre user sont absents du résultat ; le service compare `count(returned) === count(input)` pour détecter un mismatch.

### `TradeService::deleteBulk(int $userId, array $tradeIds): int`

Pipeline :

1. Validation : `ids` non-vide → `ValidationException('trades.error.bulk_delete_empty')`.
2. Plafond `BULK_DELETE_MAX = 500` → `ValidationException('trades.error.bulk_delete_too_many')`.
3. Déduplication + cast int des IDs.
4. Ownership check via `findByIdsForUser` ; mismatch → `ForbiddenException('trades.error.forbidden')`.
5. Collecte des `position_id` distincts.
6. **Transaction PDO** :
   ```php
   $pdo->beginTransaction();
   try {
       foreach ($positionIds as $pid) {
           $positionRepo->delete($pid);  // CASCADE → trades + partial_exits
       }
       $pdo->commit();
   } catch (\Throwable $e) {
       $pdo->rollBack();
       throw $e;
   }
   ```
7. Retourne `count($tradeIds)`.

`PDO` est injecté en paramètre **optionnel** sur le constructeur de `TradeService` (`?PDO $pdo = null`) pour ne pas casser les unit tests qui mockent les repos sans PDO. En production (`api/config/routes.php`), `$pdo` est toujours fourni.

### Endpoint

```
POST /trades/bulk-delete
Body: { "ids": [1, 2, 3, ...] }
Response 200: { success: true, data: { deleted_count: N, message_key: "trades.success.bulk_deleted" } }
Response 422: { success: false, error: { message_key: "trades.error.bulk_delete_empty" | "...too_many" } }
Response 403: { success: false, error: { message_key: "trades.error.forbidden" } }
```

Méthode HTTP : POST plutôt que DELETE car certains clients / proxies ne propagent pas le body sur DELETE. POST avec body JSON est universel.

Route protégée par `authMiddleware + requireSubscription` (cohérent avec le DELETE unitaire existant).

### i18n (clés ajoutées)

- `trades.error.bulk_delete_empty`
- `trades.error.bulk_delete_too_many`
- `trades.success.bulk_deleted` (avec interpolation `{count}`)
- `trades.date_range` (les sous-clés `from` / `to` du DateRangePicker viennent de `common.from` / `common.to`, déjà présentes)
- `trades.bulk.*` : `selected_count`, `delete_selection`, `confirm_title`, `confirm_intro`, `recap_count`, `count_value`, `recap_dates`, `recap_accounts`, `recap_total_pnl`, `confirm_warning`, `confirm_button`

Toutes alignées entre `fr.json` et `en.json`.

## Couverture des tests

| Test | Fichier | Scénario | Statut |
|------|---------|----------|--------|
| `testDeleteBulkSuccessDeletesPositionsInTransaction` | `tests/Unit/Services/TradeServiceTest.php` | Bulk de 3 IDs → beginTransaction + 3 deletes + commit, retourne 3 | ✅ |
| `testDeleteBulkEmptyIdsThrowsValidation` | idem | `[]` → ValidationException `bulk_delete_empty` | ✅ |
| `testDeleteBulkTooManyIdsThrowsValidation` | idem | 501 IDs → ValidationException `bulk_delete_too_many` | ✅ |
| `testDeleteBulkOwnershipMismatchThrowsForbidden` | idem | 3 IDs demandés, 2 trouvés → ForbiddenException, transaction jamais commencée | ✅ |
| `testDeleteBulkRollsBackOnException` | idem | 1ère delete OK, 2ème throws → rollBack + re-throw | ✅ |
| `testBulkDeleteTradesSuccess` | `tests/Integration/Trades/TradeFlowTest.php` | POST /trades/bulk-delete avec 3 IDs valides → 200, deleted_count=3, trades + positions disparus en DB | ✅ |
| `testBulkDeleteForbiddenWhenAnyIdBelongsToAnotherUser` | idem | Tentative cross-user → 403, aucun trade supprimé | ✅ |
| `testBulkDeleteEmptyIdsValidation` | idem | `{ ids: [] }` → 422 `bulk_delete_empty` | ✅ |
| `testListTradesFilterByDateRange` | idem | 3 trades à des dates différentes, filtre `?date_from=...&date_to=...` → seul le trade dans la plage | ✅ |

**Suite complète post-fix** : 1085/1085 PHPUnit + 212/212 Vitest.

## Limitations connues / Évolutions futures

- **Select-all = page courante uniquement** (pas tout le filtré). Décision MVP pour limiter le risque de suppression massive accidentelle. Une "select all filtered" + endpoint `bulk-delete-by-filter` est possible si le besoin remonte.
- **Plafond 500 IDs / appel** : borne anti-DoS et anti-doigt-glissant. Pour supprimer plus, plusieurs appels nécessaires.
- **Pas de soft-delete**. La suppression est irréversible (DELETE hard avec CASCADE). Cohérent avec la suppression unitaire existante.
- **Stats / dashboard** : recalculés au prochain refresh côté frontend (les agrégats de `StatsRepository` ignorent les trades disparus, pas de cache à invalider).
