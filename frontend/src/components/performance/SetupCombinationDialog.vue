<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import MultiSelect from 'primevue/multiselect'
import Button from 'primevue/button'
import Chart from 'primevue/chart'
import { useStatsStore } from '@/stores/stats'
import { useSetupsStore } from '@/stores/setups'
import { CHART_PALETTE } from '@/constants/chartPalette'

const { t } = useI18n()

const props = defineProps({
  visible: { type: Boolean, required: true },
})

const emit = defineEmits(['update:visible'])

const statsStore = useStatsStore()
const setupsStore = useSetupsStore()

// State: each row is a combination the user composes ad-hoc.
const rows = ref([{ setupIds: [] }])
const result = ref(null)
const loading = ref(false)
const errorKey = ref(null)

// Debounce window between successive MultiSelect updates and the actual fetch
// — covers the case where the user clicks several setups in a row.
const FETCH_DEBOUNCE_MS = 300

// Distinct colors for combos. The baseline always sits in slot 0 (gray).
// Combos take the remaining palette in order, so the same combo keeps the
// same color across all 3 charts (Win Rate / R:R / P&L).
const BASELINE_COLOR = '#6b7280' // gray-500
const COMBO_COLORS = [
  CHART_PALETTE.primary,
  CHART_PALETTE.positive,
  CHART_PALETTE.warning,
  CHART_PALETTE.negative,
  CHART_PALETTE.positiveLt,
  '#8b5cf6', // violet
  '#ec4899', // pink
  '#14b8a6', // teal
  '#f97316', // orange
  '#06b6d4', // cyan
]

const setupOptions = computed(() =>
  setupsStore.setups.map((s) => ({ id: s.id, label: s.label, category: s.category })),
)

const filledRows = computed(() => rows.value.filter((r) => r.setupIds.length > 0))

// Stable signature of the active combinations: changes only when the *content*
// of the filled rows changes, NOT when the user adds/removes empty rows. Used
// as the watch target so we don't refetch on a no-op like "user clicked + then
// hasn't picked anything yet".
const filledSignature = computed(() =>
  JSON.stringify(filledRows.value.map((r) => [...r.setupIds].sort((a, b) => a - b))),
)

const canRemoveRow = computed(() => rows.value.length > 1)

let debounceTimer = null

async function fetchAnalysis() {
  loading.value = true
  errorKey.value = null
  try {
    const combinations = filledRows.value.map((r) => ({ setup_ids: r.setupIds }))
    result.value = await statsStore.analyzeSetupCombinations(combinations)
  } catch (err) {
    errorKey.value = err?.messageKey || 'error.internal'
    // Keep the previous successful result on screen on error — failing silent
    // beats blanking the charts and leaves the user something to look at.
  } finally {
    loading.value = false
  }
}

function scheduleFetch() {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer)
  }
  debounceTimer = setTimeout(() => {
    debounceTimer = null
    fetchAnalysis()
  }, FETCH_DEBOUNCE_MS)
}

watch(
  () => props.visible,
  (open) => {
    if (open) {
      // First render with visible=true OR transition closed → open: kick off
      // an immediate baseline fetch so the right column is never empty when
      // the user lands on the modal.
      fetchAnalysis()
    } else {
      // Reset when closing so re-opening starts fresh.
      rows.value = [{ setupIds: [] }]
      result.value = null
      errorKey.value = null
      if (debounceTimer !== null) {
        clearTimeout(debounceTimer)
        debounceTimer = null
      }
    }
  },
  { immediate: true },
)

// Auto-refetch on any meaningful change to the filled combinations.
watch(filledSignature, (newSig, oldSig) => {
  if (!props.visible) return
  if (newSig === oldSig) return
  scheduleFetch()
})

function addRow() {
  rows.value.push({ setupIds: [] })
}

function removeRow(index) {
  if (rows.value.length <= 1) return
  rows.value.splice(index, 1)
}

function close() {
  emit('update:visible', false)
}

// ── Chart data ──────────────────────────────────────────────

// Series displayed across the 3 charts: the baseline first (gray), then one
// row per combination (each with its own color, in the order the user
// composed them).
const series = computed(() => {
  if (!result.value) return []
  const out = [
    {
      key: 'baseline',
      label: t('performance.setup_combination.global'),
      tradeCount: result.value.baseline.total_trades ?? 0,
      color: BASELINE_COLOR,
      stats: result.value.baseline,
    },
  ]
  result.value.combinations.forEach((c, i) => {
    out.push({
      key: `combo-${i}`,
      label:
        c.setups && c.setups.length > 0
          ? c.setups.join(' + ')
          : t('performance.setup_combination.combination_label', { n: i + 1 }),
      tradeCount: c.stats.total_trades ?? 0,
      color: COMBO_COLORS[i % COMBO_COLORS.length],
      stats: c.stats,
    })
  })
  return out
})

function metricChartData(field) {
  if (series.value.length === 0) return null
  return {
    labels: series.value.map((s) => `${s.label} (${s.tradeCount})`),
    datasets: [
      {
        data: series.value.map((s) => {
          const v = s.stats?.[field]
          return v == null ? 0 : Number(v)
        }),
        backgroundColor: series.value.map((s) => s.color),
        borderRadius: 4,
        label: '',
      },
    ],
  }
}

const winRateData = computed(() => metricChartData('win_rate'))
const avgRrData = computed(() => metricChartData('avg_rr'))
const totalPnlData = computed(() => metricChartData('total_pnl'))

function makeOptions(valueFormatter) {
  return {
    indexAxis: 'y',
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: { label: (ctx) => valueFormatter(ctx.parsed.x) },
      },
    },
    scales: {
      x: {
        beginAtZero: true,
        ticks: { callback: valueFormatter },
        grid: { display: true },
      },
      y: {
        grid: { display: false },
      },
    },
  }
}

const winRateOptions = computed(() => makeOptions((v) => `${Number(v).toFixed(1)}%`))
const avgRrOptions = computed(() => makeOptions((v) => Number(v).toFixed(2)))
const totalPnlOptions = computed(() => makeOptions((v) => Number(v).toFixed(0)))
</script>

<template>
  <Dialog
    :visible="visible"
    @update:visible="emit('update:visible', $event)"
    :header="t('performance.setup_combination.title')"
    modal
    :style="{ width: '90vw', maxWidth: '1100px' }"
    :dismissableMask="true"
  >
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
      {{ t('performance.setup_combination.intro') }}
    </p>

    <div
      data-test="dialog-grid"
      class="grid grid-cols-1 lg:grid-cols-[400px_1fr] gap-6"
    >
      <!-- LEFT: configuration -->
      <div data-test="config-column" class="flex flex-col gap-2">
        <div
          v-for="(row, i) in rows"
          :key="i"
          class="combo-row flex items-center gap-2"
        >
          <span class="text-xs text-gray-500 dark:text-gray-400 w-24 shrink-0">
            {{ t('performance.setup_combination.combination_label', { n: i + 1 }) }}
          </span>
          <MultiSelect
            v-model="row.setupIds"
            :options="setupOptions"
            optionLabel="label"
            optionValue="id"
            :placeholder="t('performance.setup_combination.placeholder')"
            filter
            showClear
            class="flex-1"
          />
          <Button
            v-if="canRemoveRow"
            icon="pi pi-trash"
            severity="secondary"
            text
            size="small"
            data-test="remove-combo"
            :aria-label="t('performance.setup_combination.remove_combination')"
            @click="removeRow(i)"
          />
        </div>
        <div>
          <Button
            :label="t('performance.setup_combination.add_combination')"
            icon="pi pi-plus"
            severity="secondary"
            text
            size="small"
            @click="addRow"
          />
        </div>
        <p v-if="errorKey" class="text-sm text-danger dark:text-danger-fg-dark mt-2">
          {{ t(errorKey) }}
        </p>
      </div>

      <!-- RIGHT: charts -->
      <div data-test="charts-column" class="relative flex flex-col gap-4">
        <div
          v-if="loading"
          class="absolute inset-0 z-10 flex items-center justify-center bg-white/40 dark:bg-gray-900/40 pointer-events-none"
        >
          <i class="pi pi-spin pi-spinner text-2xl text-gray-400" />
        </div>

        <template v-if="result">
          <div>
            <h4 class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
              {{ t('performance.setup_combination.chart_win_rate') }}
            </h4>
            <div class="h-40"><Chart type="bar" :data="winRateData" :options="winRateOptions" class="h-full" /></div>
          </div>
          <div>
            <h4 class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
              {{ t('performance.setup_combination.chart_avg_rr') }}
            </h4>
            <div class="h-40"><Chart type="bar" :data="avgRrData" :options="avgRrOptions" class="h-full" /></div>
          </div>
          <div>
            <h4 class="text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
              {{ t('performance.setup_combination.chart_total_pnl') }}
            </h4>
            <div class="h-40"><Chart type="bar" :data="totalPnlData" :options="totalPnlOptions" class="h-full" /></div>
          </div>
        </template>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.close')" severity="secondary" text @click="close" />
    </template>
  </Dialog>
</template>
