import { describe, it, expect, vi, beforeEach } from 'vitest'
import { nextTick } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useAuthStore } from '@/stores/auth'
import ProfileTab from '@/components/account/ProfileTab.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/auth', () => ({
  authService: {
    updateProfile: vi.fn().mockResolvedValue({ success: true, data: {} }),
    uploadProfilePicture: vi.fn(),
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
  InputText: {
    template: '<input :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    inheritAttrs: true,
  },
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

  return mount(ProfileTab, {
    global: { plugins: [i18n, PrimeVue, ToastService], stubs },
  })
}

describe('ProfileTab — BE threshold field', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the BE threshold input', async () => {
    const wrapper = createWrapper()
    await nextTick()
    expect(wrapper.find('[data-testid="input-be-threshold"]').exists()).toBe(true)
  })

  it('prefills the BE threshold from the user profile', async () => {
    const wrapper = createWrapper({ be_threshold_percent: 0.02 })
    await flushPromises()
    const input = wrapper.find('[data-testid="input-be-threshold"]')
    expect(Number(input.element.value)).toBe(0.02)
  })

  it('defaults the BE threshold to 0 when the user has no value', async () => {
    const wrapper = createWrapper({ be_threshold_percent: null })
    await flushPromises()
    const input = wrapper.find('[data-testid="input-be-threshold"]')
    expect(Number(input.element.value)).toBe(0)
  })

  it('sends the BE threshold when saving the profile', async () => {
    const wrapper = createWrapper({ be_threshold_percent: 0 })
    await flushPromises()
    const authStore = useAuthStore()
    const spy = vi.spyOn(authStore, 'updateProfile').mockResolvedValue()

    const input = wrapper.find('[data-testid="input-be-threshold"]')
    await input.setValue('0.05')

    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ be_threshold_percent: 0.05 }))
  })
})
