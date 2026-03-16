<script setup>
import { onMounted, ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useStatsStore } from '@/stores/stats'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
import { useChartOptions } from '@/composables/useChartOptions'
import Select from 'primevue/select'
import DashboardFilters from '@/components/dashboard/DashboardFilters.vue'
import ChartCard from '@/components/performance/ChartCard.vue'
import StatsDetailDialog from '@/components/performance/StatsDetailDialog.vue'
import RrDistributionChart from '@/components/performance/RrDistributionChart.vue'
import EquityCurveChart from '@/components/performance/EquityCurveChart.vue'
import HeatmapChart from '@/components/performance/HeatmapChart.vue'

const { t } = useI18n()
const statsStore = useStatsStore()
const accountsStore = useAccountsStore()
const symbolsStore = useSymbolsStore()
const setupsStore = useSetupsStore()
const { barChartOptions, lineChartOptions, doughnutChartOptions, dualAxisChartOptions } = useChartOptions()

const periodGroup = ref('month')
const periodGroupOptions = [
  { label: t('performance.period_day'), value: 'day' },
  { label: t('performance.period_week'), value: 'week' },
  { label: t('performance.period_month'), value: 'month' },
  { label: t('performance.period_year'), value: 'year' },
]

// Dialog state
const dialogVisible = ref(false)
const dialogDimension = ref(null)

function openDetail(dimension) {
  dialogDimension.value = dimension
  dialogVisible.value = true
}

const dialogData = computed(() => {
  const map = {
    symbol: statsStore.bySymbol,
    direction: statsStore.byDirection,
    setup: statsStore.bySetup,
    period: statsStore.byPeriod,
    session: statsStore.bySession,
    account_type: statsStore.byAccountType,
  }
  return map[dialogDimension.value] || []
})

// Equity curve: initial capital from selected account (or sum of all)
const initialCapital = computed(() => {
  const accountId = statsStore.filters?.account_id
  if (accountId) {
    const account = accountsStore.accounts.find((a) => a.id === accountId)
    return account ? Number(account.initial_capital || 0) : 0
  }
  return accountsStore.accounts.reduce((sum, a) => sum + Number(a.initial_capital || 0), 0)
})

// ── Data fetching ────────────────────────────────────────

async function fetchAll() {
  statsStore.dimensionsLoading = true
  try {
    await Promise.all([
      statsStore.fetchCharts(),
      statsStore.fetchBySymbol(),
      statsStore.fetchByDirection(),
      statsStore.fetchBySetup(),
      statsStore.fetchByPeriod(periodGroup.value),
      statsStore.fetchBySession(),
      statsStore.fetchByAccountType(),
      statsStore.fetchRrDistribution(),
      statsStore.fetchHeatmap(),
    ])
  } finally {
    statsStore.dimensionsLoading = false
  }
}

onMounted(async () => {
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

// ── Chart data helpers ───────────────────────────────────

function pnlBarData(items, labelField) {
  if (!items || items.length === 0) return null
  return {
    labels: items.map((d) => d[labelField]),
    datasets: [{
      label: t('performance.total_pnl'),
      data: items.map((d) => Number(d.total_pnl)),
      backgroundColor: items.map((d) => (Number(d.total_pnl) >= 0 ? '#22c55e' : '#ef4444')),
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
        backgroundColor: '#3b82f6',
        borderRadius: 4,
        yAxisID: 'y',
      },
      {
        label: t('performance.avg_rr'),
        data: items.map((d) => Number(d.avg_rr || 0)),
        backgroundColor: '#a855f7',
        borderRadius: 4,
        yAxisID: 'y1',
      },
    ],
  }
}

// ── Chart data ───────────────────────────────────────────

const pnlBySymbolChartData = computed(() => pnlBarData(statsStore.bySymbol, 'symbol'))
const pnlByPeriodChartData = computed(() => pnlBarData(statsStore.byPeriod, 'period'))

const perfBySymbolChartData = computed(() => dualMetricData(statsStore.bySymbol, 'symbol'))
const perfBySetupChartData = computed(() => dualMetricData(statsStore.bySetup, 'setup'))
const perfBySessionChartData = computed(() =>
  dualMetricData(statsStore.bySession, 'session', (v) => t(`performance.sessions.${v}`)),
)
const perfByAccountTypeChartData = computed(() =>
  dualMetricData(statsStore.byAccountType, 'account_type', (v) => t(`accounts.types.${v}`)),
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
      borderColor: '#3b82f6',
      backgroundColor: 'rgba(59, 130, 246, 0.1)',
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
      backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'],
    }],
  }
})
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold dark:text-gray-100 mb-4">{{ t('performance.title') }}</h1>

    <DashboardFilters @apply="onApplyFilters" @reset="onResetFilters" />

    <div v-if="statsStore.dimensionsLoading" class="text-gray-500 py-8 text-center">
      <i class="pi pi-spin pi-spinner text-2xl"></i>
    </div>

    <template v-else>
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

      <!-- Row 3: P&L by Symbol + Win Rate & R:R by Symbol -->
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
          :title="t('performance.perf_by_symbol')"
          type="bar"
          :data="perfBySymbolChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('symbol')"
        />
      </div>

      <!-- Row 4: Perf by Setup + P&L by Period -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <ChartCard
          :title="t('performance.perf_by_setup')"
          type="bar"
          :data="perfBySetupChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('setup')"
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
            <Select
              v-model="periodGroup"
              :options="periodGroupOptions"
              optionLabel="label"
              optionValue="value"
              class="w-32"
              @change="onPeriodGroupChange"
            />
          </template>
        </ChartCard>
      </div>

      <!-- Row 5: Perf by Session + Perf by Account Type -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <ChartCard
          :title="t('performance.perf_by_session')"
          type="bar"
          :data="perfBySessionChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('session')"
        />
        <ChartCard
          :title="t('performance.perf_by_account_type')"
          type="bar"
          :data="perfByAccountTypeChartData"
          :options="dualAxisChartOptions"
          detailable
          @detail="openDetail('account_type')"
        />
      </div>

      <!-- Row 6: Heatmap (full width) -->
      <div class="mb-6">
        <HeatmapChart :data="statsStore.heatmap" />
      </div>
    </template>

    <StatsDetailDialog
      v-model:visible="dialogVisible"
      :dimension="dialogDimension"
      :data="dialogData"
    />
  </div>
</template>
