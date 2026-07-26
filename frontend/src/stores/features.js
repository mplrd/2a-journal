import { defineStore } from 'pinia'
import { ref } from 'vue'
import { featuresService } from '@/services/features'

export const useFeaturesStore = defineStore('features', () => {
  const brokerAutoSync = ref(false)
  const robots = ref(false)
  const plans = ref(false)
  const loaded = ref(false)

  async function load() {
    if (loaded.value) return
    try {
      const response = await featuresService.get()
      brokerAutoSync.value = !!response.data?.broker_auto_sync
      robots.value = !!response.data?.robots
      plans.value = !!response.data?.plans
    } catch {
      brokerAutoSync.value = false
      robots.value = false
      plans.value = false
    } finally {
      loaded.value = true
    }
  }

  return {
    brokerAutoSync,
    robots,
    plans,
    loaded,
    load,
  }
})
