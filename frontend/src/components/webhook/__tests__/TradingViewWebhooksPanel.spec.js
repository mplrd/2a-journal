import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import ConfirmationService from 'primevue/confirmationservice'
import ToastService from 'primevue/toastservice'
import TradingViewWebhooksPanel from '../TradingViewWebhooksPanel.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/webhooks', () => ({
  webhooksService: {
    list: vi.fn(),
    create: vi.fn(),
    revoke: vi.fn(),
    events: vi.fn(),
  },
}))

import { webhooksService } from '@/services/webhooks'

function createWrapper(props = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(TradingViewWebhooksPanel, {
    props: {
      account: { id: 42, name: 'Test Account' },
      ...props,
    },
    global: {
      plugins: [i18n, PrimeVue, ConfirmationService, ToastService],
      stubs: {
        Dialog: {
          template: '<div v-if="visible" class="dialog-stub" :data-testid="$attrs[`data-testid`]"><slot /><slot name="footer" /></div>',
          props: ['visible', 'header', 'modal'],
        },
        Button: {
          template: '<button :data-testid="$attrs[`data-testid`]" :disabled="disabled" @click="$emit(\'click\', $event)">{{ label }}</button>',
          props: ['label', 'icon', 'severity', 'loading', 'disabled', 'text', 'size'],
        },
        InputText: {
          template: '<input :data-testid="$attrs[`data-testid`]" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue', 'placeholder'],
          emits: ['update:modelValue'],
        },
        Textarea: {
          template: '<textarea :data-testid="$attrs[`data-testid`]" :value="modelValue" />',
          props: ['modelValue'],
        },
        Tag: { template: '<span class="tag">{{ value }}</span>', props: ['value', 'severity'] },
        DataTable: { template: '<table><slot /></table>', props: ['value'] },
        Column: { template: '<th><slot /></th>' },
      },
    },
  })
}

describe('TradingViewWebhooksPanel', () => {
  beforeEach(() => {
    webhooksService.list.mockReset()
    webhooksService.create.mockReset()
    webhooksService.revoke.mockReset()
    webhooksService.events.mockReset()
  })

  it('shows empty state when no webhooks', async () => {
    webhooksService.list.mockResolvedValue({ data: [] })
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.text()).toContain('Aucun webhook configuré')
  })

  it('lists existing webhooks with their counters', async () => {
    webhooksService.list.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Scalper EU',
          status: 'ACTIVE',
          last_triggered_at: '2026-05-13 12:00:00',
          total_triggered: 7,
          total_errors: 1,
        },
      ],
    })
    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.text()).toContain('Scalper EU')
    expect(wrapper.text()).toContain('7 succès')
    expect(wrapper.text()).toContain('1 erreur(s)')
  })

  it('disables create button when name is empty', async () => {
    webhooksService.list.mockResolvedValue({ data: [] })
    const wrapper = createWrapper()
    await flushPromises()

    const btn = wrapper.find('[data-testid="webhook-create-btn"]')
    expect(btn.attributes('disabled')).toBeDefined()
  })

  it('calls create then opens the one-shot modal with URL+secret+template', async () => {
    webhooksService.list.mockResolvedValue({ data: [] })
    webhooksService.create.mockResolvedValue({
      data: {
        webhook: { id: 9, name: 'New', status: 'ACTIVE' },
        url: 'http://test/api/webhooks/tradingview/ABC',
        body_secret: 'SECRET-XYZ',
        template: { secret: 'SECRET-XYZ', symbol: '{{ticker}}', direction: 'BUY' },
      },
    })
    const wrapper = createWrapper()
    await flushPromises()

    await wrapper.find('[data-testid="webhook-name-input"]').setValue('New')
    await wrapper.find('[data-testid="webhook-create-btn"]').trigger('click')
    await flushPromises()

    expect(webhooksService.create).toHaveBeenCalledWith(42, 'New')

    // Modal opened with the credentials visible exactly once.
    const modal = wrapper.find('[data-testid="webhook-created-modal"]')
    expect(modal.exists()).toBe(true)
    expect(modal.html()).toContain('http://test/api/webhooks/tradingview/ABC')
    expect(modal.html()).toContain('SECRET-XYZ')
  })
})
