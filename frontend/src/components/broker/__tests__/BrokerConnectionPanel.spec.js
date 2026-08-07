import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
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
    getSyncLogs: vi.fn(),
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

  describe('non-blocking sync', () => {
    const queued = { ...activeConnection, sync_requested_at: '2026-08-07 09:00:00', syncing_since: null }
    const running = { ...activeConnection, sync_requested_at: null, syncing_since: '2026-08-07 09:00:05' }
    const done = {
      ...activeConnection,
      sync_requested_at: null,
      syncing_since: null,
      last_sync_status: 'SUCCESS',
      last_sync_at: '2026-08-07 09:00:40',
    }

    beforeEach(() => {
      vi.useFakeTimers()
      brokerSyncService.sync.mockResolvedValue({ data: { status: 'QUEUED', syncing: false } })
      brokerSyncService.getSyncLogs.mockResolvedValue({
        data: [{ status: 'SUCCESS', deals_fetched: 9, deals_imported: 3, deals_skipped: 1 }],
      })
    })

    afterEach(() => {
      vi.useRealTimers()
    })

    /** Hands back each connection state in turn, repeating the last one. */
    function connectionStates(...states) {
      let call = 0
      brokerSyncService.getConnection.mockImplementation(() =>
        Promise.resolve({ data: states[Math.min(call++, states.length - 1)] }),
      )
    }

    it('queues the run instead of holding the request open', async () => {
      // A cTrader pass opens four to five WebSocket sessions: inline, the user
      // waits, and a proxy timeout can cut the response mid-import.
      connectionStates(activeConnection, queued)
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()

      const info = toastAdd.mock.calls.find(([c]) => c.severity === 'info')
      expect(info[0].summary).toBe(fr.broker.sync_queued)
      // Nothing is known about the import yet — claiming success here would be
      // a lie, and so would a recap.
      expect(toastAdd.mock.calls.some(([c]) => c.severity === 'success')).toBe(false)
      expect(wrapper.find('.message').exists()).toBe(false)
    })

    it('says the new request lines up behind a run already in flight', async () => {
      // Queued all the same: the running pass took its reservation before this
      // request existed, so it will not swallow it.
      connectionStates(activeConnection, running)
      brokerSyncService.sync.mockResolvedValue({ data: { status: 'QUEUED', syncing: true } })
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()

      const info = toastAdd.mock.calls.find(([c]) => c.severity === 'info')
      expect(info[0].detail).toBe(fr.broker.sync_already_running_detail)
    })

    it('shows the run is pending while the scheduler has not picked it up', async () => {
      connectionStates(activeConnection, queued)
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain(fr.broker.sync_pending)
    })

    it('reports the import once the scheduler has finished the run', async () => {
      connectionStates(activeConnection, queued, running, done)
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()

      // Two ticks: still running, then done.
      await vi.advanceTimersByTimeAsync(10_000)
      await flushPromises()

      expect(toastAdd.mock.calls.some(([c]) => c.severity === 'success')).toBe(true)
      expect(wrapper.text()).toContain('3')
      expect(wrapper.emitted('synced')).toBeTruthy()
    })

    it('surfaces the broker error when the queued run failed', async () => {
      const failed = {
        ...done,
        last_sync_status: 'FAILED',
        last_sync_error: 'cTrader API error: CH_CLIENT_AUTH_FAILURE',
      }
      connectionStates(activeConnection, queued, failed)
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()
      await vi.advanceTimersByTimeAsync(10_000)
      await flushPromises()

      const error = toastAdd.mock.calls.find(([c]) => c.severity === 'error')
      expect(error).toBeTruthy()
      expect(error[0].detail).toContain('CH_CLIENT_AUTH_FAILURE')
      expect(toastAdd.mock.calls.some(([c]) => c.severity === 'success')).toBe(false)
    })

    it('gives up polling instead of watching forever', async () => {
      // The scheduler may be off, or the connection wedged: a spinner that
      // never resolves is worse than an honest "still going".
      connectionStates(activeConnection, running)
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()
      await vi.advanceTimersByTimeAsync(6 * 60 * 1000)
      await flushPromises()

      expect(toastAdd.mock.calls.some(([c]) => c.severity === 'warn')).toBe(true)
      const callsAfterGivingUp = brokerSyncService.getConnection.mock.calls.length
      await vi.advanceTimersByTimeAsync(60_000)
      expect(brokerSyncService.getConnection.mock.calls.length).toBe(callsAfterGivingUp)
    })

    it('stops polling when the panel goes away', async () => {
      connectionStates(activeConnection, running)
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()

      wrapper.unmount()
      const callsAtUnmount = brokerSyncService.getConnection.mock.calls.length
      await vi.advanceTimersByTimeAsync(30_000)

      expect(brokerSyncService.getConnection.mock.calls.length).toBe(callsAtUnmount)
    })

    it('reports the refusal when the connection cannot be synced', async () => {
      connectionStates(activeConnection)
      brokerSyncService.sync.mockRejectedValue({ messageKey: 'broker.error.connection_not_active' })
      const wrapper = createWrapper()
      await flushPromises()

      await button(wrapper, 'broker-sync').trigger('click')
      await flushPromises()

      expect(toastAdd.mock.calls.some(([c]) => c.severity === 'error')).toBe(true)
    })
  })
})
