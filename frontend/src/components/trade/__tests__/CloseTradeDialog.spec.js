import { describe, it, expect, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import CloseTradeDialog from '../CloseTradeDialog.vue'
import { ExitType } from '@/constants/enums'
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
          template:
            '<div v-if="visible" class="dialog-stub" :data-header="header"><slot /><slot name="footer" /></div>',
          props: ['visible', 'header', 'modal', 'closable', 'style'],
        },
        InputNumber: {
          template:
            '<input class="input-number" :data-name="$attrs[`data-name`]" :data-disabled="disabled ? \'true\' : \'false\'" :value="modelValue" @input="$emit(\'update:modelValue\', Number($event.target.value))" />',
          props: ['modelValue', 'disabled'],
          emits: ['update:modelValue'],
        },
        Button: {
          template: '<button><slot />{{ label }}</button>',
          props: ['label', 'severity', 'loading', 'size', 'text'],
        },
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

function findConfirmButton(wrapper) {
  return wrapper.findAll('button').find((b) => {
    const text = b.text().toLowerCase()
    return text.includes('confirm') || text.includes('confirmer')
  })
}

describe('CloseTradeDialog', () => {
  describe('header reflects exit_type', () => {
    it('SL → "Sortie au Stop Loss"', () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: { exit_type: ExitType.SL },
      })
      expect(wrapper.find('.dialog-stub').attributes('data-header')).toMatch(/Stop Loss/i)
    })

    it('BE → "Sortie à Breakeven"', () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: { exit_type: ExitType.BE },
      })
      expect(wrapper.find('.dialog-stub').attributes('data-header')).toMatch(/Breakeven/i)
    })

    it('TP → "Sortie au Take Profit"', () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: { exit_type: ExitType.TP, exit_price: 18600 },
      })
      expect(wrapper.find('.dialog-stub').attributes('data-header')).toMatch(/Take Profit/i)
    })

    it('MANUAL → "Stop Win"', () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: { exit_type: ExitType.MANUAL },
      })
      expect(wrapper.find('.dialog-stub').attributes('data-header')).toMatch(/Stop Win/i)
    })
  })

  describe('SL (Stop Loss) — points = magnitude of the loss', () => {
    it('BUY: typing points = 50 yields exit_price = entry - 50', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.SL } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18450)
    })

    it('SELL: typing points = 50 yields exit_price = entry + 50', async () => {
      const wrapper = createWrapper({ trade: sellTrade, prefill: { exit_type: ExitType.SL } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18550)
    })

    it('BUY: typing exit_price = 18430 yields points = 70 (magnitude)', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.SL } })
      await flushPromises()
      await getInput(wrapper, 'exit_price').setValue(18430)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(70)
    })
  })

  describe('Stop Win (MANUAL) — points = magnitude of the profit', () => {
    it('BUY: typing points = 50 yields exit_price = entry + 50', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.MANUAL } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18550)
    })

    it('SELL: typing points = 50 yields exit_price = entry - 50', async () => {
      const wrapper = createWrapper({ trade: sellTrade, prefill: { exit_type: ExitType.MANUAL } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18450)
    })
  })

  describe('BE — prefilled at entry but editable (slippage/spread)', () => {
    it('prefills exit_price = entry and points = 0', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE } })
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18500)
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(0)
    })

    it('inputs stay editable (NOT disabled)', () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE } })
      expect(getInput(wrapper, 'exit_price').attributes('data-disabled')).toBe('false')
      expect(getInput(wrapper, 'exit_points').attributes('data-disabled')).toBe('false')
    })

    it('BUY: positive points (favorable slippage) → exit_price > entry', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(5)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18505)
    })

    it('BUY: negative points (unfavorable slippage) → exit_price < entry', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(-5)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18495)
    })

    it('SELL: positive points (favorable slippage) → exit_price < entry', async () => {
      const wrapper = createWrapper({ trade: sellTrade, prefill: { exit_type: ExitType.BE } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(5)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18495)
    })

    it('BUY: typing exit_price > entry → positive points (signed)', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE } })
      await flushPromises()
      await getInput(wrapper, 'exit_price').setValue(18505)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(5)
    })

    it('SELL: typing exit_price > entry → negative points (signed, unfavorable)', async () => {
      const wrapper = createWrapper({ trade: sellTrade, prefill: { exit_type: ExitType.BE } })
      await flushPromises()
      await getInput(wrapper, 'exit_price').setValue(18505)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(-5)
    })
  })

  describe('SL prefill from trade.sl_points', () => {
    it('BUY: prefills exit_price = entry - sl_points and points = sl_points', () => {
      const wrapper = createWrapper({
        trade: { ...buyTrade, sl_points: 50 },
        prefill: { exit_type: ExitType.SL },
      })
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18450)
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(50)
    })

    it('SELL: prefills exit_price = entry + sl_points and points = sl_points', () => {
      const wrapper = createWrapper({
        trade: { ...sellTrade, sl_points: 50 },
        prefill: { exit_type: ExitType.SL },
      })
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18550)
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(50)
    })

    it('user can adjust the prefilled magnitude', async () => {
      const wrapper = createWrapper({
        trade: { ...buyTrade, sl_points: 50 },
        prefill: { exit_type: ExitType.SL },
      })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(75)
      await flushPromises()
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18425)
    })

    it('SL without sl_points on the trade leaves fields empty', () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: { exit_type: ExitType.SL },
      })
      expect(getInput(wrapper, 'exit_price').element.value).toBe('')
      expect(getInput(wrapper, 'exit_points').element.value).toBe('')
    })
  })

  describe('Stop Win (MANUAL) does not prefill', () => {
    it('exit_price and points stay empty', () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: { exit_type: ExitType.MANUAL },
      })
      expect(getInput(wrapper, 'exit_price').element.value).toBe('')
      expect(getInput(wrapper, 'exit_points').element.value).toBe('')
    })
  })

  describe('TP prefill (from "next objective" smart button)', () => {
    it('hydrates exit_price and recomputes points as magnitude', () => {
      const wrapper = createWrapper({
        trade: buyTrade,
        prefill: {
          exit_type: ExitType.TP,
          exit_price: 18600,
          exit_size: 0.5,
          target_id: 'tp1',
        },
      })
      expect(Number(getInput(wrapper, 'exit_price').element.value)).toBe(18600)
      expect(Number(getInput(wrapper, 'exit_points').element.value)).toBe(100)
    })
  })

  describe('submission contract', () => {
    it('emits exit_type and exit_price; exit_points stays internal', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.SL } })
      await flushPromises()
      await getInput(wrapper, 'exit_points').setValue(50)
      await flushPromises()
      await findConfirmButton(wrapper).trigger('click')

      const payload = wrapper.emitted('close')[0][0]
      expect(payload.exit_type).toBe(ExitType.SL)
      expect(payload.exit_price).toBe(18450)
      expect(payload).not.toHaveProperty('exit_points')
    })

    it('BE emits exit_price = entry', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE } })
      await flushPromises()
      await findConfirmButton(wrapper).trigger('click')

      const payload = wrapper.emitted('close')[0][0]
      expect(payload.exit_type).toBe(ExitType.BE)
      expect(payload.exit_price).toBe(18500)
    })
  })

  // "I protect but I don't lighten": a BE configured with a planned exit size,
  // but at execution the trader keeps the full position. A zero-size exit is
  // not a partial fill, so we route to the dedicated "mark BE reached" action
  // instead of close (which would reject invalid_exit_size on the backend).
  describe('BE without lightening (exit_size = 0)', () => {
    it('emits "mark-be" instead of "close" when BE is prefilled with size 0', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE, exit_size: 0 } })
      await flushPromises()
      await findConfirmButton(wrapper).trigger('click')

      expect(wrapper.emitted('mark-be')).toBeTruthy()
      expect(wrapper.emitted('close')).toBeFalsy()
    })

    it('still emits "close" when BE keeps a positive exit size', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE, exit_size: 0.5 } })
      await flushPromises()
      await findConfirmButton(wrapper).trigger('click')

      expect(wrapper.emitted('close')).toBeTruthy()
      expect(wrapper.emitted('mark-be')).toBeFalsy()
      expect(wrapper.emitted('close')[0][0].exit_size).toBe(0.5)
    })

    it('routes to "mark-be" when the user clears the size to 0 on a BE exit', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.BE, exit_size: 0.5 } })
      await flushPromises()
      await getInput(wrapper, 'exit_size').setValue(0)
      await flushPromises()
      await findConfirmButton(wrapper).trigger('click')

      expect(wrapper.emitted('mark-be')).toBeTruthy()
      expect(wrapper.emitted('close')).toBeFalsy()
    })

    it('does NOT special-case a zero-size non-BE exit (only BE protects)', async () => {
      const wrapper = createWrapper({ trade: buyTrade, prefill: { exit_type: ExitType.MANUAL, exit_size: 0 } })
      await flushPromises()
      await findConfirmButton(wrapper).trigger('click')

      expect(wrapper.emitted('close')).toBeTruthy()
      expect(wrapper.emitted('mark-be')).toBeFalsy()
    })
  })
})
