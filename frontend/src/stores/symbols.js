import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { symbolsService } from '@/services/symbols'

export const useSymbolsStore = defineStore('symbols', () => {
  const symbols = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref(null)

  const symbolOptions = computed(() =>
    symbols.value.map((s) => ({ label: s.name, value: s.code })),
  )

  async function fetchSymbols(force = false) {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const response = await symbolsService.list()
      symbols.value = response.data
      loaded.value = true
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createSymbol(data) {
    loading.value = true
    error.value = null
    try {
      const response = await symbolsService.create(data)
      symbols.value.push(response.data)
      return response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateSymbol(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await symbolsService.update(id, data)
      const index = symbols.value.findIndex((s) => s.id === id)
      if (index !== -1) {
        symbols.value[index] = response.data
      }
      return response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteSymbol(id) {
    loading.value = true
    error.value = null
    try {
      await symbolsService.remove(id)
      symbols.value = symbols.value.filter((s) => s.id !== id)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  function $reset() {
    symbols.value = []
    loading.value = false
    loaded.value = false
    error.value = null
  }

  return {
    symbols,
    loading,
    loaded,
    error,
    symbolOptions,
    fetchSymbols,
    createSymbol,
    updateSymbol,
    deleteSymbol,
    $reset,
  }
})
