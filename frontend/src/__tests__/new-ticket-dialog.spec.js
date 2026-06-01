import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import NewTicketDialog from '@/components/support/NewTicketDialog.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

// The new-ticket form adapts to the chosen type: a bug asks for expected
// behavior + reproduction steps, a feature asks for benefit + imagined
// solution, support asks for nothing extra. The description (body) is always
// the single required field. These tests pin that reactive behavior.

function createWrapper(locale = 'en') {
  const i18n = createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { fr, en } })
  return mount(NewTicketDialog, {
    props: { visible: true },
    global: {
      plugins: [createPinia(), i18n, PrimeVue, ToastService],
      stubs: {
        // Render Dialog inline so the form is in the DOM; stub PrimeVue inputs
        // that touch browser APIs (Select uses matchMedia, absent in jsdom).
        Dialog: { template: '<div><slot /></div>' },
        Select: { template: '<select></select>' },
        Textarea: { template: '<textarea></textarea>' },
        InputText: { template: '<input />' },
        Button: { template: '<button><slot /></button>' },
        Message: { template: '<div><slot /></div>' },
      },
    },
  })
}

describe('NewTicketDialog — type-specific structured fields', () => {
  beforeEach(() => vi.restoreAllMocks())

  it('shows no detail fields for SUPPORT (default type)', () => {
    const wrapper = createWrapper()
    expect(wrapper.find('[data-testid="detail-field-expected_behavior"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="detail-field-benefit"]').exists()).toBe(false)
    // Description (body) is always present.
    expect(wrapper.find('[data-testid="ticket-body"]').exists()).toBe(true)
  })

  it('shows bug fields when type is BUG', async () => {
    const wrapper = createWrapper()
    wrapper.vm.form.type = 'BUG'
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="detail-field-expected_behavior"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="detail-field-reproduction_steps"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="detail-field-benefit"]').exists()).toBe(false)
  })

  it('shows feature fields when type is FEATURE', async () => {
    const wrapper = createWrapper()
    wrapper.vm.form.type = 'FEATURE'
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="detail-field-benefit"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="detail-field-imagined_solution"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="detail-field-expected_behavior"]').exists()).toBe(false)
  })
})
