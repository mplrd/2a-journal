import { defineStore } from 'pinia'
import { ref } from 'vue'
import { featuresService } from '@/services/features'

export const useFeaturesStore = defineStore('features', () => {
  const brokerAutoSync = ref(false)
  const tradingviewWebhooks = ref(false)
  const loaded = ref(false)

  async function load() {
    if (loaded.value) return
    try {
      const response = await featuresService.get()
      brokerAutoSync.value = !!response.data?.broker_auto_sync
      tradingviewWebhooks.value = !!response.data?.tradingview_webhooks
    } catch {
      brokerAutoSync.value = false
      tradingviewWebhooks.value = false
    } finally {
      loaded.value = true
    }
  }

  return {
    brokerAutoSync,
    tradingviewWebhooks,
    loaded,
    load,
  }
})
