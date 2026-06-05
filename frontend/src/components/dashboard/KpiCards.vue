<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Chart from 'primevue/chart'
import { useChartOptions } from '@/composables/useChartOptions'
import { CHART_PALETTE } from '@/constants/chartPalette'

// Overview tile, three stacked bands that share the height evenly: hero P&L +
// win rate on top, a centred win/loss doughnut in the middle, and the secondary
// metrics in a footer row. Sits in one column so cumulative P&L takes the rest.
const { t } = useI18n()
const { doughnutChartOptions } = useChartOptions()

// Legend off: the doughnut stays compact, slice values come up on hover.
const doughnutOptions = computed(() => ({
  ...doughnutChartOptions.value,
  plugins: { ...doughnutChartOptions.value.plugins, legend: { display: false } },
}))

const props = defineProps({
  overview: { type: Object, default: null },
  winLoss: { type: Object, default: null },
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

const winLossData = computed(() => {
  const d = props.winLoss
  if (!d || d.win + d.loss + d.be === 0) return null
  return {
    labels: [t('dashboard.wins'), t('dashboard.losses'), t('dashboard.breakeven')],
    datasets: [{
      data: [d.win, d.loss, d.be],
      backgroundColor: [CHART_PALETTE.positive, CHART_PALETTE.negative, CHART_PALETTE.warning],
      hoverBackgroundColor: [CHART_PALETTE.positiveLt, '#9c2d3a', '#a8741f'],
    }],
  }
})
</script>

<template>
  <div
    class="flex flex-col bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-5"
    data-testid="kpi-tile"
  >
    <!-- Top band: P&L + win rate stacked on the left, win/loss doughnut on the right -->
    <div class="grid flex-1 grid-cols-2 gap-4">
      <div class="flex flex-col justify-start" data-testid="kpi-pnl-hero">
        <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
          {{ t('dashboard.total_pnl') }}
        </div>
        <div
          class="font-mono tabular-nums font-bold text-3xl md:text-4xl mt-1"
          :class="pnlClass(overview?.total_pnl)"
        >
          {{ formatPnl(overview?.total_pnl) }}
        </div>
        <div v-if="overview?.best_trade != null || overview?.worst_trade != null" class="mt-2 flex items-center gap-4 text-xs">
          <span class="flex items-center gap-1 text-success font-mono tabular-nums">
            <i class="pi pi-arrow-up text-[10px]"></i>
            {{ formatPnl(overview?.best_trade) }}
          </span>
          <span class="flex items-center gap-1 text-danger font-mono tabular-nums">
            <i class="pi pi-arrow-down text-[10px]"></i>
            {{ formatPnl(overview?.worst_trade) }}
          </span>
        </div>

        <div class="mt-8">
          <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
            {{ t('dashboard.win_rate') }}
          </div>
          <div class="mt-1 text-2xl font-bold font-mono tabular-nums" :class="pnlClass(overview?.win_rate)">
            {{ formatPercent(overview?.win_rate) }}
          </div>
        </div>
      </div>

      <div class="flex items-center justify-center p-2">
        <div v-if="winLossData" class="aspect-square w-full max-w-[12rem]">
          <Chart type="doughnut" :data="winLossData" :options="doughnutOptions" class="h-full w-full" />
        </div>
        <p v-else class="text-gray-400 text-sm">{{ t('performance.no_data') }}</p>
      </div>
    </div>

    <!-- Footer row: secondary metrics, evenly split -->
    <div class="mt-5 grid grid-cols-3 divide-x divide-gray-200 border-t border-gray-200 pt-5 text-center dark:divide-gray-700 dark:border-gray-700">
      <div class="px-2">
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('dashboard.profit_factor') }}</div>
        <div class="mt-0.5 font-mono tabular-nums font-semibold">{{ formatRatio(overview?.profit_factor) }}</div>
      </div>
      <div class="px-2">
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('dashboard.avg_rr') }}</div>
        <div class="mt-0.5 font-mono tabular-nums font-semibold">{{ formatRatio(overview?.avg_rr) }}</div>
      </div>
      <div class="px-2">
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ t('dashboard.total_trades') }}</div>
        <div class="mt-0.5 font-mono tabular-nums font-semibold">{{ overview?.total_trades ?? 0 }}</div>
      </div>
    </div>
  </div>
</template>
