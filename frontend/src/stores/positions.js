import { defineStore } from 'pinia'
import { ref } from 'vue'
import { positionsService } from '@/services/positions'

export const usePositionsStore = defineStore('positions', () => {
  const positions = ref([])
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})

  async function fetchPositions() {
    loading.value = true
    error.value = null
    try {
      const response = await positionsService.list(filters.value)
      positions.value = response.data
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
      const response = await positionsService.listAggregated(filters.value)
      positions.value = response.data
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
  }

  return {
    positions,
    loading,
    error,
    filters,
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
