import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import { useAuthStore } from '@/stores/auth'
import { authService } from '@/services/auth'
import LoginView from '@/views/LoginView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const push = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

vi.mock('@/services/auth', () => ({
  authService: {
    login: vi.fn(),
  },
}))

function mountView() {
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr, en } })
  return mount(LoginView, {
    global: {
      plugins: [i18n, PrimeVue],
      stubs: { RouterLink: { template: '<a><slot /></a>' } },
    },
  })
}

describe('LoginView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // Signing in works while /auth/refresh is rate-limited, but the next reload
  // restores nothing and lands here again. Without a word, it reads as a broken
  // login (production, 2026-09-21).
  it('says why the session could not be restored', () => {
    useAuthStore().restoreErrorKey = 'error.rate_limit_exceeded'

    const wrapper = mountView()

    expect(wrapper.text()).toContain(fr.error.rate_limit_exceeded)
  })

  it('shows no message on a plain visit', () => {
    const wrapper = mountView()

    expect(wrapper.text()).not.toContain(fr.error.rate_limit_exceeded)
  })

  it('replaces the restore message with the outcome of the next attempt', async () => {
    useAuthStore().restoreErrorKey = 'error.rate_limit_exceeded'
    authService.login.mockRejectedValue(Object.assign(new Error('auth.error.invalid_credentials'), {
      messageKey: 'auth.error.invalid_credentials',
    }))
    const wrapper = mountView()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).not.toContain(fr.error.rate_limit_exceeded)
    expect(wrapper.text()).toContain(fr.auth.error.invalid_credentials)
  })
})
