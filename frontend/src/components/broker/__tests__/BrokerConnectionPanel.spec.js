import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import BrokerConnectionPanel from '../BrokerConnectionPanel.vue'
import { brokerSyncService } from '@/services/brokerSync'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/brokerSync', () => ({
  brokerSyncService: {
    getConnection: vi.fn(),
    sync: vi.fn(),
    deleteConnection: vi.fn(),
  },
}))

const toastAdd = vi.fn()
vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: toastAdd }) }))

function createWrapper() {
  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })

  return mount(BrokerConnectionPanel, {
    props: { account: { id: 7, name: 'FTMO' } },
    global: {
      plugins: [i18n, PrimeVue],
      stubs: {
        Tag: { template: '<span class="tag">{{ value }}</span>', props: ['value'] },
        Message: { template: '<div class="message"><slot /></div>' },
        Button: {
          template:
            '<button :data-testid="$attrs[`data-testid`]" @click="$emit(\'click\')">{{ label }}</button>',
          props: ['label'],
          emits: ['click'],
        },
        CtraderConnectDialog: {
          name: 'CtraderConnectDialog',
          template: '<div class="ctrader-dialog" :data-mode="connection ? `edit` : `create`" />',
          props: ['visible', 'account', 'connection'],
        },
        MetaApiConnectDialog: { template: '<div />', props: ['visible', 'account', 'connection'] },
        OuinexConnectDialog: { template: '<div />', props: ['visible', 'account', 'connection'] },
        BingxConnectDialog: { template: '<div />', props: ['visible', 'account', 'connection'] },
        SyncHistoryDialog: { template: '<div />', props: ['visible', 'connection'] },
      },
    },
  })
}

const button = (wrapper, testid) => wrapper.find(`[data-testid="${testid}"]`)

const activeConnection = {
  id: 42,
  provider: 'CTRADER',
  status: 'ACTIVE',
  last_sync_at: null,
  credentials_public: { client_id: '30528', environment: 'LIVE' },
  credentials_set: { client_secret: true, access_token: true },
}

const erroredConnection = {
  ...activeConnection,
  status: 'ERROR',
  last_sync_error: 'cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret',
}

describe('BrokerConnectionPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('offers reconfigure on a healthy connection', async () => {
    brokerSyncService.getConnection.mockResolvedValue({ data: activeConnection })
    const wrapper = createWrapper()
    await flushPromises()

    expect(button(wrapper, 'broker-reconfigure').exists()).toBe(true)
  })

  it('offers reconfigure on a broken connection instead of only delete', async () => {
    // The regression that made this necessary: an ERROR connection could not
    // sync (sync() demands ACTIVE) and had no edit path, so the sole way out
    // was deleting it and losing the sync cursor plus the log history.
    brokerSyncService.getConnection.mockResolvedValue({ data: erroredConnection })
    const wrapper = createWrapper()
    await flushPromises()

    expect(button(wrapper, 'broker-reconfigure').exists()).toBe(true)
    expect(button(wrapper, 'broker-disconnect').exists()).toBe(true)
  })

  it('surfaces the broker error message on a broken connection', async () => {
    brokerSyncService.getConnection.mockResolvedValue({ data: erroredConnection })
    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.text()).toContain('CH_CLIENT_AUTH_FAILURE')
  })

  it('opens the provider dialog in edit mode when reconfiguring', async () => {
    brokerSyncService.getConnection.mockResolvedValue({ data: erroredConnection })
    const wrapper = createWrapper()
    await flushPromises()

    await button(wrapper, 'broker-reconfigure').trigger('click')

    expect(wrapper.find('.ctrader-dialog').attributes('data-mode')).toBe('edit')
  })

  it('warns when the saved credentials failed their connection test', async () => {
    brokerSyncService.getConnection.mockResolvedValue({ data: activeConnection })
    const wrapper = createWrapper()
    await flushPromises()

    await wrapper.findComponent({ name: 'CtraderConnectDialog' }).vm.$emit('connected', {
      connection_test: { success: false, error: 'CH_CLIENT_AUTH_FAILURE - wrong clientSecret' },
    })
    await flushPromises()

    // Saving is never blocked, so the only signal the credentials are still
    // wrong is this warning.
    const warn = toastAdd.mock.calls.find(([c]) => c.severity === 'warn')
    expect(warn).toBeTruthy()
    expect(warn[0].detail).toContain('CH_CLIENT_AUTH_FAILURE')
  })

  it('confirms when the saved credentials passed their connection test', async () => {
    brokerSyncService.getConnection.mockResolvedValue({ data: activeConnection })
    const wrapper = createWrapper()
    await flushPromises()

    await wrapper.findComponent({ name: 'CtraderConnectDialog' }).vm.$emit('connected', {
      connection_test: { success: true, error: null },
    })
    await flushPromises()

    expect(toastAdd.mock.calls.some(([c]) => c.severity === 'success')).toBe(true)
  })

  it('says the sync is already running instead of claiming success on zero import', async () => {
    // The scheduled run holds the connection: nothing was imported, and a
    // "success, 0 positions" toast would read as "the broker sent nothing".
    brokerSyncService.getConnection.mockResolvedValue({ data: activeConnection })
    brokerSyncService.sync.mockResolvedValue({
      data: { status: 'SKIPPED', imported_positions: 0, skipped_duplicates: 0 },
    })
    const wrapper = createWrapper()
    await flushPromises()

    await button(wrapper, 'broker-sync').trigger('click')
    await flushPromises()

    expect(toastAdd.mock.calls.some(([c]) => c.severity === 'success')).toBe(false)
    const info = toastAdd.mock.calls.find(([c]) => c.severity === 'info')
    expect(info).toBeTruthy()
    expect(info[0].summary).toBe(fr.broker.sync_already_running)
    // No import happened, so no import recap panel either.
    expect(wrapper.find('.message').exists()).toBe(false)
  })

  it('reports the import recap on a sync that actually ran', async () => {
    brokerSyncService.getConnection.mockResolvedValue({ data: activeConnection })
    brokerSyncService.sync.mockResolvedValue({
      data: { status: 'SUCCESS', imported_positions: 3, skipped_duplicates: 1 },
    })
    const wrapper = createWrapper()
    await flushPromises()

    await button(wrapper, 'broker-sync').trigger('click')
    await flushPromises()

    expect(toastAdd.mock.calls.some(([c]) => c.severity === 'success')).toBe(true)
  })
})
