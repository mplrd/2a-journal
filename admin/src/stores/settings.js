import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingsService } from '@/services/settings'

export const useSettingsStore = defineStore('settings', () => {
  const settings = ref([])
  const loading = ref(false)
  const error = ref(null)

  async function fetchSettings() {
    loading.value = true
    error.value = null
    try {
      const response = await settingsService.list()
      settings.value = response.data
      return response
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function update(key, value) {
    const response = await settingsService.update(key, value)
    settings.value = response.data
    return response
  }

  return { settings, loading, error, fetchSettings, update }
})
