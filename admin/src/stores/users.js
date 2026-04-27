import { defineStore } from 'pinia'
import { ref } from 'vue'
import { usersService } from '@/services/users'

export const useUsersStore = defineStore('users', () => {
  const users = ref([])
  const total = ref(0)
  const loading = ref(false)
  const error = ref(null)
  const filters = ref({})
  const page = ref(1)
  const perPage = ref(50)

  async function fetchUsers() {
    loading.value = true
    error.value = null
    try {
      const response = await usersService.list({
        ...filters.value,
        page: page.value,
        per_page: perPage.value,
      })
      users.value = response.data
      total.value = response.meta?.total ?? response.data.length
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function suspend(id) {
    const response = await usersService.suspend(id)
    replaceLocal(response.data)
    return response
  }

  async function unsuspend(id) {
    const response = await usersService.unsuspend(id)
    replaceLocal(response.data)
    return response
  }

  async function resetPassword(id) {
    return usersService.resetPassword(id)
  }

  async function remove(id) {
    await usersService.remove(id)
    users.value = users.value.filter((u) => u.id !== id)
  }

  function replaceLocal(updated) {
    const idx = users.value.findIndex((u) => u.id === updated.id)
    if (idx !== -1) users.value[idx] = updated
  }

  function setFilters(next) {
    filters.value = { ...next }
  }

  return {
    users, total, loading, error, filters, page, perPage,
    fetchUsers, suspend, unsuspend, resetPassword, remove, setFilters,
  }
})
