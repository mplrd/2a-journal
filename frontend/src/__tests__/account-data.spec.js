import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useAuthStore } from '@/stores/auth'
import AccountDataSection from '@/components/account/AccountDataSection.vue'
import SecuritySection from '@/components/account/SecuritySection.vue'
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

function createI18nInstance(locale = 'fr') {
  return createI18n({
    legacy: false,
    locale,
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

describe('AccountDataSection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function mountSection(locale = 'fr') {
    setupStore()
    return mount(AccountDataSection, {
      global: {
        plugins: [createI18nInstance(locale), PrimeVue, ToastService],
        stubs: { ...baseStubs, DeleteAccountDialog: true },
      },
    })
  }

  it('renders only the delete-account button (password is in SecuritySection now)', () => {
    const wrapper = mountSection()
    expect(wrapper.find('[data-testid="open-delete-account"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="open-change-password"]').exists()).toBe(false)
  })

  it('uses red-themed styling on its outer wrapper', () => {
    const wrapper = mountSection()
    const wrapperDiv = wrapper.find('[data-testid="account-data-wrapper"]')
    expect(wrapperDiv.exists()).toBe(true)
    expect(wrapperDiv.classes().some((c) => c.includes('red'))).toBe(true)
  })

  it('uses a neutral section title, free of any "danger" wording', () => {
    const title = mountSection().find('[data-testid="account-data-title"]')
    expect(title.exists()).toBe(true)
    expect(title.text()).toBe('Compte et données')
    expect(title.text().toLowerCase()).not.toContain('danger')
  })

  it('renders the neutral title in English too', () => {
    const title = mountSection('en').find('[data-testid="account-data-title"]')
    expect(title.text()).toBe('Account & data')
    expect(title.text().toLowerCase()).not.toContain('danger')
  })

  it('carries the alerting weight on the title colour being neutral, not red', () => {
    const title = mountSection().find('[data-testid="account-data-title"]')
    expect(title.classes().some((c) => c.includes('red'))).toBe(false)
  })

  it('shows a prominent irreversible-action warning', () => {
    const warning = mountSection().find('[data-testid="irreversible-warning"]')
    expect(warning.exists()).toBe(true)
    expect(warning.text()).toContain('Action irréversible')
  })

  it('renders the irreversible warning in red and bold, larger than the description', () => {
    const warning = mountSection().find('[data-testid="irreversible-warning"]')
    const classes = warning.classes()
    expect(classes.some((c) => c.includes('red'))).toBe(true)
    expect(classes).toContain('font-bold')
    expect(classes).toContain('text-base')
    expect(classes.some((c) => c === 'text-sm')).toBe(false)
  })

  it('lays out title+subtitle, warning and button as three siblings on one row', () => {
    const row = mountSection().find('[data-testid="account-data-row"]')
    expect(row.exists()).toBe(true)
    expect(row.classes()).toContain('sm:flex-row')
    expect(row.classes()).toContain('sm:justify-between')

    const children = Array.from(row.element.children)
    expect(children).toHaveLength(3)
    expect(children[0].querySelector('[data-testid="delete-account-description"]')).not.toBeNull()
    expect(children[1].getAttribute('data-testid')).toBe('irreversible-warning')
    expect(children[2].getAttribute('data-testid')).toBe('open-delete-account')
  })

  it('no longer repeats the irreversibility in the small description text', () => {
    const description = mountSection().find('[data-testid="delete-account-description"]')
    expect(description.exists()).toBe(true)
    expect(description.text().toLowerCase()).not.toContain('irréversible')
  })

  it('opens the delete-account dialog when the button is clicked', async () => {
    const wrapper = mountSection()
    expect(wrapper.vm.deleteAccountVisible).toBe(false)
    await wrapper.find('[data-testid="open-delete-account"]').trigger('click')
    expect(wrapper.vm.deleteAccountVisible).toBe(true)
  })
})

describe('SecuritySection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the change-password button', () => {
    setupStore()
    const wrapper = mount(SecuritySection, {
      global: {
        plugins: [createI18nInstance(), PrimeVue, ToastService],
        stubs: { ...baseStubs, ChangePasswordDialog: true },
      },
    })
    expect(wrapper.find('[data-testid="open-change-password"]').exists()).toBe(true)
  })

  it('does NOT use red-themed styling (neutral section)', () => {
    setupStore()
    const wrapper = mount(SecuritySection, {
      global: {
        plugins: [createI18nInstance(), PrimeVue, ToastService],
        stubs: { ...baseStubs, ChangePasswordDialog: true },
      },
    })
    const wrapperDiv = wrapper.find('[data-testid="security-section-wrapper"]')
    expect(wrapperDiv.exists()).toBe(true)
    expect(wrapperDiv.classes().some((c) => c.includes('red'))).toBe(false)
  })

  it('opens the change-password dialog when the button is clicked', async () => {
    setupStore()
    const wrapper = mount(SecuritySection, {
      global: {
        plugins: [createI18nInstance(), PrimeVue, ToastService],
        stubs: { ...baseStubs, ChangePasswordDialog: true },
      },
    })
    expect(wrapper.vm.changePasswordVisible).toBe(false)
    await wrapper.find('[data-testid="open-change-password"]').trigger('click')
    expect(wrapper.vm.changePasswordVisible).toBe(true)
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
