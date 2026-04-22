<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useChartOptions } from '@/composables/useChartOptions'
import ChartCard from '@/components/performance/ChartCard.vue'

const { t } = useI18n()
const { doughnutChartOptions } = useChartOptions()

const props = defineProps({
  data: { type: Object, default: null },
})

const chartData = computed(() => {
  if (!props.data || props.data.win + props.data.loss + props.data.be === 0) return null
  return {
    labels: [t('dashboard.wins'), t('dashboard.losses'), t('dashboard.breakeven')],
    datasets: [{
      data: [props.data.win, props.data.loss, props.data.be],
      backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'],
      hoverBackgroundColor: ['#16a34a', '#dc2626', '#d97706'],
    }],
  }
})
</script>

<template>
  <ChartCard
    :title="t('dashboard.win_loss_distribution')"
    type="doughnut"
    :data="chartData"
    :options="doughnutChartOptions"
  />
</template>
