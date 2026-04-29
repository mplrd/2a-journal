<script setup>
import { useI18n } from 'vue-i18n'
import KpiCard from './KpiCard.vue'

const { t } = useI18n()

const props = defineProps({
  overview: { type: Object, default: null },
})

function formatPnl(value) {
  if (value == null) return '-'
  const num = Number(value)
  return (num >= 0 ? '+' : '') + num.toFixed(2)
}

function formatPercent(value) {
  if (value == null) return '-'
  return Number(value).toFixed(2) + '%'
}

function formatRatio(value) {
  if (value == null) return '-'
  return Number(value).toFixed(2)
}

function pnlClass(value) {
  if (value == null) return 'text-gray-500'
  return Number(value) >= 0 ? 'text-success' : 'text-danger'
}
</script>

<template>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
    <!-- Hero: P&L total — dominates visually as the journal's headline metric -->
    <div
      class="col-span-2 lg:col-span-2 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6"
      data-testid="kpi-pnl-hero"
    >
      <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
        {{ t('dashboard.total_pnl') }}
      </div>
      <div
        class="font-mono tabular-nums font-bold text-3xl md:text-4xl mt-2"
        :class="pnlClass(overview?.total_pnl)"
      >
        {{ formatPnl(overview?.total_pnl) }}
      </div>
      <div v-if="overview?.best_trade != null || overview?.worst_trade != null" class="mt-3 flex items-center gap-4 text-xs">
        <span class="flex items-center gap-1 text-success font-mono tabular-nums">
          <i class="pi pi-arrow-up text-[10px]"></i>
          {{ formatPnl(overview?.best_trade) }}
        </span>
        <span class="flex items-center gap-1 text-danger font-mono tabular-nums">
          <i class="pi pi-arrow-down text-[10px]"></i>
          {{ formatPnl(overview?.worst_trade) }}
        </span>
      </div>
    </div>

    <KpiCard :label="t('dashboard.win_rate')" :valueClass="pnlClass(overview?.win_rate)">
      {{ formatPercent(overview?.win_rate) }}
    </KpiCard>

    <KpiCard :label="t('dashboard.profit_factor')">
      {{ formatRatio(overview?.profit_factor) }}
    </KpiCard>

    <KpiCard :label="t('dashboard.avg_rr')">
      {{ formatRatio(overview?.avg_rr) }}
    </KpiCard>

    <KpiCard :label="t('dashboard.total_trades')">
      {{ overview?.total_trades ?? 0 }}
    </KpiCard>
  </div>
</template>
