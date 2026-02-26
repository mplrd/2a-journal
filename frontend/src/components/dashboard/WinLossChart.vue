<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Chart from 'primevue/chart'

const { t } = useI18n()

const props = defineProps({
  data: {
    type: Object,
    default: null,
  },
})

const hasData = computed(() => {
  if (!props.data) return false
  return props.data.win + props.data.loss + props.data.be > 0
})

const chartData = computed(() => {
  if (!hasData.value) return null

  return {
    labels: [
      t('dashboard.wins'),
      t('dashboard.losses'),
      t('dashboard.breakeven'),
    ],
    datasets: [
      {
        data: [props.data.win, props.data.loss, props.data.be],
        backgroundColor: ['#22c55e', '#ef4444', '#f59e0b'],
        hoverBackgroundColor: ['#16a34a', '#dc2626', '#d97706'],
      },
    ],
  }
})

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
      labels: { padding: 16 },
    },
  },
}))
</script>

<template>
  <div class="bg-white rounded-lg border border-gray-200 p-4">
    <h3 class="text-sm font-medium text-gray-500 mb-3">{{ t('dashboard.win_loss_distribution') }}</h3>
    <div v-if="chartData" class="h-64">
      <Chart type="doughnut" :data="chartData" :options="chartOptions" class="h-full" />
    </div>
    <p v-else class="text-gray-400 text-sm py-8 text-center">{{ t('dashboard.no_data') }}</p>
  </div>
</template>
