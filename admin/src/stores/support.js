import { defineStore } from 'pinia'
import { ref } from 'vue'
import { supportService } from '@/services/support'

export const useSupportStore = defineStore('support', () => {
  const tickets = ref([])
  const current = ref(null)
  const total = ref(0)
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})
  const page = ref(1)
  const perPage = ref(50)

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
      total.value = response.meta?.total ?? response.data.length
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

  async function reply(id, payload) {
    const response = await supportService.reply(id, payload)
    current.value = response.data
    syncListFrom(response.data)
    return response
  }

  async function updateStatus(id, status) {
    const response = await supportService.updateStatus(id, status)
    current.value = response.data
    syncListFrom(response.data)
    return response
  }

  async function updatePriority(id, priority) {
    const response = await supportService.updatePriority(id, priority)
    current.value = response.data
    syncListFrom(response.data)
    return response
  }

  async function updateType(id, type) {
    const response = await supportService.updateType(id, type)
    current.value = response.data
    syncListFrom(response.data)
    return response
  }

  // Keep the row in the list aligned with the detail (type/status/priority badges)
  function syncListFrom(detail) {
    const idx = tickets.value.findIndex((tk) => tk.id === detail.id)
    if (idx !== -1) {
      tickets.value[idx] = {
        ...tickets.value[idx],
        type: detail.type,
        status: detail.status,
        priority: detail.priority,
      }
    }
  }

  function setFilters(next) {
    filters.value = { ...next }
  }

  return {
    tickets, current, total, loading, error, filters, page, perPage,
    fetchTickets, fetchTicket, reply, updateStatus, updatePriority, updateType, setFilters,
  }
})
