<script setup>
import { computed, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import zoomPlugin from 'chartjs-plugin-zoom'
import { useChartOptions } from '@/composables/useChartOptions'
import { primaryFor, withAlpha } from '@/constants/chartPalette'
import ChartCard from '@/components/performance/ChartCard.vue'

const { t } = useI18n()
const { lineChartOptions, isDark } = useChartOptions()

const props = defineProps({
  data: { type: Array, default: () => [] },
})

const chartPlugins = [zoomPlugin]

// The live chart, as the zoom plugin hands it over once zoomed or panned; null
// while the whole history shows. Kept shallow: Chart.js must not be proxied.
const zoomedChart = shallowRef(null)

// Zoomed on a stretch of history far from zero, keeping zero on the axis would
// flatten the very curve one zoomed in to read: the y axis fits the period
// while zoomed. This goes straight to the live chart — any change to the
// options would rebuild it and drop the zoom.
function onZoomed({ chart }) {
  chart.options.scales.y.beginAtZero = false
  chart.update('none')
  zoomedChart.value = chart
}

function resetZoom() {
  const chart = zoomedChart.value
  chart.options.scales.y.beginAtZero = true
  chart.resetZoom()
  zoomedChart.value = null
}

// New data rebuilds the chart from scratch, unzoomed.
watch(() => props.data, () => {
  zoomedChart.value = null
})

// Zoom on the time axis only. A plain drag selects the area to zoom in on, so
// panning takes Shift; the plain wheel is left to the page scroll and zooms
// with Ctrl. Never further out than the whole history.
const chartOptions = computed(() => ({
  ...lineChartOptions.value,
  plugins: {
    ...lineChartOptions.value.plugins,
    zoom: {
      zoom: {
        mode: 'x',
        drag: { enabled: true },
        wheel: { enabled: true, modifierKey: 'ctrl' },
        pinch: { enabled: true },
        onZoomComplete: onZoomed,
      },
      pan: {
        enabled: true,
        mode: 'x',
        modifierKey: 'shift',
        onPanComplete: onZoomed,
      },
      limits: { x: { min: 'original', max: 'original' } },
    },
  },
}))

const chartData = computed(() => {
  if (!props.data || props.data.length === 0) return null
  return {
    labels: props.data.map((d) => {
      const date = new Date(d.closed_at)
      return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    }),
    datasets: [{
      label: t('dashboard.cumulative_pnl'),
      data: props.data.map((d) => d.cumulative_pnl),
      fill: true,
      borderColor: primaryFor(isDark.value),
      backgroundColor: withAlpha(primaryFor(isDark.value), 0.1),
      tension: 0.3,
      pointRadius: 3,
      pointHoverRadius: 6,
    }],
  }
})
</script>

<template>
  <ChartCard
    :title="t('dashboard.cumulative_pnl')"
    type="line"
    :data="chartData"
    :options="chartOptions"
    :plugins="chartPlugins"
  >
    <template #header-actions>
      <Button
        v-if="zoomedChart"
        data-testid="reset-zoom"
        :label="t('dashboard.reset_zoom')"
        icon="pi pi-refresh"
        severity="secondary"
        text
        size="small"
        @click="resetZoom"
      />
      <i
        v-if="chartData"
        data-testid="cumulative-pnl-zoom-help"
        class="pi pi-info-circle text-gray-400 cursor-help text-xs"
        role="img"
        :aria-label="t('dashboard.cumulative_pnl_zoom_help')"
        v-tooltip.top="t('dashboard.cumulative_pnl_zoom_help')"
      />
    </template>
  </ChartCard>
</template>
