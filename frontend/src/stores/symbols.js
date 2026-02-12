import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { symbolsService } from '@/services/symbols'

export const useSymbolsStore = defineStore('symbols', () => {
  const symbols = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref(null)

  const symbolOptions = computed(() =>
    symbols.value.map((s) => ({ label: s.code, value: s.code })),
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

  return {
    symbols,
    loading,
    loaded,
    error,
    symbolOptions,
    fetchSymbols,
  }
})
