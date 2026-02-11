import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ordersService } from '@/services/orders'

export const useOrdersStore = defineStore('orders', () => {
  const orders = ref([])
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})

  async function fetchOrders() {
    loading.value = true
    error.value = null
    try {
      const response = await ordersService.list(filters.value)
      orders.value = response.data
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createOrder(data) {
    loading.value = true
    error.value = null
    try {
      const response = await ordersService.create(data)
      orders.value.unshift(response.data)
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function cancelOrder(id) {
    loading.value = true
    error.value = null
    try {
      const response = await ordersService.cancel(id)
      const index = orders.value.findIndex((o) => o.id === id)
      if (index !== -1) {
        orders.value[index] = response.data
      }
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function executeOrder(id) {
    loading.value = true
    error.value = null
    try {
      const response = await ordersService.execute(id)
      const index = orders.value.findIndex((o) => o.id === id)
      if (index !== -1) {
        orders.value[index] = response.data
      }
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteOrder(id) {
    loading.value = true
    error.value = null
    try {
      await ordersService.remove(id)
      orders.value = orders.value.filter((o) => o.id !== id)
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

  return {
    orders,
    loading,
    error,
    filters,
    fetchOrders,
    createOrder,
    cancelOrder,
    executeOrder,
    deleteOrder,
    setFilters,
  }
})
