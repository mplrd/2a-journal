import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { setupsService } from '@/services/setups'

export const useSetupsStore = defineStore('setups', () => {
  const setups = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref(null)

  const setupOptions = computed(() => setups.value.map((s) => s.label))

  // Setups grouped by category, ordered timeframe → pattern → context → uncategorized.
  // Returns { timeframe: [labels], pattern: [labels], context: [labels], uncategorized: [labels] }.
  // Empty categories are still present so callers can decide whether to render them.
  const setupsByCategory = computed(() => {
    const groups = { timeframe: [], pattern: [], context: [], uncategorized: [] }
    for (const s of setups.value) {
      const key = s.category && groups[s.category] ? s.category : 'uncategorized'
      groups[key].push(s.label)
    }
    return groups
  })

  async function fetchSetups(force = false) {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const response = await setupsService.list()
      setups.value = response.data
      loaded.value = true
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createSetup(data) {
    loading.value = true
    error.value = null
    try {
      const response = await setupsService.create(data)
      setups.value.push(response.data)
      return response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateSetup(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await setupsService.update(id, data)
      const index = setups.value.findIndex((s) => s.id === id)
      if (index !== -1) {
        setups.value[index] = response.data
      }
      return response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteSetup(id) {
    loading.value = true
    error.value = null
    try {
      await setupsService.remove(id)
      setups.value = setups.value.filter((s) => s.id !== id)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  function $reset() {
    setups.value = []
    loading.value = false
    loaded.value = false
    error.value = null
  }

  return {
    setups,
    loading,
    loaded,
    error,
    setupOptions,
    setupsByCategory,
    fetchSetups,
    createSetup,
    updateSetup,
    deleteSetup,
    $reset,
  }
})
