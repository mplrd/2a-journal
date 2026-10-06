import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import { ref, computed } from 'vue'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import Tooltip from 'primevue/tooltip'
import AccountsView from '@/views/AccountsView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const toastAdd = vi.fn()
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: toastAdd }) }))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}))

const layout = ref('desktop')
vi.mock('@/composables/useIsMobile', () => ({
  useLayout: () => ({
    isMobile: computed(() => layout.value === 'mobile'),
    isCompact: computed(() => layout.value === 'compact'),
  }),
}))

const account = {
  id: 7, name: 'PF FTMO', account_type: 'PROP_FIRM', stage: 'CHALLENGE', currency: 'EUR',
  initial_capital: '10000.00', current_capital: '9868.55', broker: 'FTMO', broker_balance: null,
}

vi.mock('@/services/accounts', () => ({
  accountsService: {
    list: vi.fn(() => Promise.resolve({ success: true, data: [account] })),
    exportXlsx: vi.fn(() => Promise.resolve()),
  },
}))

vi.mock('@/services/features', () => ({
  featuresService: { get: vi.fn().mockResolvedValue({ data: {} }) },
}))

import { accountsService } from '@/services/accounts'

const stubs = {
  AccountForm: true,
  AdjustBalanceDialog: true,
  ImportDialog: true,
  BrokerConnectionPanel: true,
  Dialog: true,
  FloatingActionButton: true,
}

function mountView() {
  const i18n = createI18n({ legacy: false, locale: 'fr', messages: { fr, en } })
  return mount(AccountsView, {
    global: {
      plugins: [i18n, PrimeVue, ToastService, ConfirmationService],
      directives: { tooltip: Tooltip },
      stubs,
    },
  })
}

describe('AccountsView — Excel export', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    layout.value = 'desktop'
  })

  it('exports the account from its row action', async () => {
    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('[data-testid="export-account-7"]').trigger('click')
    await flushPromises()

    expect(accountsService.exportXlsx).toHaveBeenCalledWith(expect.objectContaining({ id: 7, name: 'PF FTMO' }))
    expect(toastAdd).not.toHaveBeenCalled()
  })

  it('says why when the export fails', async () => {
    accountsService.exportXlsx.mockRejectedValueOnce(Object.assign(new Error('x'), { messageKey: 'accounts.error.not_found' }))
    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('[data-testid="export-account-7"]').trigger('click')
    await flushPromises()

    expect(toastAdd).toHaveBeenCalledWith(expect.objectContaining({
      severity: 'error',
      detail: fr.accounts.error.not_found,
    }))
  })

  it('offers the export in the mobile action menu', async () => {
    layout.value = 'mobile'
    const wrapper = mountView()
    await flushPromises()

    await wrapper.find('[aria-label="' + fr.common.more + '"]').trigger('click')
    await flushPromises()

    const items = wrapper.findComponent({ name: 'Menu' }).props('model')
    const exportItem = items.find((item) => item.label === fr.accounts.export_xlsx)
    expect(exportItem).toBeDefined()
    exportItem.command()
    await flushPromises()
    expect(accountsService.exportXlsx).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }))
  })
})
