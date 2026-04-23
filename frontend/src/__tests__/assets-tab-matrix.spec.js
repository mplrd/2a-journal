import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useSymbolsStore } from '@/stores/symbols'
import { useAccountsStore } from '@/stores/accounts'
import AssetsTab from '@/components/account/AssetsTab.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/symbols', () => ({
  symbolsService: {
    list: vi.fn().mockResolvedValue({ data: [] }),
    settings: vi.fn().mockResolvedValue({ success: true, data: { settings: [] } }),
    setSetting: vi.fn().mockResolvedValue({ success: true }),
    clearSetting: vi.fn().mockResolvedValue({ success: true }),
  },
}))

vi.mock('@/services/accounts', () => ({
  accountsService: {
    list: vi.fn().mockResolvedValue({ success: true, data: [] }),
  },
}))

vi.mock('@/services/api', () => ({
  api: {
    getAccessToken: vi.fn(() => 'token'),
    setTokens: vi.fn(),
    clearTokens: vi.fn(),
  },
}))

const { symbolsService } = await import('@/services/symbols')

const stubs = {
  InputNumber: {
    template:
      '<input type="number" :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : Number($event.target.value))" @blur="$emit(\'blur\')" />',
    props: ['modelValue', 'min', 'maxFractionDigits', 'disabled', 'mode', 'locale'],
    emits: ['update:modelValue', 'blur'],
    inheritAttrs: true,
  },
  Button: {
    template: '<button :data-testid="$attrs[\'data-testid\']" @click="$emit(\'click\')">{{ label || icon }}</button>',
    props: ['label', 'icon', 'severity', 'size', 'text', 'loading'],
    emits: ['click'],
  },
  Tag: {
    template: '<span class="tag">{{ value }}</span>',
    props: ['value', 'severity'],
  },
  Dialog: {
    template: '<div v-if="visible"><slot /><slot name="footer" /></div>',
    props: ['visible', 'header', 'modal', 'closable', 'style'],
    emits: ['update:visible'],
  },
  SymbolForm: { template: '<div data-testid="symbol-form-stub"></div>' },
}

function createI18nInstance() {
  return createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })
}

function mountTab({ symbols = [], accounts = [], settings = [] } = {}) {
  setActivePinia(createPinia())

  const accStore = useAccountsStore()
  accStore.accounts = accounts
  accStore.loaded = true

  // fetchSymbols(true) is called in onMounted and it force-refetches via the service,
  // overwriting any value we'd set directly on the store. Make the mocked service
  // return the fixtures instead.
  symbolsService.list.mockResolvedValue({ data: symbols })

  symbolsService.settings.mockResolvedValue({
    success: true,
    data: { settings },
  })

  return mount(AssetsTab, {
    global: { plugins: [createI18nInstance(), PrimeVue, ToastService], stubs },
  })
}

describe('AssetsTab — unified matrix', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders an empty state when there are no symbols', async () => {
    const wrapper = mountTab({ symbols: [], accounts: [{ id: 1, name: 'A', currency: 'EUR' }] })
    await flushPromises()
    expect(wrapper.text()).toContain(fr.symbols.empty)
    expect(wrapper.find('[data-testid="assets-matrix-table"]').exists()).toBe(false)
  })

  it('renders the unified table with ticker, name, type and one column per account', async () => {
    const wrapper = mountTab({
      symbols: [{ id: 1, code: 'NASDAQ', name: 'Nasdaq', type: 'INDEX' }],
      accounts: [
        { id: 10, name: 'FTMO 10k', currency: 'EUR' },
        { id: 20, name: 'Perso', currency: 'USD' },
      ],
    })
    await flushPromises()
    expect(wrapper.find('[data-testid="assets-matrix-table"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="col-account-10"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="col-account-20"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cell-1-10"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cell-1-20"]').exists()).toBe(true)
  })

  it('shows the account currency in the column header', async () => {
    const wrapper = mountTab({
      symbols: [{ id: 1, code: 'NASDAQ', name: 'Nasdaq', type: 'INDEX' }],
      accounts: [{ id: 10, name: 'FTMO 10k', currency: 'EUR' }],
    })
    await flushPromises()
    const header = wrapper.find('[data-testid="col-account-10"]')
    expect(header.text()).toContain('FTMO 10k')
    expect(header.text()).toContain('EUR')
  })

  it('renders a two-level header with "Valeur du point" group spanning all account columns', async () => {
    const wrapper = mountTab({
      symbols: [{ id: 1, code: 'NASDAQ', name: 'Nasdaq', type: 'INDEX' }],
      accounts: [
        { id: 10, name: 'FTMO 10k', currency: 'EUR' },
        { id: 20, name: 'FTMO 100k', currency: 'EUR' },
        { id: 30, name: 'Perso', currency: 'USD' },
      ],
    })
    await flushPromises()
    const groupHeader = wrapper.find('[data-testid="header-group-point-value"]')
    expect(groupHeader.exists()).toBe(true)
    expect(groupHeader.text()).toContain(fr.symbols.point_value)
    // Spans the 3 account columns
    expect(groupHeader.attributes('colspan')).toBe('3')
  })

  it('hides the "Valeur du point" group header when there are no accounts', async () => {
    const wrapper = mountTab({
      symbols: [{ id: 1, code: 'NASDAQ', name: 'Nasdaq', type: 'INDEX' }],
      accounts: [],
    })
    await flushPromises()
    expect(wrapper.find('[data-testid="header-group-point-value"]').exists()).toBe(false)
  })

  it('prefills the input with the saved point_value', async () => {
    const wrapper = mountTab({
      symbols: [{ id: 1, code: 'NASDAQ', name: 'Nasdaq', type: 'INDEX' }],
      accounts: [{ id: 10, name: 'A', currency: 'EUR' }],
      settings: [{ symbol_id: 1, account_id: 10, point_value: 20 }],
    })
    await flushPromises()
    const input = wrapper.find('[data-testid="input-1-10"]')
    expect(Number(input.element.value)).toBe(20)
  })

  it('persists the cell on blur when value changed', async () => {
    const wrapper = mountTab({
      symbols: [{ id: 1, code: 'NASDAQ', name: 'Nasdaq', type: 'INDEX' }],
      accounts: [{ id: 10, name: 'A', currency: 'EUR' }],
      settings: [{ symbol_id: 1, account_id: 10, point_value: 20 }],
    })
    await flushPromises()
    const input = wrapper.find('[data-testid="input-1-10"]')
    await input.setValue('2.5')
    await input.trigger('blur')
    await flushPromises()
    expect(symbolsService.setSetting).toHaveBeenCalledWith(1, 10, 2.5)
  })

  it('does not call API on blur when value did not change', async () => {
    const wrapper = mountTab({
      symbols: [{ id: 1, code: 'NASDAQ', name: 'Nasdaq', type: 'INDEX' }],
      accounts: [{ id: 10, name: 'A', currency: 'EUR' }],
      settings: [{ symbol_id: 1, account_id: 10, point_value: 20 }],
    })
    await flushPromises()
    const input = wrapper.find('[data-testid="input-1-10"]')
    await input.trigger('blur')
    await flushPromises()
    expect(symbolsService.setSetting).not.toHaveBeenCalled()
  })
})
