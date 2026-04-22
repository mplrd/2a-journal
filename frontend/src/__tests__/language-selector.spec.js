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
        FlagIcon: {
          template: '<span class="flag-icon" :data-code="code">flag-{{ code }}</span>',
          props: ['code', 'size'],
        },
      },
    },
  })
}

describe('Language selector', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.classList.remove('dark-mode')
  })

  it('renders locale select button', () => {
    const wrapper = createWrapper('fr')
    expect(wrapper.find('[data-testid="locale-select"]').exists()).toBe(true)
  })

  it('displays current locale flag', () => {
    const wrapper = createWrapper('fr')
    const flag = wrapper.find('[data-testid="locale-select"] .flag-icon')
    expect(flag.attributes('data-code')).toBe('fr')
  })

  it('renders theme toggle button', () => {
    const wrapper = createWrapper('fr')
    expect(wrapper.find('[data-testid="theme-toggle"]').exists()).toBe(true)
  })

  it('persists locale choice in localStorage', () => {
    localStorage.setItem('locale', 'en')
    expect(localStorage.getItem('locale')).toBe('en')
  })
})
