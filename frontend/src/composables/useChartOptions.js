import { computed } from 'vue'

export function useChartOptions() {
  const isDark = computed(() => document.documentElement.classList.contains('dark-mode'))

  const barChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
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

  const lineChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
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

  const doughnutChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } },
  }

  return { isDark, barChartOptions, lineChartOptions, doughnutChartOptions }
}
