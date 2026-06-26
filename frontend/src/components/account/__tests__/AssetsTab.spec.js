import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/stores/symbols', () => ({
  useSymbolsStore: () => ({
    symbols: [{ id: 1, ticker: 'BTCUSDT', name: 'Bitcoin', type: 'CRYPTO' }],
    loading: false,
    fetchSymbols: vi.fn(),
    createSymbol: vi.fn(),
    updateSymbol: vi.fn(),
    deleteSymbol: vi.fn(),
  }),
}))

vi.mock('@/stores/accounts', () => ({
  useAccountsStore: () => ({
    accounts: [{ id: 10, name: 'FTMO', currency: 'EUR' }],
    fetchAccounts: vi.fn(),
  }),
}))

vi.mock('@/stores/symbolAccountSettings', () => ({
  useSymbolAccountSettingsStore: () => ({
    fetchMatrix: vi.fn(),
    getPointValue: () => null,
    save: vi.fn(),
  }),
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }))

import AssetsTab from '../AssetsTab.vue'

async function createWrapper() {
  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })
  const wrapper = mount(AssetsTab, {
    global: {
      plugins: [i18n, PrimeVue],
      directives: { tooltip: {} },
      stubs: {
        Button: { template: '<button><slot />{{ label }}</button>', props: ['label'] },
        InputNumber: { template: '<input />' },
        Dialog: { template: '<div><slot /></div>' },
        SymbolForm: { template: '<div />' },
        Tag: { template: '<span><slot />{{ value }}</span>', props: ['value'] },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('AssetsTab — point-value help hint (#11)', () => {
  it('renders an info hint next to the point-value group header', async () => {
    const wrapper = await createWrapper()
    const info = wrapper.find('[data-testid="point-value-help"]')
    expect(info.exists()).toBe(true)
    expect(info.attributes('aria-label')).toBe(fr.symbols.point_value_help)
  })

  it('the hint text mentions crypto contract size', async () => {
    const wrapper = await createWrapper()
    expect(fr.symbols.point_value_help.toLowerCase()).toContain('crypto')
    expect(fr.symbols.point_value_help.toLowerCase()).toContain('contrat')
  })
})
