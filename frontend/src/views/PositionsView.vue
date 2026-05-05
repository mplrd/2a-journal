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
import { Direction } from '@/constants/enums'
import { formatSize } from '@/utils/format'
import EmptyState from '@/components/common/EmptyState.vue'
import BadgeFilter from '@/components/common/BadgeFilter.vue'
import CollapsibleFilters from '@/components/common/CollapsibleFilters.vue'
import TileList from '@/components/common/TileList.vue'
import { useLayout } from '@/composables/useIsMobile'

const { t } = useI18n()
const { isMobile, isCompact } = useLayout()
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

const filterAccountIds = ref([])

onMounted(async () => {
  store.perPage = Number(authStore.user?.default_page_size) || 10
  await Promise.all([accountsStore.fetchAccounts(), symbolsStore.fetchSymbols()])
  await store.fetchAggregated()
})

async function applyFilters() {
  const filters = {}
  if (filterAccountIds.value.length > 0) filters.account_ids = filterAccountIds.value
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
function directionIcon(direction) {
  return direction === Direction.BUY ? 'pi pi-arrow-up' : 'pi pi-arrow-down'
}
function directionIconClass(direction) {
  return direction === Direction.BUY ? 'text-success' : 'text-danger'
}
</script>

<template>
  <div>
    <!-- Desktop / compact filter bar: account filter on a single line. -->
    <div v-if="!isMobile" class="flex items-end gap-6 flex-wrap mb-4">
      <div class="flex flex-col gap-1">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('positions.account') }}</span>
        <BadgeFilter
          v-model="filterAccountIds"
          :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
          multi
          @change="applyFilters"
        />
      </div>
    </div>

    <!-- Mobile filter bar: collapsed by default. -->
    <CollapsibleFilters v-else storage-key="positions-filters-expanded" class="mb-4">
      <div class="flex flex-col gap-3">
        <div class="flex flex-col gap-1">
          <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ t('positions.account') }}</span>
          <BadgeFilter
            v-model="filterAccountIds"
            :options="accountsStore.accounts.map((a) => ({ label: a.name, value: a.id }))"
            multi
            @change="applyFilters"
          />
        </div>
      </div>
    </CollapsibleFilters>

    <EmptyState
      v-if="!store.loading && store.totalRecords === 0"
      icon="pi pi-chart-line"
      :title="t('positions.empty_title')"
      :description="t('positions.empty')"
    />

    <DataTable
      v-else-if="!isMobile"
      :value="store.positions"
      :loading="store.loading"
      :size="isCompact ? 'small' : undefined"
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
      <Column v-if="!isCompact" field="account_id" :header="t('positions.account')">
        <template #body="{ data }">{{ accountName(data.account_id) }}</template>
      </Column>
      <Column field="symbol" :header="isCompact ? '' : t('positions.symbol')">
        <template #body="{ data }">
          <!-- Compact: direction picto on the left, [symbol / account] stack
               on the right; same convention as Orders / Trades. -->
          <div v-if="isCompact" class="inline-flex items-center gap-1.5">
            <i :class="[directionIcon(data.direction), directionIconClass(data.direction), 'text-xs']" v-tooltip.top="t(`positions.directions.${data.direction}`)"></i>
            <div class="flex flex-col gap-0.5">
              <span>{{ symbolName(data.symbol) }}</span>
              <span class="inline-flex items-center self-start px-1.5 py-0.5 rounded text-[10px] leading-none bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                {{ accountName(data.account_id) }}
              </span>
            </div>
          </div>
          <span v-else>{{ symbolName(data.symbol) }}</span>
        </template>
      </Column>
      <Column v-if="!isCompact" field="direction" :header="t('positions.direction')">
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
      <Column v-if="!isCompact" field="first_opened_at" :header="t('positions.first_opened_at')">
        <template #body="{ data }">
          {{ new Date(data.first_opened_at).toLocaleDateString() }}
        </template>
      </Column>
    </DataTable>

    <!-- Mobile: tile list mirroring the DataTable columns. Read-only view —
         no action buttons / no more menu. -->
    <TileList
      v-else-if="isMobile && store.totalRecords > 0"
      :items="store.positions"
      :loading="store.loading"
      :total-records="store.totalRecords"
      :page="store.page"
      :per-page="store.perPage"
      class="mt-2"
      @page="onPage"
    >
      <template #default="{ item }">
        <div class="p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800" :data-testid="`position-tile-${item.symbol}-${item.account_id}`">
          <div class="flex items-center gap-1.5 mb-1">
            <i :class="[directionIcon(item.direction), directionIconClass(item.direction)]" v-tooltip.top="t(`positions.directions.${item.direction}`)"></i>
            <span class="font-semibold truncate">{{ symbolName(item.symbol) }}</span>
          </div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">
            {{ accountName(item.account_id) }} · {{ new Date(item.first_opened_at).toLocaleDateString() }}
          </div>
          <div class="grid grid-cols-2 gap-x-3 text-sm">
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('positions.total_size') }}</div>
              <div class="font-mono tabular-nums">{{ formatSize(item.total_size) }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('positions.pru') }}</div>
              <div class="font-mono tabular-nums">{{ Number(item.pru).toLocaleString() }}</div>
            </div>
          </div>
        </div>
      </template>
    </TileList>
  </div>
</template>
