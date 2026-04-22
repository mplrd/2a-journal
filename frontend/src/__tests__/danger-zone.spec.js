import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useAuthStore } from '@/stores/auth'
import DangerZone from '@/components/account/DangerZone.vue'
import ChangePasswordDialog from '@/components/account/ChangePasswordDialog.vue'
import DeleteAccountDialog from '@/components/account/DeleteAccountDialog.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/auth', () => ({
  authService: {
    changePassword: vi.fn().mockResolvedValue({ success: true }),
    deleteAccount: vi.fn().mockResolvedValue({ success: true }),
  },
}))

vi.mock('@/services/api', () => ({
  api: {
    getAccessToken: vi.fn(() => 'token'),
    setTokens: vi.fn(),
    clearTokens: vi.fn(),
  },
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}))

const baseStubs = {
  Dialog: {
    template: '<div v-if="visible" :data-testid="$attrs[\'data-testid\'] || \'dialog\'"><slot /></div>',
    props: ['visible', 'header', 'modal', 'closable', 'style'],
    emits: ['update:visible'],
  },
  InputText: {
    template: '<input :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    inheritAttrs: true,
  },
  Password: {
    template: '<input type="password" :data-testid="$attrs[\'data-testid\']" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'feedback', 'toggleMask', 'inputClass'],
    emits: ['update:modelValue'],
    inheritAttrs: true,
  },
  Button: {
    template: '<button :data-testid="$attrs[\'data-testid\']" :type="type" :disabled="disabled" @click="$emit(\'click\')">{{ label }}</button>',
    props: ['label', 'type', 'severity', 'outlined', 'loading', 'disabled'],
    emits: ['click'],
  },
}

function createI18nInstance() {
  return createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })
}

function setupStore(overrides = {}) {
  setActivePinia(createPinia())
  const authStore = useAuthStore()
  authStore.user = {
    id: 1,
    email: 'user@test.com',
    first_name: 'John',
    ...overrides,
  }
  return authStore
}

describe('DangerZone', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders both danger buttons', () => {
    setupStore()
    const wrapper = mount(DangerZone, {
      global: {
        plugins: [createI18nInstance(), PrimeVue, ToastService],
        stubs: { ...baseStubs, ChangePasswordDialog: true, DeleteAccountDialog: true },
      },
    })
    expect(wrapper.find('[data-testid="open-change-password"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="open-delete-account"]').exists()).toBe(true)
  })
})

describe('ChangePasswordDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function mountDialog(props = { visible: true }) {
    setupStore()
    return mount(ChangePasswordDialog, {
      props,
      global: {
        plugins: [createI18nInstance(), PrimeVue, ToastService],
        stubs: baseStubs,
      },
    })
  }

  it('submit button disabled when fields incomplete', async () => {
    const wrapper = mountDialog()
    await flushPromises()
    const btn = wrapper.find('[data-testid="submit-change-password"]')
    expect(btn.attributes('disabled')).toBeDefined()
  })

  it('shows client error when confirmation does not match new password', async () => {
    const wrapper = mountDialog()
    await wrapper.find('[data-testid="input-current-password"]').setValue('Old12345')
    await wrapper.find('[data-testid="input-new-password"]').setValue('New12345')
    await wrapper.find('[data-testid="input-confirm-password"]').setValue('DIFF1234')
    // submit button remains disabled (client-side guard via canSubmit)
    const btn = wrapper.find('[data-testid="submit-change-password"]')
    expect(btn.attributes('disabled')).toBeDefined()
  })

  it('calls authStore.changePassword on valid submit', async () => {
    const wrapper = mountDialog()
    const authStore = useAuthStore()
    const spy = vi.spyOn(authStore, 'changePassword').mockResolvedValue()

    await wrapper.find('[data-testid="input-current-password"]').setValue('Old12345')
    await wrapper.find('[data-testid="input-new-password"]').setValue('NewPass1')
    await wrapper.find('[data-testid="input-confirm-password"]').setValue('NewPass1')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(spy).toHaveBeenCalledWith({
      current_password: 'Old12345',
      new_password: 'NewPass1',
    })
  })
})

describe('DeleteAccountDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function mountDialog() {
    setupStore()
    return mount(DeleteAccountDialog, {
      props: { visible: true },
      global: {
        plugins: [createI18nInstance(), PrimeVue, ToastService],
        stubs: baseStubs,
      },
    })
  }

  it('submit button disabled when email does not match user email', async () => {
    const wrapper = mountDialog()
    await flushPromises()
    await wrapper.find('[data-testid="input-email-confirmation"]').setValue('wrong@test.com')
    await wrapper.find('[data-testid="input-delete-password"]').setValue('Pass1234')
    const btn = wrapper.find('[data-testid="submit-delete-account"]')
    expect(btn.attributes('disabled')).toBeDefined()
  })

  it('shows mismatch hint when email differs', async () => {
    const wrapper = mountDialog()
    await wrapper.find('[data-testid="input-email-confirmation"]').setValue('nope@test.com')
    expect(wrapper.find('[data-testid="email-mismatch"]').exists()).toBe(true)
  })

  it('submit button enabled when email and password correct', async () => {
    const wrapper = mountDialog()
    await wrapper.find('[data-testid="input-email-confirmation"]').setValue('user@test.com')
    await wrapper.find('[data-testid="input-delete-password"]').setValue('Pass1234')
    const btn = wrapper.find('[data-testid="submit-delete-account"]')
    expect(btn.attributes('disabled')).toBeUndefined()
  })

  it('calls authStore.deleteAccount on valid submit', async () => {
    const wrapper = mountDialog()
    const authStore = useAuthStore()
    const spy = vi.spyOn(authStore, 'deleteAccount').mockResolvedValue()

    await wrapper.find('[data-testid="input-email-confirmation"]').setValue('user@test.com')
    await wrapper.find('[data-testid="input-delete-password"]').setValue('Pass1234')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(spy).toHaveBeenCalledWith({
      password: 'Pass1234',
      email_confirmation: 'user@test.com',
    })
  })
})
