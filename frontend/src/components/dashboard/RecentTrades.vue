<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import { Direction } from '@/constants/enums'

const { t } = useI18n()
const router = useRouter()

defineProps({
  trades: { type: Array, default: () => [] },
  openTrades: { type: Array, default: () => [] },
})

const activeTab = ref('open')

function directionSeverity(direction) {
  return direction === Direction.BUY ? 'success' : 'danger'
}

function exitTypeSeverity(exitType) {
  switch (exitType) {
    case 'TP':
      return 'success'
    case 'SL':
      return 'danger'
    case 'BE':
      return 'warn'
    default:
      return 'info'
  }
}

function pnlClass(pnl) {
  if (pnl == null) return ''
  return Number(pnl) >= 0 ? 'text-success font-medium font-mono tabular-nums' : 'text-danger font-medium font-mono tabular-nums'
}

function formatPnl(pnl) {
  if (pnl == null) return '-'
  const num = Number(pnl)
  return (num >= 0 ? '+' : '') + num.toFixed(2)
}

function viewAll() {
  // From the "open trades" tab, we want the destination view to pre-filter on
  // ongoing trades (OPEN + SECURED). From "recent closed", no filter needed.
  if (activeTab.value === 'open') {
    router.push({ path: '/trades', query: { statuses: 'OPEN,SECURED' } })
  } else {
    router.push('/trades')
  }
}
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 h-full">
    <Tabs v-model:value="activeTab">
      <div class="flex items-center justify-between mb-3">
        <TabList>
          <Tab value="open">{{ t('dashboard.open_trades') }}</Tab>
          <Tab value="recent">{{ t('dashboard.recent_trades') }}</Tab>
        </TabList>
        <Button
          :label="t('dashboard.view_all')"
          icon="pi pi-arrow-right"
          iconPos="right"
          size="small"
          text
          @click="viewAll"
        />
      </div>

      <TabPanels>
        <!-- Open trades -->
        <TabPanel value="open">
          <DataTable v-if="openTrades.length > 0" :value="openTrades" stripedRows size="small">
            <Column field="symbol" :header="t('positions.symbol')" />
            <Column field="direction" :header="t('positions.direction')">
              <template #body="{ data }">
                <Tag :value="t(`positions.directions.${data.direction}`)" :severity="directionSeverity(data.direction)" />
              </template>
            </Column>
            <Column field="entry_price" :header="t('positions.entry_price')">
              <template #body="{ data }">
                {{ Number(data.entry_price).toFixed(2) }}
              </template>
            </Column>
            <Column field="size" :header="t('positions.size')">
              <template #body="{ data }">
                {{ Number(data.size) }}
              </template>
            </Column>
            <Column field="opened_at" :header="t('trades.opened_at')">
              <template #body="{ data }">
                {{ new Date(data.opened_at).toLocaleString() }}
              </template>
            </Column>
          </DataTable>
          <p v-else class="text-gray-400 text-sm py-4 text-center">{{ t('dashboard.no_open_trades') }}</p>
        </TabPanel>

        <!-- Recent closed trades -->
        <TabPanel value="recent">
          <DataTable v-if="trades.length > 0" :value="trades" stripedRows size="small">
            <Column field="symbol" :header="t('positions.symbol')" />
            <Column field="direction" :header="t('positions.direction')">
              <template #body="{ data }">
                <Tag :value="t(`positions.directions.${data.direction}`)" :severity="directionSeverity(data.direction)" />
              </template>
            </Column>
            <Column field="pnl" :header="t('trades.pnl')">
              <template #body="{ data }">
                <span :class="pnlClass(data.pnl)">{{ formatPnl(data.pnl) }}</span>
              </template>
            </Column>
            <Column field="exit_type" :header="t('trades.exit_type')">
              <template #body="{ data }">
                <Tag v-if="data.exit_type" :value="t(`trades.exit_types.${data.exit_type}`)" :severity="exitTypeSeverity(data.exit_type)" />
              </template>
            </Column>
            <Column field="closed_at" :header="t('trades.closed_at')">
              <template #body="{ data }">
                {{ new Date(data.closed_at).toLocaleString() }}
              </template>
            </Column>
          </DataTable>
          <p v-else class="text-gray-400 text-sm py-4 text-center">{{ t('dashboard.no_recent_trades') }}</p>
        </TabPanel>
      </TabPanels>
    </Tabs>
  </div>
</template>
