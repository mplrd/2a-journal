<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePositionsStore } from '@/stores/positions'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import { Direction } from '@/constants/enums'

const { t } = useI18n()
const store = usePositionsStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()

function symbolName(code) {
  const s = symbolsStore.symbols.find((sym) => sym.code === code)
  return s ? s.name : code
}

function accountName(accountId) {
  const a = accountsStore.accounts.find((acc) => acc.id === accountId)
  return a ? a.name : '-'
}

const filterAccountId = ref(null)

onMounted(async () => {
  await Promise.all([accountsStore.fetchAccounts(), symbolsStore.fetchSymbols()])
  await store.fetchAggregated()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountId.value) filters.account_id = filterAccountId.value
  store.setFilters(filters)
  await store.fetchAggregated()
}

function directionSeverity(direction) {
  return direction === Direction.BUY ? 'success' : 'danger'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold">{{ t('positions.title') }}</h1>
    </div>

    <div class="flex gap-4 mb-4">
      <Select
        v-model="filterAccountId"
        :options="[{ label: t('positions.all_accounts'), value: null }, ...accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))]"
        optionLabel="label"
        optionValue="value"
        :placeholder="t('positions.filter_account')"
        class="w-48"
        @change="applyFilters"
      />
    </div>

    <p v-if="!store.loading && store.positions.length === 0" class="text-gray-500">
      {{ t('positions.empty') }}
    </p>

    <DataTable
      v-if="store.positions.length > 0"
      :value="store.positions"
      :loading="store.loading"
      stripedRows
      class="mt-2"
    >
      <Column field="account_id" :header="t('positions.account')">
        <template #body="{ data }">{{ accountName(data.account_id) }}</template>
      </Column>
      <Column field="symbol" :header="t('positions.symbol')">
        <template #body="{ data }">{{ symbolName(data.symbol) }}</template>
      </Column>
      <Column field="direction" :header="t('positions.direction')">
        <template #body="{ data }">
          <Tag :value="t(`positions.directions.${data.direction}`)" :severity="directionSeverity(data.direction)" />
        </template>
      </Column>
      <Column field="total_size" :header="t('positions.total_size')" />
      <Column field="pru" :header="t('positions.pru')">
        <template #body="{ data }">
          {{ Number(data.pru).toLocaleString() }}
        </template>
      </Column>
      <Column field="first_opened_at" :header="t('positions.first_opened_at')">
        <template #body="{ data }">
          {{ new Date(data.first_opened_at).toLocaleDateString() }}
        </template>
      </Column>
    </DataTable>
  </div>
</template>
