# Évolutions à prévoir

Liste des améliorations identifiées en cours de route mais sortant du scope d'une feature en cours. À planifier / prioriser quand les branches concernées seront mergées.

## UX / UI

### Remplacer les `window.confirm()` natifs restants par des Dialog PrimeVue

**Contexte** : l'app mélangeait des `window.confirm()` natifs (alertes OS, sans dark mode, non stylables) et des `<Dialog>` PrimeVue. Le cas sur **AssetsTab** (suppression d'un actif) a été corrigé directement sur `develop`. **6 occurrences restent** à migrer sur le même pattern.

**Occurrences restantes** (recensées le 2026-04-22) :

| Fichier | Ligne | i18n key utilisée | Action |
|---|---|---|---|
| `frontend/src/views/AccountsView.vue` | 90 | `accounts.confirm_delete` | Supprimer un compte |
| `frontend/src/views/OrdersView.vue` | 96 | `orders.confirm_cancel` | Annuler un ordre |
| `frontend/src/views/OrdersView.vue` | 107 | `orders.confirm_execute` | Exécuter un ordre manuellement |
| `frontend/src/views/OrdersView.vue` | 118 | `orders.confirm_delete` | Supprimer un ordre |
| `frontend/src/views/SymbolsView.vue` | 54 | `symbols.confirm_delete` | Supprimer un actif (legacy, doublonne AssetsTab) |
| `frontend/src/views/TradesView.vue` | 202 | `trades.confirm_delete` | Supprimer un trade |

**Problèmes** :
- Incohérence UX avec le reste de l'app (`DeleteAccountDialog`, `ChangePasswordDialog`, résiliation billing utilisent tous `<Dialog>` PrimeVue)
- Pas de dark mode, pas de contrôle visuel, pas de thématisation
- Bloque le thread JS (modale bloquante du navigateur)

**Pattern de référence** (voir `AssetsTab.vue` après le fix `chore/assets-confirm-dialog`) :
```vue
// script setup
const deleteDialogVisible = ref(false)
const itemToDelete = ref(null)
const deleting = ref(false)

function handleDelete(item) {
  itemToDelete.value = item
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  if (!itemToDelete.value) return
  deleting.value = true
  try {
    await store.delete(itemToDelete.value.id)
    toast.add({ ... })
    deleteDialogVisible.value = false
  } finally {
    deleting.value = false
  }
}

// template
<Dialog v-model:visible="deleteDialogVisible" :header="t('…confirm_delete_title')" :modal="true" :style="{ width: '420px' }">
  <p>{{ t('…confirm_delete_line', { ... }) }}</p>
  <template #footer>
    <Button :label="t('common.cancel')" severity="secondary" @click="deleteDialogVisible = false" />
    <Button :label="t('common.delete')" severity="danger" :loading="deleting" @click="confirmDelete" />
  </template>
</Dialog>
```

Pour chaque vue : ajouter deux nouvelles clés i18n `…confirm_delete_title` (ou `_cancel_title` / `_execute_title`) et `…confirm_delete_line` avec interpolation du contexte (nom du compte, ID de l'ordre, etc.) pour une meilleure UX qu'un message générique.

**Priorité** : basse (fonctionnel OK). À regrouper dans une branche dédiée `chore/replace-native-confirms`.

---

*À chaque nouvelle évolution repérée mais non traitée immédiatement : l'ajouter ici avec contexte + fichiers + à-faire + priorité.*
