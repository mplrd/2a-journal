import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import MetaApiConnectDialog from '../MetaApiConnectDialog.vue'
import { brokerSyncService } from '@/services/brokerSync'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/brokerSync', () => ({
  brokerSyncService: {
    createMetaApiConnection: vi.fn(),
    updateConnection: vi.fn(),
  },
}))

/**
 * MetaApi is the second provider whose credentials are shared (its api_token),
 * and the reason the feature was written per provider rather than as a cTrader
 * special case. If the dialog needed cTrader-shaped code to work, the
 * abstraction would not be one.
 */
function createWrapper(props = {}) {
  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })

  return mount(MetaApiConnectDialog, {
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
        Message: { template: '<div class="message"><slot /></div>' },
        FieldHelpIcon: {
          template: '<i :data-testid="testid" :aria-label="text" />',
          props: ['text', 'testid'],
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
const submit = (wrapper) => wrapper.find('[data-testid="metaapi-submit"]')
const banner = (wrapper) => wrapper.find('[data-testid="metaapi-shared-banner"]')

const sharedToken = {
  credentials_public: {},
  credentials_set: { api_token: true },
  credentials_shared_fields: ['api_token'],
  credentials_shared_count: 1,
}

describe('MetaApiConnectDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    brokerSyncService.createMetaApiConnection.mockResolvedValue({
      data: { connection_test: { success: true } },
    })
    brokerSyncService.updateConnection.mockResolvedValue({
      data: { connection_test: { success: true } },
    })
  })

  it('demands both credentials on a first connection', async () => {
    const wrapper = createWrapper()
    expect(submit(wrapper).attributes('disabled')).toBeDefined()

    await field(wrapper, 'metaapi-api-token').setValue('tok')
    await field(wrapper, 'metaapi-account-id').setValue('acc-1')

    expect(submit(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('shows no banner when nothing is stored', () => {
    expect(banner(createWrapper()).exists()).toBe(false)
  })

  it('needs only the account id once the token is stored', async () => {
    const wrapper = createWrapper({ shared: sharedToken })
    expect(submit(wrapper).attributes('disabled')).toBeDefined()

    await field(wrapper, 'metaapi-account-id').setValue('acc-2')

    expect(submit(wrapper).attributes('disabled')).toBeUndefined()
  })

  it('marks the stored token instead of echoing it', () => {
    const wrapper = createWrapper({ shared: sharedToken })

    expect(field(wrapper, 'metaapi-api-token').element.value).toBe('')
    expect(field(wrapper, 'metaapi-api-token').attributes('placeholder')).toBe(
      fr.broker.credential_stored_placeholder,
    )
  })

  it('says how many connections the token feeds', () => {
    const wrapper = createWrapper({ shared: { ...sharedToken, credentials_shared_count: 2 } })

    expect(banner(wrapper).text()).toContain('2')
  })

  it('warns that reconfiguring the token reaches every connection', () => {
    const wrapper = createWrapper({
      connection: {
        id: 9,
        provider: 'METAAPI',
        credentials_public: { metaapi_account_id: 'acc-1' },
        credentials_set: { api_token: true },
        credentials_shared_fields: ['api_token'],
        credentials_shared_count: 2,
      },
    })

    expect(banner(wrapper).text()).toContain(fr.broker.shared_credentials_edit_warning)
  })

  it('sends a blank token so the server keeps the stored one', async () => {
    const wrapper = createWrapper({ shared: sharedToken })
    await field(wrapper, 'metaapi-account-id').setValue('acc-2')
    await submit(wrapper).trigger('click')
    await flushPromises()

    expect(brokerSyncService.createMetaApiConnection).toHaveBeenCalledWith(7, '', 'acc-2')
  })
})
