import { describe, it, expect } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PricePointsInput from '../PricePointsInput.vue'

// PricePointsInput now derives its InputNumber locale from the active i18n
// locale (useNumberLocale), so the component needs an i18n instance to mount.
const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr: {}, en: {} } })

// Minimal InputNumber stub: renders a native input, exposes data-name and
// data-min, emits update:modelValue (null on empty string).
const InputNumberStub = {
  template:
    '<input class="input-number" :data-name="$attrs[`data-name`]" :data-min="min" :data-disabled="disabled ? \'true\' : \'false\'" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : Number($event.target.value))" />',
  props: ['modelValue', 'min', 'disabled', 'maxFractionDigits', 'mode', 'locale', 'placeholder'],
  emits: ['update:modelValue'],
}

function createWrapper(props = {}) {
  return mount(PricePointsInput, {
    props: {
      entryPrice: 18500,
      direction: 'BUY',
      mode: 'SL',
      pointsName: 'pts',
      priceName: 'prc',
      ...props,
    },
    global: {
      plugins: [i18n],
      stubs: { InputNumber: InputNumberStub },
    },
  })
}

const points = (w) => w.find('[data-name="pts"]')
const price = (w) => w.find('[data-name="prc"]')
const val = (input) => (input.element.value === '' ? null : Number(input.element.value))

describe('PricePointsInput', () => {
  describe('SL mode — loss direction, positive magnitude', () => {
    it('BUY: points 50 → price = entry - 50', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'SL' })
      await points(w).setValue(50)
      await flushPromises()
      expect(val(price(w))).toBe(18450)
    })

    it('SELL: points 50 → price = entry + 50', async () => {
      const w = createWrapper({ direction: 'SELL', mode: 'SL' })
      await points(w).setValue(50)
      await flushPromises()
      expect(val(price(w))).toBe(18550)
    })

    it('BUY: price 18430 → points = 70 (magnitude)', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'SL' })
      await price(w).setValue(18430)
      await flushPromises()
      expect(val(points(w))).toBe(70)
    })

    it('min is 0 (no negative points)', () => {
      const w = createWrapper({ mode: 'SL' })
      expect(points(w).attributes('data-min')).toBe('0')
    })
  })

  describe('TP mode — profit direction, positive magnitude', () => {
    it('BUY: points 100 → price = entry + 100', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'TP' })
      await points(w).setValue(100)
      await flushPromises()
      expect(val(price(w))).toBe(18600)
    })

    it('SELL: points 100 → price = entry - 100', async () => {
      const w = createWrapper({ direction: 'SELL', mode: 'TP' })
      await points(w).setValue(100)
      await flushPromises()
      expect(val(price(w))).toBe(18400)
    })

    it('BUY: price 18600 → points = 100', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'TP' })
      await price(w).setValue(18600)
      await flushPromises()
      expect(val(points(w))).toBe(100)
    })

    it('min is 0', () => {
      const w = createWrapper({ mode: 'TP' })
      expect(points(w).attributes('data-min')).toBe('0')
    })
  })

  describe('BE mode — signed around entry', () => {
    it('BUY: positive points → price > entry', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'BE' })
      await points(w).setValue(5)
      await flushPromises()
      expect(val(price(w))).toBe(18505)
    })

    it('BUY: negative points → price < entry', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'BE' })
      await points(w).setValue(-5)
      await flushPromises()
      expect(val(price(w))).toBe(18495)
    })

    it('SELL: positive points → price < entry', async () => {
      const w = createWrapper({ direction: 'SELL', mode: 'BE' })
      await points(w).setValue(5)
      await flushPromises()
      expect(val(price(w))).toBe(18495)
    })

    it('BUY: price > entry → positive points (signed)', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'BE' })
      await price(w).setValue(18505)
      await flushPromises()
      expect(val(points(w))).toBe(5)
    })

    it('SELL: price > entry → negative points (signed)', async () => {
      const w = createWrapper({ direction: 'SELL', mode: 'BE' })
      await price(w).setValue(18505)
      await flushPromises()
      expect(val(points(w))).toBe(-5)
    })

    it('min is -entryPrice (allows the negative keystroke)', () => {
      const w = createWrapper({ mode: 'BE', entryPrice: 18500 })
      expect(points(w).attributes('data-min')).toBe('-18500')
    })
  })

  describe('initial seeding', () => {
    it('seeds the price input from the incoming points', () => {
      const w = createWrapper({ direction: 'BUY', mode: 'SL', points: 50 })
      expect(val(price(w))).toBe(18450)
    })

    it('leaves price empty when points is null', () => {
      const w = createWrapper({ mode: 'SL', points: null })
      expect(val(price(w))).toBeNull()
    })
  })

  describe('entry change keeps points fixed (recomputes price)', () => {
    it('changing entryPrice recomputes the price, magnitude unchanged', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'SL', points: 50 })
      expect(val(price(w))).toBe(18450)
      await w.setProps({ entryPrice: 18000 })
      await flushPromises()
      expect(val(points(w))).toBe(50)
      expect(val(price(w))).toBe(17950)
    })
  })

  describe('null propagation', () => {
    it('clearing points clears price', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'SL', points: 50 })
      await points(w).setValue('')
      await flushPromises()
      expect(val(price(w))).toBeNull()
    })

    it('clearing price clears points', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'TP', points: 100 })
      await price(w).setValue('')
      await flushPromises()
      expect(val(points(w))).toBeNull()
    })
  })

  describe('two-way models', () => {
    it('editing points emits both update:points and update:price', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'SL' })
      await points(w).setValue(50)
      await flushPromises()
      expect(w.emitted('update:points').at(-1)).toEqual([50])
      expect(w.emitted('update:price').at(-1)).toEqual([18450])
    })

    it('editing price emits both update:price and update:points', async () => {
      const w = createWrapper({ direction: 'BUY', mode: 'TP' })
      await price(w).setValue(18600)
      await flushPromises()
      expect(w.emitted('update:price').at(-1)).toEqual([18600])
      expect(w.emitted('update:points').at(-1)).toEqual([100])
    })
  })

  describe('no entry price → no conversion (no crash)', () => {
    it('price stays null when entryPrice is null', async () => {
      const w = createWrapper({ entryPrice: null, mode: 'SL' })
      await points(w).setValue(50)
      await flushPromises()
      expect(val(price(w))).toBeNull()
    })
  })
})
