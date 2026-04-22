import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { customFieldsService } from '@/services/customFields'

export const useCustomFieldsStore = defineStore('customFields', () => {
  const definitions = ref([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref(null)

  const activeDefinitions = computed(() =>
    definitions.value.filter((d) => d.is_active),
  )

  async function fetchDefinitions(force = false) {
    if (loaded.value && !force) return
    loading.value = true
    error.value = null
    try {
      const response = await customFieldsService.list()
      definitions.value = response.data
      loaded.value = true
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createDefinition(data) {
    loading.value = true
    error.value = null
    try {
      const response = await customFieldsService.create(data)
      definitions.value.push(response.data)
      return response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateDefinition(id, data) {
    loading.value = true
    error.value = null
    try {
      const response = await customFieldsService.update(id, data)
      const index = definitions.value.findIndex((d) => d.id === id)
      if (index !== -1) {
        definitions.value[index] = response.data
      }
      return response.data
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteDefinition(id) {
    loading.value = true
    error.value = null
    try {
      await customFieldsService.remove(id)
      definitions.value = definitions.value.filter((d) => d.id !== id)
    } catch (err) {
      error.value = err.messageKey || 'error.internal'
      throw err
    } finally {
      loading.value = false
    }
  }

  function $reset() {
    definitions.value = []
    loading.value = false
    loaded.value = false
    error.value = null
  }

  return {
    definitions,
    loading,
    loaded,
    error,
    activeDefinitions,
    fetchDefinitions,
    createDefinition,
    updateDefinition,
    deleteDefinition,
    $reset,
  }
})
