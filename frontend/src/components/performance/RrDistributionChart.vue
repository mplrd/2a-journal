<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChartOptions } from '@/composables/useChartOptions'
import { CHART_PALETTE } from '@/constants/chartPalette'
import ChartCard from './ChartCard.vue'

const { t } = useI18n()
const { barChartOptions } = useChartOptions()

const props = defineProps({
  data: { type: Array, default: () => [] },
})

const bucketLabels = {
  '<-2': '< -2R',
  '-2--1': '-2R / -1R',
  '-1-0': '-1R / 0',
  '0-1': '0 / +1R',
  '1-2': '+1R / +2R',
  '2-3': '+2R / +3R',
  '>3': '> +3R',
}

const chartData = computed(() => {
  if (!props.data || props.data.length === 0) return null
  return {
    labels: props.data.map((d) => bucketLabels[d.bucket] || d.bucket),
    datasets: [{
      label: t('performance.rr_distribution_title'),
      data: props.data.map((d) => Number(d.count)),
      backgroundColor: props.data.map((d) => {
        const bucket = d.bucket
        if (bucket.startsWith('<') || bucket.startsWith('-')) return CHART_PALETTE.negative
        if (bucket === '0-1') return CHART_PALETTE.warning
        return CHART_PALETTE.positive
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
