import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import AppLayout from '@/components/layout/AppLayout.vue'
import en from '@/locales/en.json'
import fr from '@/locales/fr.json'

// The header crams the page title next to the brand on the left and the
// locale/theme/help/avatar controls on the right. On a narrow (mobile)
// viewport a long page title must TRUNCATE rather than push the row and crush
// the right-hand controls. In a flexbox that only works when the title (and
// its container) can shrink (min-w-0) and the controls cannot (shrink-0).
// These tests pin those classes so the regression can't silently return.

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: { en, fr },
})

// Route carries a titleKey so the page-title span renders.
const mockRoute = { path: '/', meta: { titleKey: 'trades.title' } }

vi.mock('vue-router', () => ({
  useRoute: () => mockRoute,
  useRouter: () => ({ push: () => {} }),
  RouterLink: { template: '<a><slot /></a>' },
  RouterView: { template: '<div />' },
}))

const baseGlobal = {
  plugins: [i18n],
  stubs: {
    FlagIcon: true,
    BrandLogo: true,
    Button: true,
    Popover: {
      template: '<div><slot /></div>',
      methods: { toggle() {} },
    },
  },
  directives: { tooltip: () => {} },
}

function createWrapper() {
  return mount(AppLayout, { global: baseGlobal })
}

describe('AppLayout header — responsive title truncation', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
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
