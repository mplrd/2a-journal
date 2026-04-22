import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useBillingStore } from '@/stores/billing'
import BillingTab from '@/components/account/BillingTab.vue'
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
    props: ['label', 'severity', 'loading', 'disabled', 'icon', 'outlined'],
    emits: ['click'],
  },
  Dialog: {
    template: '<div v-if="visible" :data-testid="$attrs[\'data-testid\'] || \'dialog\'"><slot /><slot name="footer" /></div>',
    props: ['visible', 'header', 'modal', 'closable', 'style'],
    emits: ['update:visible'],
  },
}

function createI18nInstance() {
  return createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })
}

function mountTab() {
  setActivePinia(createPinia())
  return mount(BillingTab, {
    global: { plugins: [createI18nInstance(), PrimeVue, ToastService], stubs },
  })
}

describe('BillingTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows grace banner during grace period', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: true, reason: 'grace_period', grace_period_end: '2026-05-01 00:00:00', subscription: null },
    })
    const wrapper = mountTab()
    await flushPromises()
    expect(wrapper.find('[data-testid="status-grace"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="subscribe-button"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="manage-button"]').exists()).toBe(false)
  })

  it('shows active status and manage button when subscription is active', async () => {
    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: false },
      },
    })
    const wrapper = mountTab()
    await flushPromises()
    expect(wrapper.find('[data-testid="status-active"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="manage-button"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="subscribe-button"]').exists()).toBe(false)
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
    const wrapper = mountTab()
    await flushPromises()
    expect(wrapper.find('[data-testid="status-cancel-scheduled"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="manage-button"]').exists()).toBe(true)
  })

  it('shows bypass status and no action buttons for exempt user', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: true, reason: 'bypass', grace_period_end: null, subscription: null },
    })
    const wrapper = mountTab()
    await flushPromises()
    expect(wrapper.find('[data-testid="status-bypass"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="subscribe-button"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="manage-button"]').exists()).toBe(false)
  })

  it('shows no-access banner and subscribe button when grace expired', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: false, reason: 'no_access', grace_period_end: null, subscription: null },
    })
    const wrapper = mountTab()
    await flushPromises()
    expect(wrapper.find('[data-testid="status-no-access"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="subscribe-button"]').exists()).toBe(true)
  })

  it('clicking manage calls billingStore.openPortal', async () => {
    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: false },
      },
    })
    const wrapper = mountTab()
    await flushPromises()
    const store = useBillingStore()
    const spy = vi.spyOn(store, 'openPortal').mockResolvedValue()
    await wrapper.find('[data-testid="manage-button"]').trigger('click')
    await flushPromises()
    expect(spy).toHaveBeenCalled()
  })

  it('clicking subscribe calls billingStore.startCheckout', async () => {
    billingService.getStatus.mockResolvedValue({
      data: { has_access: false, reason: 'no_access', grace_period_end: null, subscription: null },
    })
    const wrapper = mountTab()
    await flushPromises()
    const store = useBillingStore()
    const spy = vi.spyOn(store, 'startCheckout').mockResolvedValue()
    await wrapper.find('[data-testid="subscribe-button"]').trigger('click')
    await flushPromises()
    expect(spy).toHaveBeenCalled()
  })

  it('shows cancel button when subscription active, reactivate when cancel scheduled', async () => {
    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: false },
      },
    })
    const wrapper1 = mountTab()
    await flushPromises()
    expect(wrapper1.find('[data-testid="cancel-button"]').exists()).toBe(true)
    expect(wrapper1.find('[data-testid="reactivate-button"]').exists()).toBe(false)

    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: true },
      },
    })
    const wrapper2 = mountTab()
    await flushPromises()
    expect(wrapper2.find('[data-testid="cancel-button"]').exists()).toBe(false)
    expect(wrapper2.find('[data-testid="reactivate-button"]').exists()).toBe(true)
  })

  it('cancel button opens confirmation dialog, confirm calls billing.cancel', async () => {
    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: false },
      },
    })
    const wrapper = mountTab()
    await flushPromises()
    const store = useBillingStore()
    const spy = vi.spyOn(store, 'cancel').mockResolvedValue()

    await wrapper.find('[data-testid="cancel-button"]').trigger('click')
    await flushPromises()
    // Dialog renders with the confirm button inside its footer slot
    const confirm = wrapper.find('[data-testid="confirm-cancel-button"]')
    expect(confirm.exists()).toBe(true)

    await confirm.trigger('click')
    await flushPromises()
    expect(spy).toHaveBeenCalled()
  })

  it('reactivate button calls billing.reactivate', async () => {
    billingService.getStatus.mockResolvedValue({
      data: {
        has_access: true,
        reason: 'subscription_active',
        grace_period_end: null,
        subscription: { status: 'active', current_period_end: '2026-06-01 00:00:00', cancel_at_period_end: true },
      },
    })
    const wrapper = mountTab()
    await flushPromises()
    const store = useBillingStore()
    const spy = vi.spyOn(store, 'reactivate').mockResolvedValue()
    await wrapper.find('[data-testid="reactivate-button"]').trigger('click')
    await flushPromises()
    expect(spy).toHaveBeenCalled()
  })
})
