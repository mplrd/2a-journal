import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useAuthStore } from '@/stores/auth'
import AccountView from '@/views/AccountView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const mockRoute = { query: {} }
const mockRouter = { push: vi.fn(), replace: vi.fn() }

vi.mock('vue-router', () => ({
  useRoute: () => mockRoute,
  useRouter: () => mockRouter,
}))

vi.mock('@/services/auth', () => ({
  authService: {
    updateProfile: vi.fn(),
  },
}))

vi.mock('@/services/setups', () => ({
  setupsService: {
    list: vi.fn().mockResolvedValue({ data: [] }),
    create: vi.fn(),
    remove: vi.fn(),
  },
}))

vi.mock('@/services/symbols', () => ({
  symbolsService: {
    list: vi.fn().mockResolvedValue({ data: [] }),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
  },
}))

function createWrapper(query = {}) {
  mockRoute.query = query

  const pinia = createPinia()
  setActivePinia(pinia)

  const authStore = useAuthStore()
  authStore.user = {
    id: 1,
    email: 'test@test.com',
    first_name: 'John',
    last_name: 'Doe',
    timezone: 'Europe/Paris',
    default_currency: 'EUR',
    theme: 'light',
    locale: 'fr',
    onboarding_completed_at: '2024-01-01',
  }

  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(AccountView, {
    global: {
      plugins: [pinia, i18n, PrimeVue, ToastService],
      stubs: {
        ProfileTab: { template: '<div data-testid="profile-tab">Profile</div>' },
        AssetsTab: { template: '<div data-testid="assets-tab">Assets</div>' },
        SetupsTab: { template: '<div data-testid="setups-tab">Setups</div>' },
        TabView: {
          template: '<div data-testid="tab-view"><slot /></div>',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
        TabPanel: {
          template: '<div :data-testid="`tab-panel-${value}`"><slot /></div>',
          props: ['header', 'value'],
        },
      },
    },
  })
}

describe('AccountView', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark-mode')
    mockRoute.query = {}
  })

  it('renders TabView with three tab panels', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-testid="tab-view"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-profile"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-assets"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-setups"]').exists()).toBe(true)
  })

  it('renders page title', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('h2').exists()).toBe(true)
  })

  it('has profile tab as default active', () => {
    const wrapper = createWrapper()
    expect(wrapper.vm.activeTab).toBe('profile')
  })

  it('activates assets tab from query param', () => {
    const wrapper = createWrapper({ tab: 'assets' })
    expect(wrapper.vm.activeTab).toBe('assets')
  })

  it('activates setups tab from query param', () => {
    const wrapper = createWrapper({ tab: 'setups' })
    expect(wrapper.vm.activeTab).toBe('setups')
  })

  it('defaults to profile for unknown tab query', () => {
    const wrapper = createWrapper({ tab: 'unknown' })
    expect(wrapper.vm.activeTab).toBe('profile')
  })
})
