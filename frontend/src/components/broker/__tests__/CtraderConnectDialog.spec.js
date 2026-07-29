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
