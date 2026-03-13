import { defineStore } from 'pinia'
import { ref } from 'vue'
import { statsService } from '@/services/stats'

export const useStatsStore = defineStore('stats', () => {
  const overview = ref(null)
  const recentTrades = ref([])
  const charts = ref(null)
  const loading = ref(false)
  const chartsLoading = ref(false)
  const error = ref(null)
  const filters = ref({})

  // Dimension stats
  const bySymbol = ref([])
  const byDirection = ref([])
  const bySetup = ref([])
  const byPeriod = ref([])
  const rrDistribution = ref([])
  const heatmap = ref([])
  const dimensionsLoading = ref(false)

  async function fetchDashboard() {
    loading.value = true
    error.value = null
    try {
      const response = await statsService.getDashboard(filters.value)
      overview.value = response.data.overview
      recentTrades.value = response.data.recent_trades
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchCharts() {
    chartsLoading.value = true
    try {
      const response = await statsService.getCharts(filters.value)
      charts.value = response.data
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      chartsLoading.value = false
    }
  }

  async function fetchBySymbol() {
    try {
      const response = await statsService.getBySymbol(filters.value)
      bySymbol.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function fetchByDirection() {
    try {
      const response = await statsService.getByDirection(filters.value)
      byDirection.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function fetchBySetup() {
    try {
      const response = await statsService.getBySetup(filters.value)
      bySetup.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function fetchByPeriod(group = 'month') {
    try {
      const response = await statsService.getByPeriod(filters.value, group)
      byPeriod.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function fetchRrDistribution() {
    try {
      const response = await statsService.getRrDistribution(filters.value)
      rrDistribution.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function fetchHeatmap() {
    try {
      const response = await statsService.getHeatmap(filters.value)
      heatmap.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  function setFilters(newFilters) {
    filters.value = { ...newFilters }
  }

  function $reset() {
    overview.value = null
    recentTrades.value = []
    charts.value = null
    loading.value = false
    chartsLoading.value = false
    error.value = null
    filters.value = {}
    bySymbol.value = []
    byDirection.value = []
    bySetup.value = []
    byPeriod.value = []
    rrDistribution.value = []
    heatmap.value = []
    dimensionsLoading.value = false
  }

  return {
    overview,
    recentTrades,
    charts,
    loading,
    chartsLoading,
    error,
    filters,
    bySymbol,
    byDirection,
    bySetup,
    byPeriod,
    rrDistribution,
    heatmap,
    dimensionsLoading,
    fetchDashboard,
    fetchCharts,
    fetchBySymbol,
    fetchByDirection,
    fetchBySetup,
    fetchByPeriod,
    fetchRrDistribution,
    fetchHeatmap,
    setFilters,
    $reset,
  }
})
