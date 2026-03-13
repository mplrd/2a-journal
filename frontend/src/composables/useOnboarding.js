import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useAccountsStore } from '@/stores/accounts'
import { authService } from '@/services/auth'

const STEP_ROUTES = {
  accounts: ['accounts', 'account'],
  symbols: ['accounts', 'account'],
}

export function useOnboarding() {
  const authStore = useAuthStore()
  const accountsStore = useAccountsStore()

  const isOnboarding = computed(() => {
    return !!authStore.user && authStore.user.onboarding_completed_at === null
  })

  const currentStep = computed(() => {
    if (!isOnboarding.value) return null
    if (accountsStore.accounts.length === 0) return 'accounts'
    return 'symbols'
  })

  const onboardingRoute = computed(() => {
    if (!currentStep.value) return null
    if (currentStep.value === 'symbols') {
      return { name: 'account', query: { tab: 'assets' } }
    }
    return { name: currentStep.value }
  })

  function isRouteAllowed(routeName) {
    if (!isOnboarding.value) return true
    const step = currentStep.value
    if (!step) return true
    const allowed = STEP_ROUTES[step]
    return allowed ? allowed.includes(routeName) : true
  }

  async function completeOnboarding() {
    const response = await authService.completeOnboarding()
    authStore.user = response.data
  }

  async function ensureAccountsLoaded() {
    if (!accountsStore.loaded) {
      await accountsStore.fetchAccounts()
    }
  }

  return {
    isOnboarding,
    currentStep,
    onboardingRoute,
    isRouteAllowed,
    completeOnboarding,
    ensureAccountsLoaded,
  }
}
