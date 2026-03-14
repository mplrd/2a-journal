<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'

const { t } = useI18n()

const props = defineProps({
  visible: { type: Boolean, required: true },
  dimension: { type: String, default: null },
  data: { type: Array, default: () => [] },
})

defineEmits(['update:visible'])

const title = computed(() => {
  const titles = {
    symbol: t('performance.by_symbol'),
    direction: t('performance.by_direction'),
    setup: t('performance.by_setup'),
    period: t('performance.by_period'),
    session: t('performance.by_session'),
    account: t('performance.by_account'),
    account_type: t('performance.by_account_type'),
  }
  return titles[props.dimension] || ''
})

const groupColumn = computed(() => {
  const map = {
    symbol: { field: 'symbol', header: t('dashboard.symbols') },
    direction: { field: 'direction', header: t('dashboard.direction') },
    setup: { field: 'setup', header: t('dashboard.setups') },
    period: { field: 'period', header: t('performance.by_period') },
    session: { field: 'session', header: t('performance.by_session') },
    account: { field: 'account_name', header: t('performance.by_account') },
    account_type: { field: 'account_type', header: t('performance.by_account_type') },
  }
  return map[props.dimension] || { field: '', header: '' }
})

const showFullColumns = computed(() => props.dimension !== 'period')

function formatPercent(value) {
  return value != null ? `${Number(value).toFixed(2)}%` : '-'
}

function formatPnl(value) {
  return value != null ? Number(value).toFixed(2) : '-'
}

function formatRatio(value) {
  return value != null ? Number(value).toFixed(2) : '-'
}

function pnlClass(value) {
  if (value > 0) return 'text-green-600 dark:text-green-400'
  if (value < 0) return 'text-red-600 dark:text-red-400'
  return ''
}
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="$emit('update:visible', $event)"
    :header="title"
    modal
    :style="{ width: '80vw', maxWidth: '900px' }"
    :dismissableMask="true"
  >
    <DataTable :value="data" stripedRows size="small" :emptyMessage="t('performance.no_data')">
      <Column :field="groupColumn.field" :header="groupColumn.header" />
      <Column field="total_trades" :header="t('performance.total_trades')" />
      <Column field="wins" :header="t('performance.wins')" />
      <Column field="losses" :header="t('performance.losses')" />
      <Column :header="t('performance.win_rate')">
        <template #body="{ data }">{{ formatPercent(data.win_rate) }}</template>
      </Column>
      <Column :header="t('performance.total_pnl')">
        <template #body="{ data }"><span :class="pnlClass(data.total_pnl)">{{ formatPnl(data.total_pnl) }}</span></template>
      </Column>
      <template v-if="showFullColumns">
        <Column :header="t('performance.avg_rr')">
          <template #body="{ data }">{{ formatRatio(data.avg_rr) }}</template>
        </Column>
        <Column :header="t('performance.profit_factor')">
          <template #body="{ data }">{{ formatRatio(data.profit_factor) }}</template>
        </Column>
      </template>
    </DataTable>
  </Dialog>
</template>
