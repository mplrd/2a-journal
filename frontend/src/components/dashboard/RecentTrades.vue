<script setup>
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import { Direction } from '@/constants/enums'

const { t } = useI18n()
const router = useRouter()

defineProps({
  trades: {
    type: Array,
    default: () => [],
  },
})

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
  return Number(pnl) >= 0 ? 'text-green-600 font-medium' : 'text-red-600 font-medium'
}

function formatPnl(pnl) {
  if (pnl == null) return '-'
  const num = Number(pnl)
  return (num >= 0 ? '+' : '') + num.toFixed(2)
}
</script>

<template>
  <div class="bg-white rounded-lg border border-gray-200 p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-medium text-gray-500">{{ t('dashboard.recent_trades') }}</h3>
      <Button
        :label="t('dashboard.view_all')"
        icon="pi pi-arrow-right"
        iconPos="right"
        size="small"
        text
        @click="router.push('/trades')"
      />
    </div>

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
  </div>
</template>
