<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChartOptions } from '@/composables/useChartOptions'
import ChartCard from './ChartCard.vue'

const { t } = useI18n()
const { lineChartOptions } = useChartOptions()

const props = defineProps({
  cumulativePnl: { type: Array, default: () => [] },
  initialCapital: { type: Number, default: 0 },
})

const chartData = computed(() => {
  if (!props.cumulativePnl || props.cumulativePnl.length === 0) return null
  const capital = props.initialCapital || 0
  return {
    labels: props.cumulativePnl.map((d) => {
      const date = new Date(d.closed_at)
      return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    }),
    datasets: [{
      label: t('performance.equity_curve_title'),
      data: props.cumulativePnl.map((d) => capital + d.cumulative_pnl),
      fill: true,
      borderColor: '#8b5cf6',
      backgroundColor: 'rgba(139, 92, 246, 0.1)',
      tension: 0.3,
      pointRadius: 3,
      pointHoverRadius: 6,
    }],
  }
})
</script>

<template>
  <ChartCard
    :title="t('performance.equity_curve_title')"
    type="line"
    :data="chartData"
    :options="lineChartOptions"
  />
</template>
