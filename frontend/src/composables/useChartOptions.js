import { computed } from 'vue'

// Axis/grid contrast tuned to the charte palette. Keep the values narrow:
// just enough contrast to read the values, never enough to fight with the
// data series for attention.
const AXIS_LIGHT = {
  grid: '#ececea',     // gray-100 (charte warm)
  tick: '#6b6e75',     // gray-500
  legend: '#3a3d44',   // gray-700
}
const AXIS_DARK = {
  grid: 'rgba(255,255,255,0.08)',
  tick: '#93a3b9',     // brand-navy-300
  legend: '#c0c8d4',
}

export function useChartOptions() {
  const isDark = computed(() => document.documentElement.classList.contains('dark-mode'))
  const axis = computed(() => (isDark.value ? AXIS_DARK : AXIS_LIGHT))

  const barChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: axis.value.grid },
        ticks: { color: axis.value.tick },
      },
      x: {
        grid: { display: false },
        ticks: { color: axis.value.tick },
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
        grid: { color: axis.value.grid },
        ticks: { color: axis.value.tick },
      },
      x: {
        grid: { display: false },
        ticks: { color: axis.value.tick },
      },
    },
  }))

  const dualAxisChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { color: axis.value.legend } } },
    scales: {
      y: {
        beginAtZero: true,
        position: 'left',
        title: { display: true, text: 'Win Rate %', color: axis.value.legend },
        grid: { color: axis.value.grid },
        ticks: { color: axis.value.tick },
      },
      y1: {
        beginAtZero: true,
        position: 'right',
        title: { display: true, text: 'R:R', color: axis.value.legend },
        grid: { drawOnChartArea: false },
        ticks: { color: axis.value.tick },
      },
      x: {
        grid: { display: false },
        ticks: { color: axis.value.tick },
      },
    },
  }))

  const doughnutChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { color: axis.value.legend } } },
  }))

  return { isDark, barChartOptions, lineChartOptions, doughnutChartOptions, dualAxisChartOptions }
}
