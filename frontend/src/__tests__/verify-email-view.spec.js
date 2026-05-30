import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api'
import { authService } from '@/services/auth'
import VerifyEmailView from '@/views/VerifyEmailView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const mockRoute = { query: { token: 'valid-token' } }

vi.mock('vue-router', () => ({
  useRoute: () => mockRoute,
}))

vi.mock('@/services/api', () => ({
  api: {
    get: vi.fn(),
    getAccessToken: vi.fn(() => null),
  },
}))

vi.mock('@/services/auth', () => ({
  authService: {
    me: vi.fn(),
  },
}))

function mountView() {
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr, en } })
  return mount(VerifyEmailView, {
    global: {
      plugins: [i18n, PrimeVue],
      stubs: { RouterLink: { template: '<a><slot /></a>' } },
    },
  })
}

describe('VerifyEmailView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockRoute.query = { token: 'valid-token' }
  })

  it('refreshes the in-memory profile after a successful verification when a session is active', async () => {
    api.getAccessToken.mockReturnValue('token')
    api.get.mockResolvedValue({ success: true })
    // Stale profile: banner would still show email as unverified
    const store = useAuthStore()
    store.user = { id: 1, email_verified: false }
    authService.me.mockResolvedValue({ data: { id: 1, email_verified: true } })

    mountView()
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith(
      '/auth/verify-email?token=valid-token',
      { auth: false },
    )
    // Profile resynced so the verification banner disappears without a manual reload
    expect(authService.me).toHaveBeenCalled()
    expect(store.user.email_verified).toBe(true)
  })

  it('notifies other tabs after a successful verification', async () => {
    api.getAccessToken.mockReturnValue('token')
    api.get.mockResolvedValue({ success: true })
    const store = useAuthStore()
    authService.me.mockResolvedValue({ data: { id: 1, email_verified: true } })

    const otherTab = new BroadcastChannel('auth')
    const received = []
    otherTab.onmessage = (event) => received.push(event.data)

    mountView()
    await flushPromises()
    await new Promise((resolve) => setTimeout(resolve, 20))
    otherTab.close()

    expect(received).toContainEqual({ type: 'email-verified' })
  })

  it('does not attempt to refresh the profile when there is no active session', async () => {
    api.getAccessToken.mockReturnValue(null)
    api.get.mockResolvedValue({ success: true })

    mountView()
    await flushPromises()

    expect(api.get).toHaveBeenCalled()
    expect(authService.me).not.toHaveBeenCalled()
  })
})
