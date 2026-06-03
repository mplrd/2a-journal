import { defineStore } from 'pinia'
import { ref } from 'vue'
import { accountsService } from '@/services/accounts'

export const useAccountsStore = defineStore('accounts', () => {
  const accounts = ref([])
  const adjustments = ref([])
  const loaded = ref(false)
  const loading = ref(false)
  const error = ref(null)

  async function fetchAccounts() {
    loading.value = true
    error.value = null
    try {
      const response = await accountsService.list()
      accounts.value = response.data
      loaded.value = true
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createAccount(data) {
    loading.value = true
    error.value = null
    try {
      const response = await accountsService.create(data)
      accounts.value.unshift(response.data)
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateAccount(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await accountsService.update(id, data)
      const index = accounts.value.findIndex((a) => a.id === id)
      if (index !== -1) {
        accounts.value[index] = response.data
      }
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteAccount(id) {
    loading.value = true
    error.value = null
    try {
      await accountsService.remove(id)
      accounts.value = accounts.value.filter((a) => a.id !== id)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  // ── Balance adjustments (ticket #30) ──────────────────────────

  async function fetchAdjustments(id) {
    error.value = null
    try {
      const response = await accountsService.listAdjustments(id)
      adjustments.value = response.data
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function addAdjustment(id, data) {
    error.value = null
    try {
      return await accountsService.addAdjustment(id, data)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  async function deleteAdjustment(id, adjustmentId) {
    error.value = null
    try {
      return await accountsService.deleteAdjustment(id, adjustmentId)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    }
  }

  function $reset() {
    accounts.value = []
    adjustments.value = []
    loaded.value = false
    loading.value = false
    error.value = null
  }

  return {
    accounts,
    adjustments,
    loaded,
    loading,
    error,
    fetchAccounts,
    createAccount,
    updateAccount,
    deleteAccount,
    fetchAdjustments,
    addAdjustment,
    deleteAdjustment,
    $reset,
  }
})
