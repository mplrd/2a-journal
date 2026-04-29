<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePositionsStore } from '@/stores/positions'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useAuthStore } from '@/stores/auth'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import { Direction } from '@/constants/enums'
import { formatSize } from '@/utils/format'
import EmptyState from '@/components/common/EmptyState.vue'

const { t } = useI18n()
const store = usePositionsStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()
const authStore = useAuthStore()

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
  store.perPage = Number(authStore.user?.default_page_size) || 10
  await Promise.all([accountsStore.fetchAccounts(), symbolsStore.fetchSymbols()])
  await store.fetchAggregated()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountId.value) filters.account_id = filterAccountId.value
  store.setFilters(filters)
  store.page = 1
  await store.fetchAggregated()
}

function onPage(event) {
  store.page = event.page + 1
  store.perPage = event.rows
  store.fetchAggregated()
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
      <div>
        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ t('positions.account') }}</label>
        <Select
          v-model="filterAccountId"
          :options="[{ label: t('positions.all_accounts'), value: null }, ...accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))]"
          optionLabel="label"
          optionValue="value"
          class="w-56"
          @change="applyFilters"
        />
      </div>
    </div>

    <EmptyState
      v-if="!store.loading && store.totalRecords === 0"
      icon="pi pi-chart-line"
      :title="t('positions.empty_title')"
      :description="t('positions.empty')"
    />

    <DataTable
      v-else
      :value="store.positions"
      :loading="store.loading"
      lazy
      paginator
      :rows="store.perPage"
      :totalRecords="store.totalRecords"
      :first="(store.page - 1) * store.perPage"
      :rowsPerPageOptions="[10, 25, 50]"
      @page="onPage"
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
      <Column field="total_size" :header="t('positions.total_size')">
        <template #body="{ data }">
          <span class="font-mono tabular-nums">{{ formatSize(data.total_size) }}</span>
        </template>
      </Column>
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
