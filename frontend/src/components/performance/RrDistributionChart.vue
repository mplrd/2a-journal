<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChartOptions } from '@/composables/useChartOptions'
import ChartCard from './ChartCard.vue'

const { t } = useI18n()
const { barChartOptions } = useChartOptions()

const props = defineProps({
  data: { type: Array, default: () => [] },
})

const chartData = computed(() => {
  if (!props.data || props.data.length === 0) return null
  return {
    labels: props.data.map((d) => d.bucket),
    datasets: [{
      label: t('performance.rr_distribution_title'),
      data: props.data.map((d) => Number(d.count)),
      backgroundColor: props.data.map((d) => {
        const bucket = d.bucket
        if (bucket.startsWith('<') || bucket.startsWith('-')) return '#ef4444'
        if (bucket === '0-1') return '#f59e0b'
        return '#22c55e'
      }),
      borderRadius: 4,
    }],
  }
})
</script>

<template>
  <ChartCard
    :title="t('performance.rr_distribution_title')"
    type="bar"
    :data="chartData"
    :options="barChartOptions"
  />
</template>
