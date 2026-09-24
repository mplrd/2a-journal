import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const toastAdd = vi.fn()

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useRoute: () => ({ path: '/login', query: {} }),
}))

vi.mock('primevue/usetoast', () => ({
  useToast: () => ({ add: toastAdd }),
}))

function mountView() {
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr, en } })
  return mount(LoginView, { global: { plugins: [i18n, PrimeVue] } })
}

describe('LoginView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  // Signing in works while /auth/refresh is rate-limited, but the next reload
  // restores nothing and lands here again. Without a word, it reads as a broken
  // login (production, 2026-09-21).
  it('says why the session could not be restored', async () => {
    useAuthStore().restoreErrorKey = 'error.rate_limit_exceeded'

    mountView()
    await flushPromises()

    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({
      severity: 'error',
      detail: fr.error.rate_limit_exceeded,
    }))
  })

  it('stays silent on a plain visit', async () => {
    mountView()
    await flushPromises()

    expect(toastAdd).not.toHaveBeenCalled()
  })
})
