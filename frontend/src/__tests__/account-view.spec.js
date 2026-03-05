import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { useAuthStore } from '@/stores/auth'
import AccountView from '@/views/AccountView.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/auth', () => ({
  authService: {
    updateProfile: vi.fn(),
  },
}))

function createWrapper() {
  const pinia = createPinia()
  setActivePinia(pinia)

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
  }

  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(AccountView, {
    global: {
      plugins: [pinia, i18n, PrimeVue, ToastService],
      stubs: {
        InputText: {
          template: '<input :value="modelValue" :disabled="disabled" :data-testid="$attrs[\'data-testid\']" />',
          props: ['modelValue', 'disabled'],
        },
        Select: {
          template: '<select :data-testid="$attrs[\'data-testid\']"></select>',
          props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'filter'],
        },
        Button: {
          template: '<button :data-testid="$attrs[\'data-testid\']" type="submit">{{ label }}</button>',
          props: ['label', 'loading'],
        },
      },
    },
  })
}

describe('AccountView', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark-mode')
  })

  it('renders profile form fields', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-testid="input-first-name"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="input-last-name"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="input-email"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-timezone"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-currency"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-theme"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="select-locale"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="save-button"]').exists()).toBe(true)
  })

  it('initializes form from user data', async () => {
    const wrapper = createWrapper()
    await wrapper.vm.$nextTick()
    const firstNameInput = wrapper.find('[data-testid="input-first-name"]')
    expect(firstNameInput.element.value).toBe('John')
    const emailInput = wrapper.find('[data-testid="input-email"]')
    expect(emailInput.element.value).toBe('test@test.com')
    expect(emailInput.element.disabled).toBe(true)
  })

  it('calls updateProfile on save', async () => {
    const wrapper = createWrapper()
    const authStore = useAuthStore()
    authStore.updateProfile = vi.fn()

    await wrapper.find('[data-testid="account-form"]').trigger('submit')

    expect(authStore.updateProfile).toHaveBeenCalledWith({
      first_name: 'John',
      last_name: 'Doe',
      timezone: 'Europe/Paris',
      default_currency: 'EUR',
      theme: 'light',
      locale: 'fr',
    })
  })

  it('renders avatar with initials when no picture', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-testid="avatar-initials"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="avatar-initials"]').text()).toBe('JD')
    expect(wrapper.find('[data-testid="avatar-image"]').exists()).toBe(false)
  })

  it('renders avatar with image when picture exists', () => {
    const pinia = createPinia()
    setActivePinia(pinia)

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
      profile_picture: 'uploads/avatars/1_123456.jpg',
    }

    const i18n = createI18n({
      legacy: false,
      locale: 'fr',
      fallbackLocale: 'en',
      messages: { fr, en },
    })

    const wrapper = mount(AccountView, {
      global: {
        plugins: [pinia, i18n, PrimeVue, ToastService],
        stubs: {
          InputText: {
            template: '<input :value="modelValue" :disabled="disabled" :data-testid="$attrs[\'data-testid\']" />',
            props: ['modelValue', 'disabled'],
          },
          Select: {
            template: '<select :data-testid="$attrs[\'data-testid\']"></select>',
            props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'filter'],
          },
          Button: {
            template: '<button :data-testid="$attrs[\'data-testid\']" type="submit">{{ label }}</button>',
            props: ['label', 'loading'],
          },
        },
      },
    })

    expect(wrapper.find('[data-testid="avatar-image"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="avatar-initials"]').exists()).toBe(false)
  })
})
