import { defineStore } from 'pinia'
import { ref } from 'vue'
import { positionsService } from '@/services/positions'

export const usePositionsStore = defineStore('positions', () => {
  const positions = ref([])
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})
  const page = ref(1)
  const perPage = ref(10)
  const totalRecords = ref(0)

  async function fetchPositions() {
    loading.value = true
    error.value = null
    try {
      const response = await positionsService.list({
        ...filters.value,
        page: page.value,
        per_page: perPage.value,
      })
      positions.value = response.data
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

  async function fetchAggregated() {
    loading.value = true
    error.value = null
    try {
      const response = await positionsService.listAggregated({
        ...filters.value,
        page: page.value,
        per_page: perPage.value,
      })
      positions.value = response.data
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

  async function fetchPosition(id) {
    const response = await positionsService.get(id)
    return response.data
  }

  async function updatePosition(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await positionsService.update(id, data)
      const index = positions.value.findIndex((p) => p.id === id)
      if (index !== -1) {
        positions.value[index] = response.data
      }
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deletePosition(id) {
    loading.value = true
    error.value = null
    try {
      await positionsService.remove(id)
      positions.value = positions.value.filter((p) => p.id !== id)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function transferPosition(id, accountId) {
    loading.value = true
    error.value = null
    try {
      const response = await positionsService.transfer(id, { account_id: accountId })
      const index = positions.value.findIndex((p) => p.id === id)
      if (index !== -1) {
        positions.value[index] = response.data
      }
      return response
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
    positions.value = []
    loading.value = false
    error.value = null
    filters.value = {}
    page.value = 1
    perPage.value = 10
    totalRecords.value = 0
  }

  return {
    positions,
    loading,
    error,
    filters,
    page,
    perPage,
    totalRecords,
    fetchPositions,
    fetchAggregated,
    fetchPosition,
    updatePosition,
    deletePosition,
    transferPosition,
    setFilters,
    $reset,
  }
})
