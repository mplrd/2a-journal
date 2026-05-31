import { defineStore } from 'pinia'
import { ref } from 'vue'
import { supportService } from '@/services/support'

export const useSupportStore = defineStore('support', () => {
  const tickets = ref([])
  const current = ref(null)
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})
  const page = ref(1)
  const perPage = ref(20)
  const totalRecords = ref(0)

  async function fetchTickets() {
    loading.value = true
    error.value = null
    try {
      const response = await supportService.list({
        ...filters.value,
        page: page.value,
        per_page: perPage.value,
      })
      tickets.value = response.data
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

  async function fetchTicket(id) {
    loading.value = true
    error.value = null
    try {
      const response = await supportService.get(id)
      current.value = response.data
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createTicket(payload) {
    loading.value = true
    error.value = null
    try {
      const response = await supportService.create(payload)
      tickets.value.unshift(response.data)
      current.value = response.data
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function reply(id, payload) {
    loading.value = true
    error.value = null
    try {
      const response = await supportService.reply(id, payload)
      current.value = response.data
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
    tickets.value = []
    current.value = null
    loading.value = false
    error.value = null
    filters.value = {}
    page.value = 1
    perPage.value = 20
    totalRecords.value = 0
  }

  return {
    tickets,
    current,
    loading,
    error,
    filters,
    page,
    perPage,
    totalRecords,
    fetchTickets,
    fetchTicket,
    createTicket,
    reply,
    setFilters,
    $reset,
  }
})
