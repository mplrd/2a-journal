import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import CloseTradeDialog from '../CloseTradeDialog.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

function createWrapper(props = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(CloseTradeDialog, {
    props: {
      visible: true,
      loading: false,
      trade: null,
      prefill: null,
      ...props,
    },
    global: {
      plugins: [i18n, PrimeVue],
      stubs: {
        Dialog: {
          template: '<div v-if="visible" class="dialog-stub"><slot /><slot name="footer" /></div>',
          props: ['visible', 'header', 'modal', 'closable', 'style'],
        },
        InputNumber: {
          template:
            '<input class="input-number" :data-name="$attrs[`data-name`]" :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
        Select: {
          template: '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
          props: ['modelValue', 'options', 'optionLabel', 'optionValue'],
          emits: ['update:modelValue'],
        },
        Button: { template: '<button><slot />{{ label }}</button>', props: ['label', 'severity', 'loading', 'size', 'text'] },
      },
    },
  })
}

const buyTrade = {
  id: 1,
  symbol: 'NASDAQ',
  direction: 'BUY',
  entry_price: 18500,
  remaining_size: 1,
}

const sellTrade = {
  id: 2,
  symbol: 'NASDAQ',
  direction: 'SELL',
  entry_price: 18500,
  remaining_size: 1,
}

function getInput(wrapper, name) {
  return wrapper.find(`[data-name="${name}"]`)
}

describe('CloseTradeDialog — price/points sync', () => {
  describe('BUY trade', () => {
    let wrapper
    beforeEach(async () => {
      wrapper = createWrapper({ trade: buyTrade })
      await flushPromises()
    })

    it('renders both exit_price and exit_points inputs', () => {
      expect(getInput(wrapper, 'exit_price').exists()).toBe(true)
      expect(getInput(wrapper, 'exit_points').exists()).toBe(true)
    })

    it('typing exit_price updates exit_points (profit case)', async () => {
      await getInput(wrapper, 'exit_price').setValue(18550)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(50)
    })

    it('typing exit_price updates exit_points (loss case)', async () => {
      await getInput(wrapper, 'exit_price').setValue(18470)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(-30)
    })

    it('typing exit_points updates exit_price (profit case)', async () => {
      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18550)
    })

    it('typing exit_points updates exit_price (loss case)', async () => {
      await getInput(wrapper, 'exit_points').setValue(-30)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18470)
    })
  })

  describe('SELL trade', () => {
    let wrapper
    beforeEach(async () => {
      wrapper = createWrapper({ trade: sellTrade })
      await flushPromises()
    })

    it('typing exit_price below entry yields POSITIVE points (profit)', async () => {
      await getInput(wrapper, 'exit_price').setValue(18450)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(50)
    })

    it('typing exit_price above entry yields NEGATIVE points (loss)', async () => {
      await getInput(wrapper, 'exit_price').setValue(18530)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(-30)
    })

    it('typing positive points yields exit_price BELOW entry (profit)', async () => {
      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18450)
    })

    it('typing negative points yields exit_price ABOVE entry (loss)', async () => {
      await getInput(wrapper, 'exit_points').setValue(-30)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18530)
    })
  })

  describe('submission contract', () => {
    it('emits "close" with exit_price (points stays internal to the dialog)', async () => {
      const wrapper = createWrapper({ trade: buyTrade })
      await flushPromises()

      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()

      const confirm = wrapper.findAll('button').find((b) => b.text().includes('Confirm') || b.text().toLowerCase().includes('confirmer'))
      await confirm.trigger('click')

      const events = wrapper.emitted('close')
      expect(events).toBeTruthy()
      const payload = events[0][0]
      expect(payload.exit_price).toBe(18550)
      expect(payload).not.toHaveProperty('exit_points')
    })

    it('prefill with exit_price hydrates exit_points consistently', async () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: { exit_price: 18570, exit_size: 1, exit_type: 'TP', target_id: null },
      })
      await flushPromises()

      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18570)
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(70)
    })
  })
})
