import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import CtraderConnectDialog from '../CtraderConnectDialog.vue'
import { brokerSyncService } from '@/services/brokerSync'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/brokerSync', () => ({
  brokerSyncService: {
    createCtraderConnection: vi.fn(),
    updateConnection: vi.fn(),
    fetchCtraderAccounts: vi.fn(),
  },
}))

function createWrapper(props = {}) {
  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })

  return mount(CtraderConnectDialog, {
    props: {
      visible: true,
      account: { id: 7, name: 'FTMO' },
      connection: null,
      ...props,
    },
    global: {
      plugins: [i18n, PrimeVue],
      stubs: {
        Dialog: {
          template: '<div v-if="visible" class="dialog-stub"><slot /></div>',
          props: ['visible'],
        },
        InputText: {
          template:
            '<input class="input-text" :data-name="$attrs.name" :placeholder="$attrs.placeholder" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
        SelectButton: {
          template:
            '<div class="select-button" :data-name="$attrs.name"><button v-for="o in options" :key="o.value" :data-value="o.value" :class="{ active: modelValue === o.value }" @click="$emit(\'update:modelValue\', o.value)">{{ o.label }}</button></div>',
          props: ['modelValue', 'options'],
          emits: ['update:modelValue'],
        },
        Message: { template: '<div class="message"><slot /></div>' },
        FieldHelpIcon: {
          template: '<i :data-testid="testid" :aria-label="text" />',
          props: ['text', 'testid'],
        },
        Select: {
          template:
            '<div class="select" :data-name="$attrs.name"><button v-for="o in options" :key="o[optionValue]" :data-value="o[optionValue]" :disabled="optionDisabled ? o[optionDisabled] : false" @click="$emit(\'update:modelValue\', o[optionValue])">{{ o[optionLabel] }}</button></div>',
          props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'optionDisabled'],
          emits: ['update:modelValue'],
        },
        Button: {
          template:
            '<button :data-testid="$attrs[`data-testid`]" :disabled="disabled" @click="$emit(\'click\')">{{ label }}</button>',
          props: ['label', 'disabled'],
          emits: ['click'],
        },
      },
    },
  })
}

const field = (wrapper, name) => wrapper.find(`[data-name="${name}"]`)
const submit = (wrapper) => wrapper.find('[data-testid="ctrader-submit"]')

const existingConnection = {
  id: 42,
  provider: 'CTRADER',
  credentials_public: { client_id: '30528', account_id_ctrader: '12345678', environment: 'LIVE' },
  credentials_set: { client_secret: true, access_token: true },
}

describe('CtraderConnectDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    brokerSyncService.createCtraderConnection.mockResolvedValue({ data: { connection_test: { success: true } } })
    brokerSyncService.updateConnection.mockResolvedValue({ data: { connection_test: { success: true } } })
  })

  describe('create mode', () => {
    it('requires every credential before enabling submit', async () => {
      const wrapper = createWrapper()
      expect(submit(wrapper).attributes('disabled')).toBeDefined()

      await field(wrapper, 'ctrader-client-id').setValue('30528')
      await field(wrapper, 'ctrader-client-secret').setValue('sec')
      await field(wrapper, 'ctrader-access-token').setValue('tok')
      await field(wrapper, 'ctrader-account-id').setValue('12345678')

      expect(submit(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('defaults to the live environment and sends it', async () => {
      const wrapper = createWrapper()
      await field(wrapper, 'ctrader-client-id').setValue('30528')
      await field(wrapper, 'ctrader-client-secret').setValue('sec')
      await field(wrapper, 'ctrader-access-token').setValue('tok')
      await field(wrapper, 'ctrader-account-id').setValue('12345678')
      await submit(wrapper).trigger('click')
      await flushPromises()

      expect(brokerSyncService.createCtraderConnection).toHaveBeenCalledWith(
        7,
        expect.objectContaining({ environment: 'LIVE' }),
      )
    })

    it('sends the demo environment when selected', async () => {
      const wrapper = createWrapper()
      await field(wrapper, 'ctrader-client-id').setValue('30528')
      await field(wrapper, 'ctrader-client-secret').setValue('sec')
      await field(wrapper, 'ctrader-access-token').setValue('tok')
      await field(wrapper, 'ctrader-account-id').setValue('12345678')
      await wrapper.find('[data-name="ctrader-environment"] [data-value="DEMO"]').trigger('click')
      await submit(wrapper).trigger('click')
      await flushPromises()

      expect(brokerSyncService.createCtraderConnection).toHaveBeenCalledWith(
        7,
        expect.objectContaining({ environment: 'DEMO' }),
      )
    })
  })

  describe('account discovery', () => {
    const accounts = [
      {
        ctid_trader_account_id: 42111,
        trader_login: '1234567',
        is_live: true,
        broker_name: 'FTMO',
        balance: 80000,
        currency: 'EUR',
        is_disabled: false,
        disabled_reason: null,
        details: { brokerTitleShort: 'FTMO', ctidTraderAccountId: 42111, traderLogin: 1234567 },
      },
      {
        ctid_trader_account_id: 42112,
        trader_login: '7654321',
        is_live: false,
        broker_name: null,
        balance: null,
        currency: null,
        is_disabled: false,
        disabled_reason: null,
        details: {},
      },
    ]

    async function fillAppCredentials(wrapper) {
      await field(wrapper, 'ctrader-client-id').setValue('30528')
      await field(wrapper, 'ctrader-client-secret').setValue('sec')
      await field(wrapper, 'ctrader-access-token').setValue('tok')
    }

    it('cannot load accounts before the app credentials are filled', () => {
      const wrapper = createWrapper()

      expect(wrapper.find('[data-testid="ctrader-load-accounts"]').attributes('disabled')).toBeDefined()
    })

    it('lists the accounts behind the token, labelled by the login the user recognises', async () => {
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({ data: { accounts, error: null } })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)

      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      const options = wrapper.findAll('[data-name="ctrader-account-picker"] button')
      expect(options).toHaveLength(2)
      // traderLogin is the number shown in the cTrader platform; the opaque
      // ctidTraderAccountId is what we store, never what we display.
      expect(options[0].text()).toContain('1234567')
    })

    it('fills the account id and derives the server from the picked account', async () => {
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({ data: { accounts, error: null } })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)
      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      // Pick the demo account.
      await wrapper.find('[data-name="ctrader-account-picker"] [data-value="42112"]').trigger('click')
      await submit(wrapper).trigger('click')
      await flushPromises()

      expect(brokerSyncService.createCtraderConnection).toHaveBeenCalledWith(
        7,
        expect.objectContaining({ account_id_ctrader: '42112', environment: 'DEMO' }),
      )
    })

    it('labels an account by broker, size and login', async () => {
      // Several accounts at the same prop firm all read "FTMO", so the broker
      // name alone does not separate them — the balance is what does. Reported
      // by a user with several FTMO accounts who could not tell which was which.
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({ data: { accounts, error: null } })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)
      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      const label = wrapper.findAll('[data-name="ctrader-account-picker"] button')[0].text()
      expect(label).toContain('FTMO')
      expect(label).toContain('1234567')
      // Grouping separators are locale- and runtime-dependent; assert the
      // digits and the currency, not the exact spacing.
      expect(label).toMatch(/80[\s  ]?000/)
      expect(label).toContain('€')
    })

    it('omits the size when the broker did not give one', async () => {
      // Enrichment is best-effort: an account we could not read must still be
      // listed and pickable, just without its balance.
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({ data: { accounts, error: null } })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)
      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      const label = wrapper.findAll('[data-name="ctrader-account-picker"] button')[1].text()
      expect(label).toContain('7654321')
      expect(label).not.toContain('€')
      expect(label).not.toContain('null')
    })

    it('marks accounts the broker refused and stops them being picked', async () => {
      // The list includes archived accounts; picking one used to fail only
      // after saving, with RET_ACCOUNT_DISABLED. Account auth already told us
      // during discovery, so say it here instead.
      const withArchived = [
        { ...accounts[0], is_disabled: true, disabled_reason: 'RET_ACCOUNT_DISABLED' },
        accounts[1],
      ]
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({
        data: { accounts: withArchived, error: null },
      })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)
      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      const archived = wrapper.find('[data-name="ctrader-account-picker"] [data-value="42111"]')
      expect(archived.text()).toContain('RET_ACCOUNT_DISABLED')
      expect(archived.attributes('disabled')).toBeDefined()
    })

    it('shows every field the API returned for the selected account', async () => {
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({ data: { accounts, error: null } })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)
      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      await wrapper.find('[data-name="ctrader-account-picker"] [data-value="42111"]').trigger('click')

      const details = wrapper.find('[data-testid="ctrader-account-details"]')
      expect(details.exists()).toBe(true)
      expect(details.text()).toContain('brokerTitleShort')
      expect(details.text()).toContain('FTMO')
    })

    it('reports how many accounts came back so a long list is not a surprise', async () => {
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({ data: { accounts, error: null } })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)
      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid="ctrader-account-count"]').text()).toContain('2')
    })

    it('shows the broker reason when discovery fails', async () => {
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({
        data: { accounts: [], error: 'cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret' },
      })
      const wrapper = createWrapper()
      await fillAppCredentials(wrapper)

      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      expect(wrapper.text()).toContain('CH_CLIENT_AUTH_FAILURE')
    })

    it('sends the stored connection id so reconfiguring needs no retyped secret', async () => {
      brokerSyncService.fetchCtraderAccounts.mockResolvedValue({ data: { accounts, error: null } })
      const wrapper = createWrapper({ connection: existingConnection })

      // client_id is prefilled and the secrets are stored — the button must be
      // usable without retyping anything.
      expect(wrapper.find('[data-testid="ctrader-load-accounts"]').attributes('disabled')).toBeUndefined()
      await wrapper.find('[data-testid="ctrader-load-accounts"]').trigger('click')
      await flushPromises()

      expect(brokerSyncService.fetchCtraderAccounts).toHaveBeenCalledWith(
        expect.objectContaining({ connection_id: 42 }),
      )
    })
  })

  describe('credential help hints', () => {
    // The account-id field caused a real support case: the user entered the
    // account number shown in the cTrader platform (traderLogin), but the API
    // authenticates with ctidTraderAccountId — a different number.
    it('warns that the account id is not the platform account number', () => {
      const wrapper = createWrapper()
      const hint = wrapper.find('[data-testid="ctrader-account-id-help"]')

      expect(hint.exists()).toBe(true)
      expect(hint.attributes('aria-label')).toBe(fr.broker.ctrader_account_id_help)
      expect(fr.broker.ctrader_account_id_help).toContain('ctidTraderAccountId')
    })

    it('does not suggest a platform login number as the placeholder', () => {
      // "ex: 12345678" reads exactly like a trading account login and is what
      // sent the user down the wrong path.
      expect(fr.broker.ctrader_account_id_placeholder).not.toBe('ex: 12345678')
    })

    it('hints every credential field', () => {
      const wrapper = createWrapper()

      for (const testid of [
        'ctrader-client-id-help',
        'ctrader-client-secret-help',
        'ctrader-access-token-help',
        'ctrader-account-id-help',
      ]) {
        const hint = wrapper.find(`[data-testid="${testid}"]`)
        expect(hint.exists(), testid).toBe(true)
        expect(hint.attributes('aria-label'), testid).toBeTruthy()
      }
    })
  })

  describe('reconfigure mode', () => {
    it('prefills the non-secret identifiers from the existing connection', () => {
      const wrapper = createWrapper({ connection: existingConnection })

      expect(field(wrapper, 'ctrader-client-id').element.value).toBe('30528')
      expect(field(wrapper, 'ctrader-account-id').element.value).toBe('12345678')
    })

    it('leaves secrets empty and says they are kept if untouched', () => {
      const wrapper = createWrapper({ connection: existingConnection })

      expect(field(wrapper, 'ctrader-client-secret').element.value).toBe('')
      expect(field(wrapper, 'ctrader-access-token').element.value).toBe('')
      // The placeholder is what tells the user a blank field is not a wipe.
      expect(field(wrapper, 'ctrader-client-secret').attributes('placeholder')).toBe(
        fr.broker.credential_unchanged_placeholder,
      )
    })

    it('enables submit as soon as one field is edited', async () => {
      const wrapper = createWrapper({ connection: existingConnection })
      expect(submit(wrapper).attributes('disabled')).toBeDefined()

      await field(wrapper, 'ctrader-client-secret').setValue('fresh-secret')

      expect(submit(wrapper).attributes('disabled')).toBeUndefined()
    })

    it('sends only the edited fields to the update endpoint', async () => {
      const wrapper = createWrapper({ connection: existingConnection })
      await field(wrapper, 'ctrader-client-secret').setValue('fresh-secret')
      await submit(wrapper).trigger('click')
      await flushPromises()

      // Untouched secrets must not be sent as empty strings that would be
      // mistaken for a deliberate blank.
      expect(brokerSyncService.updateConnection).toHaveBeenCalledWith(42, {
        client_secret: 'fresh-secret',
      })
      expect(brokerSyncService.createCtraderConnection).not.toHaveBeenCalled()
    })

    it('sends the environment when it is switched', async () => {
      const wrapper = createWrapper({ connection: existingConnection })
      await wrapper.find('[data-name="ctrader-environment"] [data-value="DEMO"]').trigger('click')
      await submit(wrapper).trigger('click')
      await flushPromises()

      expect(brokerSyncService.updateConnection).toHaveBeenCalledWith(42, { environment: 'DEMO' })
    })

    it('emits the saved connection test result so the panel can warn', async () => {
      brokerSyncService.updateConnection.mockResolvedValue({
        data: {
          connection_test: { success: false, error: 'cTrader API error: CH_CLIENT_AUTH_FAILURE - wrong clientSecret' },
        },
      })
      const wrapper = createWrapper({ connection: existingConnection })
      await field(wrapper, 'ctrader-client-secret').setValue('still-wrong')
      await submit(wrapper).trigger('click')
      await flushPromises()

      const emitted = wrapper.emitted('connected')
      expect(emitted).toHaveLength(1)
      expect(emitted[0][0].connection_test.success).toBe(false)
    })
  })
})
