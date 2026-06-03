import { describe, it, expect } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import AdjustBalanceDialog from '../AdjustBalanceDialog.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

function createWrapper(props = {}) {
  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })

  return mount(AdjustBalanceDialog, {
    props: {
      visible: true,
      loading: false,
      account: { id: 1, name: 'FTMO', current_capital: 10000, currency: 'EUR' },
      adjustments: [],
      ...props,
    },
    global: {
      plugins: [i18n, PrimeVue],
      stubs: {
        Dialog: {
          template: '<div v-if="visible" class="dialog-stub"><slot /><slot name="footer" /></div>',
          props: ['visible'],
        },
        InputNumber: {
          template:
            '<input class="input-number" :data-name="$attrs[`data-name`]" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : Number($event.target.value))" />',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
        InputText: {
          template: '<input class="input-text" :data-name="$attrs[`data-name`]" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
        Button: {
          template: '<button :data-testid="$attrs[`data-testid`]" @click="$emit(\'click\')"><slot />{{ label }}</button>',
          props: ['label'],
          emits: ['click'],
        },
      },
    },
  })
}

const input = (wrapper, name) => wrapper.find(`[data-name="${name}"]`)
const confirmButton = (wrapper) =>
  wrapper.findAll('button').find((b) => b.text().toLowerCase().includes('confirm'))

describe('AdjustBalanceDialog', () => {
  it('shows the current computed balance as the base', () => {
    const wrapper = createWrapper()
    expect(Number(input(wrapper, 'target').element.value)).toBe(10000)
  })

  it('emits submit with the signed delta and reason', async () => {
    const wrapper = createWrapper()
    await input(wrapper, 'target').setValue(10018)
    await input(wrapper, 'reason').setValue('Frais oubliés')
    await flushPromises()
    await confirmButton(wrapper).trigger('click')

    const payload = wrapper.emitted('submit')[0][0]
    expect(payload.amount).toBe(18)
    expect(payload.reason).toBe('Frais oubliés')
  })

  it('does not emit submit when the delta is zero/empty', async () => {
    const wrapper = createWrapper()
    await flushPromises()
    await confirmButton(wrapper).trigger('click')
    expect(wrapper.emitted('submit')).toBeFalsy()
  })

  it('renders the adjustment history', () => {
    const wrapper = createWrapper({
      adjustments: [
        { id: 1, amount: 18, reason: 'a', adjusted_at: '2026-02-01 10:00:00' },
        { id: 2, amount: -5, reason: 'b', adjusted_at: '2026-01-01 10:00:00' },
      ],
    })
    expect(wrapper.findAll('[data-testid="adjustment-row"]')).toHaveLength(2)
  })

  it('emits delete-adjustment with the row id', async () => {
    const wrapper = createWrapper({
      adjustments: [{ id: 7, amount: 18, reason: 'a', adjusted_at: '2026-02-01 10:00:00' }],
    })
    await wrapper.find('[data-testid="delete-adjustment"]').trigger('click')
    expect(wrapper.emitted('delete-adjustment')[0][0]).toBe(7)
  })
})
