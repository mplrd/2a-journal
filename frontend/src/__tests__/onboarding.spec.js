import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useOnboarding } from '@/composables/useOnboarding'
import { useAuthStore } from '@/stores/auth'
import { useAccountsStore } from '@/stores/accounts'
import { authService } from '@/services/auth'

vi.mock('@/services/auth', () => ({
  authService: {
    register: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
    me: vi.fn(),
    updateLocale: vi.fn(),
    updateProfile: vi.fn(),
    uploadProfilePicture: vi.fn(),
    completeOnboarding: vi.fn(),
  },
}))

vi.mock('@/services/accounts', () => ({
  accountsService: {
    list: vi.fn().mockResolvedValue({ success: true, data: [] }),
    get: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
  },
}))

vi.mock('@/services/api', () => ({
  api: {
    getAccessToken: vi.fn(() => 'token'),
    setTokens: vi.fn(),
    clearTokens: vi.fn(),
    refreshAccessToken: vi.fn(),
  },
}))

describe('useOnboarding', () => {
  let authStore
  let accountsStore

  beforeEach(() => {
    setActivePinia(createPinia())
    authStore = useAuthStore()
    accountsStore = useAccountsStore()
    vi.restoreAllMocks()
  })

  describe('isOnboarding', () => {
    it('returns false when user is null', () => {
      authStore.user = null
      const { isOnboarding } = useOnboarding()
      expect(isOnboarding.value).toBe(false)
    })

    it('returns true when onboarding_completed_at is null', () => {
      authStore.user = { id: 1, email: 'test@test.com', onboarding_completed_at: null }
      const { isOnboarding } = useOnboarding()
      expect(isOnboarding.value).toBe(true)
    })

    it('returns false when onboarding_completed_at is set', () => {
      authStore.user = { id: 1, email: 'test@test.com', onboarding_completed_at: '2024-01-01 00:00:00' }
      const { isOnboarding } = useOnboarding()
      expect(isOnboarding.value).toBe(false)
    })
  })

  describe('currentStep', () => {
    it('returns accounts when no accounts exist', () => {
      authStore.user = { id: 1, onboarding_completed_at: null }
      accountsStore.accounts = []
      const { currentStep } = useOnboarding()
      expect(currentStep.value).toBe('accounts')
    })

    it('returns symbols when accounts exist', () => {
      authStore.user = { id: 1, onboarding_completed_at: null }
      accountsStore.accounts = [{ id: 1, name: 'Test' }]
      const { currentStep } = useOnboarding()
      expect(currentStep.value).toBe('symbols')
    })

    it('returns null when onboarding is complete', () => {
      authStore.user = { id: 1, onboarding_completed_at: '2024-01-01 00:00:00' }
      const { currentStep } = useOnboarding()
      expect(currentStep.value).toBeNull()
    })
  })

  describe('isRouteAllowed', () => {
    it('allows all routes when onboarding is complete', () => {
      authStore.user = { id: 1, onboarding_completed_at: '2024-01-01 00:00:00' }
      const { isRouteAllowed } = useOnboarding()
      expect(isRouteAllowed('dashboard')).toBe(true)
      expect(isRouteAllowed('accounts')).toBe(true)
      expect(isRouteAllowed('symbols')).toBe(true)
      expect(isRouteAllowed('trades')).toBe(true)
    })

    it('allows only accounts and account at accounts step', () => {
      authStore.user = { id: 1, onboarding_completed_at: null }
      accountsStore.accounts = []
      const { isRouteAllowed } = useOnboarding()
      expect(isRouteAllowed('accounts')).toBe(true)
      expect(isRouteAllowed('account')).toBe(true)
      expect(isRouteAllowed('dashboard')).toBe(false)
      expect(isRouteAllowed('symbols')).toBe(false)
      expect(isRouteAllowed('trades')).toBe(false)
      expect(isRouteAllowed('orders')).toBe(false)
      expect(isRouteAllowed('positions')).toBe(false)
    })

    it('allows accounts, symbols and account at symbols step', () => {
      authStore.user = { id: 1, onboarding_completed_at: null }
      accountsStore.accounts = [{ id: 1 }]
      const { isRouteAllowed } = useOnboarding()
      expect(isRouteAllowed('accounts')).toBe(true)
      expect(isRouteAllowed('symbols')).toBe(true)
      expect(isRouteAllowed('account')).toBe(true)
      expect(isRouteAllowed('dashboard')).toBe(false)
      expect(isRouteAllowed('trades')).toBe(false)
      expect(isRouteAllowed('orders')).toBe(false)
      expect(isRouteAllowed('positions')).toBe(false)
    })
  })

  describe('completeOnboarding', () => {
    it('calls API and updates user', async () => {
      const updatedUser = { id: 1, onboarding_completed_at: '2024-01-01 00:00:00' }
      authService.completeOnboarding.mockResolvedValue({ data: updatedUser })
      authStore.user = { id: 1, onboarding_completed_at: null }

      const { completeOnboarding } = useOnboarding()
      await completeOnboarding()

      expect(authService.completeOnboarding).toHaveBeenCalled()
      expect(authStore.user).toEqual(updatedUser)
    })
  })
})
