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
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
    <KpiCard :label="t('dashboard.total_trades')">
      {{ overview?.total_trades ?? 0 }}
    </KpiCard>

    <KpiCard :label="t('dashboard.win_rate')" :valueClass="pnlClass(overview?.win_rate)">
      {{ formatPercent(overview?.win_rate) }}
    </KpiCard>

    <KpiCard :label="t('dashboard.total_pnl')" :valueClass="pnlClass(overview?.total_pnl)">
      {{ formatPnl(overview?.total_pnl) }}
    </KpiCard>

    <KpiCard :label="t('dashboard.profit_factor')">
      {{ formatRatio(overview?.profit_factor) }}
    </KpiCard>

    <KpiCard :label="t('dashboard.avg_rr')">
      {{ formatRatio(overview?.avg_rr) }}
    </KpiCard>

    <KpiCard :label="t('dashboard.best_worst')">
      <div class="flex flex-col">
        <span class="text-sm font-bold font-mono tabular-nums text-success">{{ formatPnl(overview?.best_trade) }}</span>
        <span class="text-sm font-bold font-mono tabular-nums text-danger">{{ formatPnl(overview?.worst_trade) }}</span>
      </div>
    </KpiCard>
  </div>
</template>
