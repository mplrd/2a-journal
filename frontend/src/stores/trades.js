import { defineStore } from 'pinia'
import { ref } from 'vue'
import { tradesService } from '@/services/trades'

export const useTradesStore = defineStore('trades', () => {
  const trades = ref([])
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})
  const page = ref(1)
  const perPage = ref(10)
  const totalRecords = ref(0)

  async function fetchTrades() {
    loading.value = true
    error.value = null
    try {
      const response = await tradesService.list({
        ...filters.value,
        page: page.value,
        per_page: perPage.value,
      })
      trades.value = response.data
      if (response.meta) {
        totalRecords.value = response.meta.total || 0
      }
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

  async function markBeHit(id) {
    try {
      const response = await tradesService.markBeHit(id)
      const index = trades.value.findIndex((t) => t.id === id)
      if (index !== -1) {
        trades.value[index] = response.data
      }
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
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

  async function bulkDeleteTrades(ids) {
    loading.value = true
    error.value = null
    try {
      const res = await tradesService.bulkDelete(ids)
      const idSet = new Set(ids.map(Number))
      trades.value = trades.value.filter((t) => !idSet.has(Number(t.id)))
      return res?.data?.deleted_count ?? ids.length
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
    page.value = 1
    perPage.value = 10
    totalRecords.value = 0
  }

  return {
    trades,
    loading,
    error,
    filters,
    page,
    perPage,
    totalRecords,
    fetchTrades,
    createTrade,
    closeTrade,
    markBeHit,
    deleteTrade,
    bulkDeleteTrades,
    setFilters,
    $reset,
  }
})
