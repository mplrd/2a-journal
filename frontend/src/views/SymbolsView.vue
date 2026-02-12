<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useSymbolsStore } from '@/stores/symbols'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import SymbolForm from '@/components/symbol/SymbolForm.vue'
import { SymbolType } from '@/constants/enums'

const { t } = useI18n()
const toast = useToast()
const store = useSymbolsStore()

const showForm = ref(false)
const editingSymbol = ref(null)

onMounted(() => {
  store.fetchSymbols(true)
})

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
      await store.updateSymbol(editingSymbol.value.id, data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('symbols.success.updated'), life: 3000 })
    } else {
      await store.createSymbol(data)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('symbols.success.created'), life: 3000 })
    }
    showForm.value = false
  } catch {
    // error is set in the store
  }
}

async function handleDelete(symbol) {
  if (confirm(t('symbols.confirm_delete'))) {
    try {
      await store.deleteSymbol(symbol.id)
      toast.add({ severity: 'success', summary: t('common.success'), detail: t('symbols.success.deleted'), life: 3000 })
    } catch {
      // error is set in the store
    }
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
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('symbols.title') }}</h1>
      <Button :label="t('symbols.create')" icon="pi pi-plus" @click="openCreate" />
    </div>

    <p v-if="!store.loading && store.symbols.length === 0" class="text-gray-500">
      {{ t('symbols.empty') }}
    </p>

    <DataTable
      v-if="store.symbols.length > 0"
      :value="store.symbols"
      :loading="store.loading"
      stripedRows
      class="mt-2"
    >
      <Column field="code" :header="t('symbols.ticker')" />
      <Column field="name" :header="t('symbols.name')" />
      <Column field="type" :header="t('symbols.type')">
        <template #body="{ data }">
          <Tag :value="t(`symbols.types.${data.type}`)" :severity="typeSeverity(data.type)" />
        </template>
      </Column>
      <Column field="point_value" :header="t('symbols.point_value')">
        <template #body="{ data }">
          {{ Number(data.point_value) }}
        </template>
      </Column>
      <Column field="currency" :header="t('symbols.currency')" />
      <Column :header="''">
        <template #body="{ data }">
          <div class="flex gap-2">
            <Button icon="pi pi-pencil" severity="secondary" size="small" text v-tooltip.top="t('common.edit')" @click="openEdit(data)" />
            <Button icon="pi pi-trash" severity="danger" size="small" text v-tooltip.top="t('common.delete')" @click="handleDelete(data)" />
          </div>
        </template>
      </Column>
    </DataTable>

    <SymbolForm
      v-model:visible="showForm"
      :symbol="editingSymbol"
      :loading="store.loading"
      @save="handleSave"
    />
  </div>
</template>
