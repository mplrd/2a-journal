import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia } from 'pinia'
import AppLayout from '../components/layout/AppLayout.vue'
import fr from '../locales/fr.json'
import en from '../locales/en.json'

function createWrapper(locale = 'fr') {
  const i18n = createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(AppLayout, {
    global: {
      plugins: [createPinia(), i18n],
      stubs: {
        RouterView: true,
        RouterLink: true,
        Popover: {
          template: '<div><slot /></div>',
          methods: { toggle() {} },
        },
      },
    },
  })
}

describe('Language selector', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('renders language options', () => {
    const wrapper = createWrapper('fr')
    const frOption = wrapper.find('[data-testid="lang-option-fr"]')
    const enOption = wrapper.find('[data-testid="lang-option-en"]')
    expect(frOption.exists()).toBe(true)
    expect(enOption.exists()).toBe(true)
  })

  it('highlights current locale', () => {
    const wrapper = createWrapper('fr')
    const frOption = wrapper.find('[data-testid="lang-option-fr"]')
    expect(frOption.classes()).toContain('font-bold')
  })

  it('switches locale to English', async () => {
    const wrapper = createWrapper('fr')
    const enOption = wrapper.find('[data-testid="lang-option-en"]')
    await enOption.trigger('click')

    expect(wrapper.vm.$i18n.locale).toBe('en')
  })

  it('persists locale choice in localStorage', async () => {
    const wrapper = createWrapper('fr')
    const enOption = wrapper.find('[data-testid="lang-option-en"]')
    await enOption.trigger('click')

    expect(localStorage.getItem('locale')).toBe('en')
  })

  it('loads saved locale from localStorage on mount', () => {
    localStorage.setItem('locale', 'en')
    const wrapper = createWrapper('en')
    const enOption = wrapper.find('[data-testid="lang-option-en"]')
    expect(enOption.classes()).toContain('font-bold')
  })
})
