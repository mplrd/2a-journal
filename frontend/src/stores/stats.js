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

  // Dashboard
  const openTrades = ref([])
  const dailyPnl = ref([])

  // Dimension stats
  const bySymbol = ref([])
  const byDirection = ref([])
  const bySetup = ref([])
  const byPeriod = ref([])
  const bySession = ref([])
  const byAccount = ref([])
  const byAccountType = ref([])
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

  async function fetchBySession() {
    try {
      const response = await statsService.getBySession(filters.value)
      bySession.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function fetchByAccount() {
    try {
      const response = await statsService.getByAccount(filters.value)
      byAccount.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function fetchByAccountType() {
    try {
      const response = await statsService.getByAccountType(filters.value)
      byAccountType.value = response.data
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

  async function fetchOpenTrades() {
    try {
      const response = await statsService.getOpenTrades(filters.value)
      openTrades.value = response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function analyzeSetupCombinations(combinations, match = 'all') {
    // Inherit the page-level filters (account, date range, etc.) so every
    // combination's stats AND the shared baseline are computed on the same
    // trade population the user is currently looking at.
    const payload = {
      ...filters.value,
      combinations,
      match,
    }
    const response = await statsService.analyzeSetupCombinations(payload)
    return response.data
  }

  async function fetchDailyPnl() {
    try {
      const response = await statsService.getDailyPnl(filters.value)
      dailyPnl.value = response.data
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
    openTrades.value = []
    dailyPnl.value = []
    charts.value = null
    loading.value = false
    chartsLoading.value = false
    error.value = null
    filters.value = {}
    bySymbol.value = []
    byDirection.value = []
    bySetup.value = []
    byPeriod.value = []
    bySession.value = []
    byAccount.value = []
    byAccountType.value = []
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
    bySession,
    byAccount,
    byAccountType,
    rrDistribution,
    heatmap,
    dimensionsLoading,
    openTrades,
    dailyPnl,
    fetchDashboard,
    fetchCharts,
    fetchOpenTrades,
    fetchDailyPnl,
    fetchBySymbol,
    fetchByDirection,
    fetchBySetup,
    fetchByPeriod,
    fetchBySession,
    fetchByAccount,
    fetchByAccountType,
    fetchRrDistribution,
    fetchHeatmap,
    analyzeSetupCombinations,
    setFilters,
    $reset,
  }
})
