import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import App from '../App.vue'

function mountApp(pinia) {
  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr: {}, en: {} } })
  return mount(App, {
    global: {
      plugins: [pinia, i18n],
      stubs: { RouterView: true, Toast: true, ConfirmDialog: true },
    },
  })
}

describe('App', () => {
  it('renders RouterView', () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const wrapper = mountApp(pinia)
    expect(wrapper.findComponent({ name: 'RouterView' }).exists()).toBe(true)
  })

  it('starts cross-tab auth sync on mount', () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    const spy = vi.spyOn(useAuthStore(), 'startCrossTabSync')
    mountApp(pinia)
    expect(spy).toHaveBeenCalled()
  })
})
