import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'

// The header crams the page title next to the brand on the left and the
// locale/theme/help/avatar controls on the right. On a narrow (mobile)
// viewport a long page title must TRUNCATE rather than push the row and crush
// the right-hand controls. In a flexbox that only works when the title (and
// its container) can shrink (min-w-0) and the controls cannot (shrink-0).
// These tests pin those classes so the regression can't silently return.

// Route carries a titleKey so the page-title span renders.
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useRoute: () => ({ path: '/', meta: { titleKey: 'trades.title' } }),
}))

import AppLayout from '../components/layout/AppLayout.vue'
import fr from '../locales/fr.json'
import en from '../locales/en.json'

function createWrapper(locale = 'fr') {
  const i18n = createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(AppLayout, {
    global: {
      plugins: [createPinia(), i18n, PrimeVue, ToastService],
      stubs: {
        RouterView: true,
        RouterLink: true,
        Popover: {
          template: '<div><slot /></div>',
          methods: { toggle() {} },
        },
        FlagIcon: true,
        BrandLogo: true,
      },
    },
  })
}

describe('AppLayout header — responsive title truncation', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.stubGlobal('innerWidth', 375) // mobile width
  })

  it('lets the page title truncate (truncate + min-w-0)', () => {
    const title = createWrapper().find('[data-testid="page-title"]')
    expect(title.exists()).toBe(true)
    expect(title.classes()).toContain('truncate')
    expect(title.classes()).toContain('min-w-0')
  })

  it('uses a smaller title font on mobile, larger from sm up', () => {
    const title = createWrapper().find('[data-testid="page-title"]')
    expect(title.classes()).toContain('text-base')
    expect(title.classes()).toContain('sm:text-lg')
  })

  it('keeps the right-hand controls from being compressed (shrink-0)', () => {
    const userMenu = createWrapper().find('[data-testid="user-menu-trigger"]')
    expect(userMenu.exists()).toBe(true)
    // The avatar/user-menu trigger lives in the right-hand control cluster,
    // whose flex row must not shrink.
    expect(userMenu.element.parentElement.className).toContain('shrink-0')
  })
})
