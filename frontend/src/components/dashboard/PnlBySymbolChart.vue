<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChartOptions } from '@/composables/useChartOptions'
import ChartCard from '@/components/performance/ChartCard.vue'

const { t } = useI18n()
const { barChartOptions: baseBarOptions } = useChartOptions()

const props = defineProps({
  data: { type: Array, default: () => [] },
})

const chartData = computed(() => {
  if (!props.data || props.data.length === 0) return null
  return {
    labels: props.data.map((d) => d.symbol),
    datasets: [{
      label: t('dashboard.pnl_by_symbol'),
      data: props.data.map((d) => Number(d.total_pnl)),
      backgroundColor: props.data.map((d) => (Number(d.total_pnl) >= 0 ? '#22c55e' : '#ef4444')),
      borderRadius: 4,
    }],
  }
})

const chartOptions = computed(() => ({
  ...baseBarOptions.value,
  plugins: {
    ...baseBarOptions.value.plugins,
    tooltip: {
      callbacks: {
        afterLabel(ctx) {
          const item = props.data[ctx.dataIndex]
          return item ? t('dashboard.trade_count', { count: item.trade_count }) : ''
        },
      },
    },
  },
}))
</script>

<template>
  <ChartCard
    :title="t('dashboard.pnl_by_symbol')"
    type="bar"
    :data="chartData"
    :options="chartOptions"
  />
</template>
