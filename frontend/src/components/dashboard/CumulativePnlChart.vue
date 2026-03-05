<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Chart from 'primevue/chart'

const { t } = useI18n()

const isDark = computed(() => document.documentElement.classList.contains('dark-mode'))

const props = defineProps({
  data: {
    type: Array,
    default: () => [],
  },
})

const chartData = computed(() => {
  if (!props.data || props.data.length === 0) return null

  return {
    labels: props.data.map((d) => {
      const date = new Date(d.closed_at)
      return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
    }),
    datasets: [
      {
        label: t('dashboard.cumulative_pnl'),
        data: props.data.map((d) => d.cumulative_pnl),
        fill: true,
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        tension: 0.3,
        pointRadius: 3,
        pointHoverRadius: 6,
      },
    ],
  }
})

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
  },
  scales: {
    y: {
      beginAtZero: true,
      grid: { color: isDark.value ? '#374151' : '#f3f4f6' },
      ticks: { color: isDark.value ? '#9ca3af' : '#6b7280' },
    },
    x: {
      grid: { display: false },
      ticks: { color: isDark.value ? '#9ca3af' : '#6b7280' },
    },
  },
}))
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">{{ t('dashboard.cumulative_pnl') }}</h3>
    <div v-if="chartData" class="h-64">
      <Chart type="line" :data="chartData" :options="chartOptions" class="h-full" />
    </div>
    <p v-else class="text-gray-400 text-sm py-8 text-center">{{ t('dashboard.no_data') }}</p>
  </div>
</template>
