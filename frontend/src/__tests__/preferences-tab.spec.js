import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useAuthStore } from '@/stores/auth'
import PreferencesTab from '@/components/account/PreferencesTab.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/auth', () => ({
  authService: {
    updateProfile: vi.fn().mockResolvedValue({ success: true, data: {} }),
  },
}))

vi.mock('@/services/api', () => ({
  api: {
    getAccessToken: vi.fn(() => 'token'),
    setTokens: vi.fn(),
    clearTokens: vi.fn(),
  },
}))

const stubs = {
  InputNumber: {
    template: '<input type="number" :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', parseFloat($event.target.value))" />',
    props: ['modelValue', 'min', 'max', 'maxFractionDigits'],
    emits: ['update:modelValue'],
    inheritAttrs: true,
  },
  Select: {
    template: '<select :data-testid="$attrs[\'data-testid\']" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
    props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'filter'],
    emits: ['update:modelValue'],
  },
  Button: {
    template: '<button :data-testid="$attrs[\'data-testid\']" :type="type" @click="$emit(\'click\')">{{ label }}</button>',
    props: ['label', 'type', 'loading', 'icon'],
    emits: ['click'],
  },
}

function createWrapper(userOverrides = {}) {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.user = {
    id: 1,
    email: 'test@test.com',
    first_name: 'John',
    last_name: 'Doe',
    timezone: 'Europe/Paris',
    default_currency: 'EUR',
    theme: 'light',
    locale: 'fr',
    be_threshold_percent: 0,
    ...userOverrides,
  }

  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(PreferencesTab, {
    global: { plugins: [i18n, PrimeVue, ToastService], stubs },
  })
}

describe('PreferencesTab', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('renders all 5 preference fields', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    expect(wrapper.find('[data-testid="select-locale"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-theme"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-timezone"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-currency"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="input-be-threshold"]').exists()).toBe(true)
  })

  it('prefills the BE threshold from the user profile', async () => {
    const wrapper = createWrapper({ be_threshold_percent: 0.02 })
    await flushPromises()
    expect(Number(wrapper.find('[data-testid="input-be-threshold"]').element.value)).toBe(0.02)
  })

  it('defaults the BE threshold to 0 when the user has no value', async () => {
    const wrapper = createWrapper({ be_threshold_percent: null })
    await flushPromises()
    expect(Number(wrapper.find('[data-testid="input-be-threshold"]').element.value)).toBe(0)
  })

  it('sends all 5 fields when saving', async () => {
    const wrapper = createWrapper({ be_threshold_percent: 0 })
    await flushPromises()
    const authStore = useAuthStore()
    const spy = vi.spyOn(authStore, 'updateProfile').mockResolvedValue()

    await wrapper.find('[data-testid="input-be-threshold"]').setValue('0.05')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(spy).toHaveBeenCalledWith(expect.objectContaining({
      locale: 'fr',
      theme: 'light',
      timezone: 'Europe/Paris',
      default_currency: 'EUR',
      be_threshold_percent: 0.05,
    }))
  })

  it('does not send profile-identity fields (first_name, last_name, email)', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    const authStore = useAuthStore()
    const spy = vi.spyOn(authStore, 'updateProfile').mockResolvedValue()

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    const payload = spy.mock.calls[0][0]
    expect(payload).not.toHaveProperty('first_name')
    expect(payload).not.toHaveProperty('last_name')
    expect(payload).not.toHaveProperty('email')
  })
})
