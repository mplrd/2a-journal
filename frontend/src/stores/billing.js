import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { billingService } from '@/services/billing'

export const useBillingStore = defineStore('billing', () => {
  const status = ref(null)
  const loading = ref(false)

  const hasAccess = computed(() => status.value?.has_access === true)
  const reason = computed(() => status.value?.reason ?? null)
  const gracePeriodEnd = computed(() => status.value?.grace_period_end ?? null)
  const subscription = computed(() => status.value?.subscription ?? null)

  async function fetchStatus() {
    loading.value = true
    try {
      const response = await billingService.getStatus()
      status.value = response.data
    } finally {
      loading.value = false
    }
  }

  async function startCheckout() {
    const response = await billingService.createCheckoutSession()
    if (response.data?.url) {
      window.location.href = response.data.url
    }
  }

  async function openPortal() {
    const response = await billingService.createPortalSession()
    if (response.data?.url) {
      window.location.href = response.data.url
    }
  }

  function reset() {
    status.value = null
  }

  return {
    status,
    loading,
    hasAccess,
    reason,
    gracePeriodEnd,
    subscription,
    fetchStatus,
    startCheckout,
    openPortal,
    reset,
  }
})
