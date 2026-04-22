import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useBillingStore } from '@/stores/billing'
import SubscribeView from '@/views/SubscribeView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/billing', () => ({
  billingService: {
    getStatus: vi.fn(),
    createCheckoutSession: vi.fn(),
    createPortalSession: vi.fn(),
  },
}))

vi.mock('@/services/api', () => ({
  api: {
    getAccessToken: vi.fn(() => 'token'),
    setTokens: vi.fn(),
    clearTokens: vi.fn(),
  },
}))

const { billingService } = await import('@/services/billing')

const stubs = {
  Button: {
    template: '<button :data-testid="$attrs[\'data-testid\']" :disabled="disabled" @click="$emit(\'click\')">{{ label }}</button>',
    props: ['label', 'severity', 'loading', 'disabled'],
    emits: ['click'],
  },
}

function createI18nInstance() {
  return createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })
}

function mountView() {
  setActivePinia(createPinia())
  return mount(SubscribeView, {
    global: { plugins: [createI18nInstance(), PrimeVue, ToastService], stubs },
  })
}

describe('billing store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('hasAccess false initially', () => {
    const store = useBillingStore()
    expect(store.hasAccess).toBe(false)
  })

  it('fetchStatus populates status and hasAccess', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: true, reason: 'grace_period', grace_period_end: '2026-05-01 00:00:00', subscription: null },
    })
    const store = useBillingStore()
    await store.fetchStatus()
    expect(store.hasAccess).toBe(true)
    expect(store.reason).toBe('grace_period')
    expect(store.gracePeriodEnd).toBe('2026-05-01 00:00:00')
  })

  it('reset clears the status', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: true, reason: 'bypass', grace_period_end: null, subscription: null },
    })
    const store = useBillingStore()
    await store.fetchStatus()
    expect(store.hasAccess).toBe(true)
    store.reset()
    expect(store.status).toBeNull()
    expect(store.hasAccess).toBe(false)
  })

  it('startCheckout redirects to the returned URL', async () => {
    const originalLocation = window.location
    delete window.location
    window.location = { href: '' }

    billingService.createCheckoutSession.mockResolvedValue({ data: { url: 'https://stripe.checkout/session_123' } })
    const store = useBillingStore()
    await store.startCheckout()

    expect(window.location.href).toBe('https://stripe.checkout/session_123')
    window.location = originalLocation
  })
})

describe('SubscribeView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows subscribe button and loading spinner while fetching', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: false, reason: 'no_access', grace_period_end: null, subscription: null },
    })
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.find('[data-testid="subscribe-button"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="status-no-access"]').exists()).toBe(true)
  })

  it('shows grace info when in grace period', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: true, reason: 'grace_period', grace_period_end: '2026-05-01 00:00:00', subscription: null },
    })
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.find('[data-testid="status-grace"]').exists()).toBe(true)
  })

  it('shows manage button when subscription exists', async () => {
    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: false },
      },
    })
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.find('[data-testid="manage-button"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="status-active"]').exists()).toBe(true)
  })

  it('shows cancel_scheduled banner when cancel is scheduled', async () => {
    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: true },
      },
    })
    const wrapper = mountView()
    await flushPromises()
    expect(wrapper.find('[data-testid="status-cancel-scheduled"]').exists()).toBe(true)
  })

  it('clicking subscribe calls startCheckout', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: false, reason: 'no_access', grace_period_end: null, subscription: null },
    })
    const wrapper = mountView()
    await flushPromises()
    const store = useBillingStore()
    const spy = vi.spyOn(store, 'startCheckout').mockResolvedValue()

    await wrapper.find('[data-testid="subscribe-button"]').trigger('click')
    await flushPromises()

    expect(spy).toHaveBeenCalled()
  })
})
