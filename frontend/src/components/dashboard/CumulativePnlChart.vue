<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChartOptions } from '@/composables/useChartOptions'
import { primaryFor, withAlpha } from '@/constants/chartPalette'
import ChartCard from '@/components/performance/ChartCard.vue'

const { t } = useI18n()
const { lineChartOptions, isDark } = useChartOptions()

const props = defineProps({
  data: { type: Array, default: () => [] },
})

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
    :options="lineChartOptions"
  />
</template>
