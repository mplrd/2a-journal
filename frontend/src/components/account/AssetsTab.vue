<script setup>
import { onMounted, ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useSymbolsStore } from '@/stores/symbols'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolAccountSettingsStore } from '@/stores/symbolAccountSettings'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import SymbolForm from '@/components/symbol/SymbolForm.vue'
import { SymbolType } from '@/constants/enums'

const { t } = useI18n()
const toast = useToast()
const symbolsStore = useSymbolsStore()
const accountsStore = useAccountsStore()
const settingsStore = useSymbolAccountSettingsStore()

const showForm = ref(false)
const editingSymbol = ref(null)
const deleteDialogVisible = ref(false)
const symbolToDelete = ref(null)
const deleting = ref(false)

// Local drafts per cell for debouncing input until blur
const drafts = ref({})
const savingCells = ref(new Set())

onMounted(async () => {
  await symbolsStore.fetchSymbols(true)
  if (!accountsStore.accounts || accountsStore.accounts.length === 0) {
    await accountsStore.fetchAccounts()
  }
  await settingsStore.fetchMatrix(true)
})

const symbols = computed(() => symbolsStore.symbols ?? [])
const accounts = computed(() => accountsStore.accounts ?? [])
const hasSymbols = computed(() => symbols.value.length > 0)
const hasAccounts = computed(() => accounts.value.length > 0)

function openCreate() {
  editingSymbol.value = null
  showForm.value = true
}

function openEdit(symbol) {
  editingSymbol.value = symbol
  showForm.value = true
}

async function handleSave(data) {
  try {
    if (editingSymbol.value) {
      await symbolsStore.updateSymbol(editingSymbol.value.id, data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('symbols.success.updated'), life: 3000 })
    } else {
      await symbolsStore.createSymbol(data)
      // New symbol → auto-materialize settings for the new (symbol, account) pairs
      await settingsStore.fetchMatrix(true)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('symbols.success.created'), life: 3000 })
    }
    showForm.value = false
  } catch {
    // error is set in the store
  }
}

function handleDelete(symbol) {
  symbolToDelete.value = symbol
  deleteDialogVisible.value = true
}

async function confirmDelete() {
  if (!symbolToDelete.value) return
  deleting.value = true
  try {
    await symbolsStore.deleteSymbol(symbolToDelete.value.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('symbols.success.deleted'), life: 3000 })
    deleteDialogVisible.value = false
    symbolToDelete.value = null
  } catch {
    // error is set in the store
  } finally {
    deleting.value = false
  }
}

function typeSeverity(type) {
  const map = {
    [SymbolType.INDEX]: 'info',
    [SymbolType.FOREX]: 'success',
    [SymbolType.CRYPTO]: 'warn',
    [SymbolType.STOCK]: 'secondary',
    [SymbolType.COMMODITY]: 'contrast',
  }
  return map[type] || 'secondary'
}

// ── Per-(symbol, account) point value cells ───────────────────────────

function keyOf(sid, aid) {
  return `${sid}:${aid}`
}

function cellValue(sid, aid) {
  const k = keyOf(sid, aid)
  if (k in drafts.value) return drafts.value[k]
  return settingsStore.getPointValue(sid, aid)
}

function isSaving(sid, aid) {
  return savingCells.value.has(keyOf(sid, aid))
}

function onInput(sid, aid, value) {
  drafts.value[keyOf(sid, aid)] = value
}

async function persistCell(sid, aid) {
  const k = keyOf(sid, aid)
  if (!(k in drafts.value)) return

  const draft = drafts.value[k]
  const saved = settingsStore.getPointValue(sid, aid)

  if (draft === saved) {
    delete drafts.value[k]
    return
  }

  savingCells.value.add(k)
  try {
    if (draft === null || draft === '' || Number.isNaN(draft) || Number(draft) <= 0) {
      toast.add({ severity: 'error', summary: t('common.error'), detail: t('symbols.error.invalid_point_value'), life: 3500 })
      delete drafts.value[k]
      return
    }
    await settingsStore.save(sid, aid, Number(draft))
    delete drafts.value[k]
  } catch (err) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: t(err.messageKey || 'error.internal'), life: 4000 })
  } finally {
    savingCells.value.delete(k)
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ t('symbols.title') }}</h3>
      <Button :label="t('symbols.create')" icon="pi pi-plus" size="small" @click="openCreate" />
    </div>

    <p v-if="!symbolsStore.loading && !hasSymbols" class="text-gray-500">
      {{ t('symbols.empty') }}
    </p>

    <div v-if="hasSymbols" class="overflow-x-auto">
      <table class="min-w-full border border-gray-200 dark:border-gray-700 text-sm" data-testid="assets-matrix-table">
        <thead>
          <!-- Level 1: groups — ticker/name/type/actions are fixed, account columns grouped under "Valeur du point" -->
          <!-- Uniform bg on level 1 (matches other single-level tables). Visual hierarchy conveyed by
               typography: centered + semibold for the group cell, normal for sub-headers below. -->
          <tr class="bg-gray-50 dark:bg-gray-800">
            <th
              rowspan="2"
              class="p-2 text-left font-medium text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700"
            >
              {{ t('symbols.ticker') }}
            </th>
            <th
              rowspan="2"
              class="p-2 text-left font-medium text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700"
            >
              {{ t('symbols.name') }}
            </th>
            <th
              rowspan="2"
              class="p-2 text-left font-medium text-gray-700 dark:text-gray-300 border-b border-gray-200 dark:border-gray-700"
            >
              {{ t('symbols.type') }}
            </th>
            <th
              v-if="hasAccounts"
              :colspan="accounts.length"
              class="p-2 text-center font-semibold text-gray-700 dark:text-gray-300 border-l border-gray-200 dark:border-gray-700"
              data-testid="header-group-point-value"
            >
              {{ t('symbols.point_value') }}
            </th>
            <th
              rowspan="2"
              class="p-2 border-b border-gray-200 dark:border-gray-700"
            ></th>
          </tr>
          <!-- Level 2: account names with currency. Slightly lighter shade to hint at sub-header. -->
          <tr class="bg-gray-100/60 dark:bg-gray-800/60">
            <th
              v-for="(account, idx) in accounts"
              :key="account.id"
              class="p-2 text-left font-normal text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700 min-w-[140px]"
              :class="{ 'border-l': idx === 0 }"
              :data-testid="`col-account-${account.id}`"
            >
              {{ account.name }}
              <span class="text-xs text-gray-500 dark:text-gray-400">({{ account.currency }})</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="symbol in symbols" :key="symbol.id" class="border-b border-gray-100 dark:border-gray-800">
            <td class="p-2 font-medium text-gray-800 dark:text-gray-200">{{ symbol.code }}</td>
            <td class="p-2 text-gray-700 dark:text-gray-300">{{ symbol.name }}</td>
            <td class="p-2">
              <Tag :value="t(`symbols.types.${symbol.type}`)" :severity="typeSeverity(symbol.type)" />
            </td>
            <td
              v-for="(account, idx) in accounts"
              :key="account.id"
              class="p-2"
              :class="{ 'border-l border-gray-200 dark:border-gray-700': idx === 0 }"
              :data-testid="`cell-${symbol.id}-${account.id}`"
            >
              <InputNumber
                :modelValue="cellValue(symbol.id, account.id)"
                :min="0"
                :maxFractionDigits="5"
                mode="decimal"
                locale="en-US"
                :disabled="isSaving(symbol.id, account.id)"
                :data-testid="`input-${symbol.id}-${account.id}`"
                class="w-full max-w-[120px]"
                @update:modelValue="onInput(symbol.id, account.id, $event)"
                @blur="persistCell(symbol.id, account.id)"
              />
            </td>
            <td class="p-2">
              <div class="flex gap-2">
                <Button icon="pi pi-pencil" severity="secondary" size="small" text v-tooltip.top="t('common.edit')" @click="openEdit(symbol)" />
                <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="handleDelete(symbol)" />
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <p v-if="!hasAccounts" class="text-xs text-gray-500 mt-2">
        {{ t('symbols.sizes_matrix_no_accounts') }}
      </p>
    </div>

    <SymbolForm
      v-model:visible="showForm"
      :symbol="editingSymbol"
      :loading="symbolsStore.loading"
      @save="handleSave"
    />

    <Dialog
      v-model:visible="deleteDialogVisible"
      :header="t('symbols.confirm_delete_title')"
      :modal="true"
      :closable="true"
      :style="{ width: '420px' }"
    >
      <p class="text-gray-700 dark:text-gray-300">
        {{ t('symbols.confirm_delete_line', { code: symbolToDelete?.code }) }}
      </p>
      <template #footer>
        <Button :label="t('common.cancel')" severity="secondary" @click="deleteDialogVisible = false" />
        <Button
          :label="t('common.delete')"
          severity="danger"
          :loading="deleting"
          data-testid="confirm-delete-symbol"
          @click="confirmDelete"
        />
      </template>
    </Dialog>
  </div>
</template>
