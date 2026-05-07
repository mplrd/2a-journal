<script setup>
import { onMounted, ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStatsStore } from '@/stores/stats'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
import { useChartOptions } from '@/composables/useChartOptions'
import { CHART_PALETTE, primaryFor, withAlpha } from '@/constants/chartPalette'
import Select from 'primevue/select'
import Button from 'primevue/button'
import DashboardFilters from '@/components/dashboard/DashboardFilters.vue'
import ChartCard from '@/components/performance/ChartCard.vue'
import StatsDetailDialog from '@/components/performance/StatsDetailDialog.vue'
import SetupCombinationDialog from '@/components/performance/SetupCombinationDialog.vue'
import RrDistributionChart from '@/components/performance/RrDistributionChart.vue'
import EquityCurveChart from '@/components/performance/EquityCurveChart.vue'
import HeatmapChart from '@/components/performance/HeatmapChart.vue'

const { t } = useI18n()
const statsStore = useStatsStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()
const setupsStore = useSetupsStore()
const { barChartOptions, lineChartOptions, doughnutChartOptions, dualAxisChartOptions, isDark } = useChartOptions()

// Period axis: linear = absolute date buckets ("2026-01"), cyclic = collapses
// the year so seasonality stands out ("Monday", "January", etc.).
const periodAxisMode = ref('linear')
const periodGroup = ref('month')

const periodAxisOptions = computed(() => [
  { label: t('performance.period_axis_linear'), value: 'linear' },
  { label: t('performance.period_axis_cyclic'), value: 'cyclic' },
])

const periodGroupOptions = computed(() => {
  if (periodAxisMode.value === 'cyclic') {
    return [
      { label: t('performance.period_day_of_week'), value: 'day_of_week' },
      { label: t('performance.period_iso_week'), value: 'iso_week' },
      { label: t('performance.period_month_of_year'), value: 'month_of_year' },
    ]
  }
  return [
    { label: t('performance.period_day'), value: 'day' },
    { label: t('performance.period_week'), value: 'week' },
    { label: t('performance.period_month'), value: 'month' },
    { label: t('performance.period_year'), value: 'year' },
  ]
})

function formatPeriodLabel(periodValue) {
  if (periodGroup.value === 'day_of_week') {
    return t(`performance.weekdays.${periodValue}`)
  }
  if (periodGroup.value === 'month_of_year') {
    return t(`performance.months.${periodValue}`)
  }
  if (periodGroup.value === 'iso_week') {
    return t('performance.iso_week_short', { n: periodValue })
  }
  return periodValue
}

// Dialog state
const dialogVisible = ref(false)
const dialogDimension = ref(null)
const setupComboDialogVisible = ref(false)

function openDetail(dimension) {
  dialogDimension.value = dimension
  dialogVisible.value = true
}

const dialogData = computed(() => {
  // The setup chart and the timeframe chart share the same underlying source
  // (statsStore.bySetup) but split it by category for display. The detail
  // dialog respects that split.
  const setups = statsStore.bySetup || []
  const map = {
    symbol: statsStore.bySymbol,
    direction: statsStore.byDirection,
    setup: setups.filter((d) => d.category !== 'timeframe'),
    setup_timeframe: setups.filter((d) => d.category === 'timeframe'),
    period: statsStore.byPeriod,
    session: statsStore.bySession,
    account_type: statsStore.byAccountType,
  }
  return map[dialogDimension.value] || []
})

// Equity curve: initial capital aggregated from the selected accounts.
// Backwards compat with the legacy single account_id filter.
const initialCapital = computed(() => {
  const ids = statsStore.filters?.account_ids
  if (Array.isArray(ids) && ids.length > 0) {
    return accountsStore.accounts
      .filter((a) => ids.includes(a.id))
      .reduce((sum, a) => sum + Number(a.initial_capital || 0), 0)
  }
  const accountId = statsStore.filters?.account_id
  if (accountId) {
    const account = accountsStore.accounts.find((a) => a.id === accountId)
    return account ? Number(account.initial_capital || 0) : 0
  }
  return accountsStore.accounts.reduce((sum, a) => sum + Number(a.initial_capital || 0), 0)
})

// ── Data fetching ────────────────────────────────────────

async function fetchAll() {
  // The "by account type" widget was removed from the layout; the store
  // action and endpoint are kept for future configurable widgets, but
  // we drop the fetch here to avoid loading data that's not displayed.
  statsStore.dimensionsLoading = true
  try {
    await Promise.all([
      statsStore.fetchCharts(),
      statsStore.fetchBySymbol(),
      statsStore.fetchByDirection(),
      statsStore.fetchBySetup(),
      statsStore.fetchByPeriod(periodGroup.value),
      statsStore.fetchBySession(),
      statsStore.fetchRrDistribution(),
      statsStore.fetchHeatmap(),
    ])
  } finally {
    statsStore.dimensionsLoading = false
  }
}

onMounted(async () => {
  statsStore.setFilters({})
  await Promise.all([
    accountsStore.fetchAccounts(),
    symbolsStore.fetchSymbols(),
    setupsStore.fetchSetups(),
  ])
  fetchAll()
})

async function onApplyFilters(filters) {
  statsStore.setFilters(filters)
  fetchAll()
}

async function onResetFilters() {
  statsStore.setFilters({})
  fetchAll()
}

async function onPeriodGroupChange() {
  await statsStore.fetchByPeriod(periodGroup.value)
}

function onPeriodAxisModeChange() {
  // Seed a sensible default for the new axis: "month" linear or "month_of_year"
  // cyclic. Then refetch with the new group value.
  periodGroup.value = periodAxisMode.value === 'cyclic' ? 'month_of_year' : 'month'
  onPeriodGroupChange()
}

// ── Chart data helpers ───────────────────────────────────

function pnlBarData(items, labelField) {
  if (!items || items.length === 0) return null
  return {
    labels: items.map((d) => d[labelField]),
    datasets: [{
      label: t('performance.total_pnl'),
      data: items.map((d) => Number(d.total_pnl)),
      backgroundColor: items.map((d) => (Number(d.total_pnl) >= 0 ? CHART_PALETTE.positive : CHART_PALETTE.negative)),
      borderRadius: 4,
    }],
  }
}

function dualMetricData(items, labelField, labelTranslator = null) {
  if (!items || items.length === 0) return null
  const labels = labelTranslator
    ? items.map((d) => labelTranslator(d[labelField]))
    : items.map((d) => d[labelField])
  return {
    labels,
    datasets: [
      {
        label: t('performance.win_rate'),
        data: items.map((d) => Number(d.win_rate)),
        backgroundColor: CHART_PALETTE.primary,
        borderRadius: 4,
        yAxisID: 'y',
      },
      {
        label: t('performance.avg_rr'),
        data: items.map((d) => (d.avg_rr == null ? null : Number(d.avg_rr))),
        backgroundColor: CHART_PALETTE.positiveLt,
        borderRadius: 4,
        yAxisID: 'y1',
      },
    ],
  }
}

// ── Chart data ───────────────────────────────────────────

const pnlBySymbolChartData = computed(() => pnlBarData(statsStore.bySymbol, 'symbol'))
const pnlByPeriodChartData = computed(() => {
  const items = statsStore.byPeriod
  if (!items || items.length === 0) return null
  return {
    labels: items.map((d) => formatPeriodLabel(d.period)),
    datasets: [{
      label: t('performance.total_pnl'),
      data: items.map((d) => Number(d.total_pnl)),
      backgroundColor: items.map((d) => (Number(d.total_pnl) >= 0 ? CHART_PALETTE.positive : CHART_PALETTE.negative)),
      borderRadius: 4,
    }],
  }
})

const perfBySymbolChartData = computed(() => dualMetricData(statsStore.bySymbol, 'symbol'))
// "WR/RR par setup" excludes timeframe-categorized setups, which now have
// their own dedicated widget. NULL category and other categories stay here.
const perfBySetupChartData = computed(() => {
  const items = (statsStore.bySetup || []).filter((d) => d.category !== 'timeframe')
  return dualMetricData(items, 'setup')
})
const perfByTimeframeChartData = computed(() => {
  const items = (statsStore.bySetup || []).filter((d) => d.category === 'timeframe')
  return dualMetricData(items, 'setup')
})
const perfBySessionChartData = computed(() =>
  dualMetricData(statsStore.bySession, 'session', (v) => t(`performance.sessions.${v}`)),
)

const cumulativePnlChartData = computed(() => {
  const data = statsStore.charts?.cumulative_pnl
  if (!data || data.length === 0) return null
  return {
    labels: data.map((d) => {
      const date = new Date(d.closed_at)
      return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    }),
    datasets: [{
      label: t('dashboard.cumulative_pnl'),
      data: data.map((d) => d.cumulative_pnl),
      fill: true,
      borderColor: primaryFor(isDark.value),
      backgroundColor: withAlpha(primaryFor(isDark.value), 0.1),
      tension: 0.3,
      pointRadius: 3,
      pointHoverRadius: 6,
    }],
  }
})

const winLossChartData = computed(() => {
  const data = statsStore.charts?.win_loss
  if (!data) return null
  return {
    labels: [t('dashboard.wins'), t('dashboard.losses'), t('dashboard.breakeven')],
    datasets: [{
      data: [data.win, data.loss, data.be],
      backgroundColor: [CHART_PALETTE.positive, CHART_PALETTE.negative, CHART_PALETTE.warning],
    }],
  }
})
</script>

<template>
  <div>
    <DashboardFilters @apply="onApplyFilters" @reset="onResetFilters" />

    <div class="relative">
      <div v-if="statsStore.dimensionsLoading" class="absolute inset-0 bg-white/60 dark:bg-gray-900/60 z-10 flex items-center justify-center rounded-lg">
        <i class="pi pi-spin pi-spinner text-3xl text-gray-400"></i>
      </div>

      <!-- Row 1: Cumulative P&L + Equity Curve -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <ChartCard
          :title="t('dashboard.cumulative_pnl')"
          type="line"
          :data="cumulativePnlChartData"
          :options="lineChartOptions"
        />
        <EquityCurveChart
          :cumulativePnl="statsStore.charts?.cumulative_pnl"
          :initialCapital="initialCapital"
        />
      </div>

      <!-- Row 2: Win/Loss + R:R Distribution -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <ChartCard
          :title="t('dashboard.win_loss_distribution')"
          type="doughnut"
          :data="winLossChartData"
          :options="doughnutChartOptions"
          detailable
          @detail="openDetail('direction')"
        />
        <RrDistributionChart :data="statsStore.rrDistribution" />
      </div>

      <!-- Row 3: P&L by Symbol + P&L by Period (with linear/cyclic toggle) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <ChartCard
          :title="t('dashboard.pnl_by_symbol')"
          type="bar"
          :data="pnlBySymbolChartData"
          :options="barChartOptions"
          detailable
          @detail="openDetail('symbol')"
        />
        <ChartCard
          :title="t('performance.pnl_by_period')"
          type="bar"
          :data="pnlByPeriodChartData"
          :options="barChartOptions"
          detailable
          @detail="openDetail('period')"
        >
          <template #header-actions>
            <div class="flex gap-2">
              <Select
                v-model="periodAxisMode"
                :options="periodAxisOptions"
                optionLabel="label"
                optionValue="value"
                class="w-32"
                @change="onPeriodAxisModeChange"
              />
              <Select
                v-model="periodGroup"
                :options="periodGroupOptions"
                optionLabel="label"
                optionValue="value"
                class="w-36"
                @change="onPeriodGroupChange"
              />
            </div>
          </template>
        </ChartCard>
      </div>

      <!-- Row 4: WR/RR by Symbol + WR/RR by Setup (excludes timeframe) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <ChartCard
          :title="t('performance.perf_by_symbol')"
          type="bar"
          :data="perfBySymbolChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('symbol')"
        />
        <ChartCard
          :title="t('performance.perf_by_setup')"
          type="bar"
          :data="perfBySetupChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('setup')"
        >
          <template #header-actions>
            <Button
              :label="t('performance.go_further')"
              icon="pi pi-sliders-h"
              severity="secondary"
              text
              size="small"
              @click="setupComboDialogVisible = true"
            />
          </template>
        </ChartCard>
      </div>

      <!-- Row 5: WR/RR by Timeframe + WR/RR by Session -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <ChartCard
          :title="t('performance.perf_by_timeframe')"
          type="bar"
          :data="perfByTimeframeChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('setup_timeframe')"
        />
        <ChartCard
          :title="t('performance.perf_by_session')"
          type="bar"
          :data="perfBySessionChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('session')"
        />
      </div>

      <!-- Row 6: Heatmap (full width) -->
      <div class="mb-6">
        <HeatmapChart :data="statsStore.heatmap" />
      </div>
    </div>

    <StatsDetailDialog
      v-model:visible="dialogVisible"
      :dimension="dialogDimension"
      :data="dialogData"
    />
    <SetupCombinationDialog v-model:visible="setupComboDialogVisible" />
  </div>
</template>
