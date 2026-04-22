import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useAuthStore } from '@/stores/auth'
import { useAccountsStore } from '@/stores/accounts'
import AccountView from '@/views/AccountView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const mockRoute = { query: {} }
const mockRouter = { push: vi.fn(), replace: vi.fn() }

vi.mock('vue-router', () => ({
  useRoute: () => mockRoute,
  useRouter: () => mockRouter,
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

vi.mock('@/services/auth', () => ({
  authService: {
    updateProfile: vi.fn(),
    completeOnboarding: vi.fn(),
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

const stubs = {
  ProfileTab: { template: '<div data-testid="profile-tab">Profile</div>' },
  PreferencesTab: { template: '<div data-testid="preferences-tab">Preferences</div>' },
  BillingTab: { template: '<div data-testid="billing-tab">Billing</div>' },
  AssetsTab: { template: '<div data-testid="assets-tab">Assets</div>' },
  SetupsTab: { template: '<div data-testid="setups-tab">Setups</div>' },
  Tabs: {
    template: '<div data-testid="tabs"><slot /></div>',
    props: ['value'],
    emits: ['update:value'],
  },
  TabList: { template: '<div data-testid="tab-list"><slot /></div>' },
  Tab: {
    template: '<div :data-testid="`tab-${value}`"><slot /></div>',
    props: ['value'],
  },
  TabPanels: { template: '<div data-testid="tab-panels"><slot /></div>' },
  TabPanel: {
    template: '<div :data-testid="`tab-panel-${value}`"><slot /></div>',
    props: ['value'],
  },
  Button: {
    template: '<button :data-testid="$attrs[\'data-testid\']" @click="$emit(\'click\')">{{ label }}</button>',
    props: ['label', 'icon'],
    emits: ['click'],
  },
}

function createWrapper(query = {}, { onboardingCompleted = true, hasAccounts = false } = {}) {
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
    onboarding_completed_at: onboardingCompleted ? '2024-01-01' : null,
  }

  if (hasAccounts) {
    const accountsStore = useAccountsStore()
    accountsStore.accounts = [{ id: 1, name: 'Test Account' }]
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
      stubs,
    },
  })
}

describe('AccountView', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark-mode')
    mockRoute.query = {}
  })

  it('renders Tabs with all tab panels including preferences and billing', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-testid="tabs"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-profile"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-preferences"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-billing"]').exists()).toBe(true)
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

  it('activates preferences tab from query param', () => {
    const wrapper = createWrapper({ tab: 'preferences' })
    expect(wrapper.vm.activeTab).toBe('preferences')
  })

  it('activates billing tab from query param', () => {
    const wrapper = createWrapper({ tab: 'billing' })
    expect(wrapper.vm.activeTab).toBe('billing')
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

  it('shows onboarding banner during onboarding', () => {
    const wrapper = createWrapper({}, { onboardingCompleted: false })
    expect(wrapper.find('[data-testid="onboarding-banner"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="start-trading-btn"]').exists()).toBe(true)
  })

  it('hides onboarding banner when onboarding completed', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-testid="onboarding-banner"]').exists()).toBe(false)
  })

  it('shows all three tabs during onboarding', () => {
    const wrapper = createWrapper({}, { onboardingCompleted: false })
    expect(wrapper.find('[data-testid="tab-panel-profile"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-assets"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tab-panel-setups"]').exists()).toBe(true)
  })
})
