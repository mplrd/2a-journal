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
    // Open in a new tab — Stripe blocks iframing via X-Frame-Options, and opening in a new tab
    // lets the user come back to our app without losing context.
    const response = await billingService.createPortalSession()
    if (response.data?.url) {
      window.open(response.data.url, '_blank', 'noopener,noreferrer')
    }
  }

  async function cancel() {
    await billingService.cancel()
    await fetchStatus()
  }

  async function reactivate() {
    await billingService.reactivate()
    await fetchStatus()
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
    cancel,
    reactivate,
    reset,
  }
})
