import { defineStore } from 'pinia'
import { ref } from 'vue'
import { tradesService } from '@/services/trades'

export const useTradesStore = defineStore('trades', () => {
  const trades = ref([])
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})

  async function fetchTrades() {
    loading.value = true
    error.value = null
    try {
      const response = await tradesService.list(filters.value)
      trades.value = response.data
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createTrade(data) {
    loading.value = true
    error.value = null
    try {
      const response = await tradesService.create(data)
      trades.value.unshift(response.data)
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function closeTrade(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await tradesService.close(id, data)
      const index = trades.value.findIndex((t) => t.id === id)
      if (index !== -1) {
        trades.value[index] = response.data
      }
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteTrade(id) {
    loading.value = true
    error.value = null
    try {
      await tradesService.remove(id)
      trades.value = trades.value.filter((t) => t.id !== id)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  function setFilters(newFilters) {
    filters.value = { ...newFilters }
  }

  function $reset() {
    trades.value = []
    loading.value = false
    error.value = null
    filters.value = {}
  }

  return {
    trades,
    loading,
    error,
    filters,
    fetchTrades,
    createTrade,
    closeTrade,
    deleteTrade,
    setFilters,
    $reset,
  }
})
