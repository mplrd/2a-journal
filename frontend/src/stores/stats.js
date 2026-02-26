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
  }

  return {
    overview,
    recentTrades,
    charts,
    loading,
    chartsLoading,
    error,
    filters,
    fetchDashboard,
    fetchCharts,
    setFilters,
    $reset,
  }
})
