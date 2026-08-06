import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import TradeForm from '../TradeForm.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

// Editable price ⇄ points on the objectives (SL / BE / TP) flows through the
// shared PricePointsInput. These tests cover the TradeForm integration:
// seeding the prices on open, and the save contract (backend reads points).
vi.mock('@/services/symbols', () => ({
  symbolsService: { list: vi.fn(), create: vi.fn() },
}))

const InputNumberStub = {
  template:
    '<input class="input-number" :data-name="$attrs[`data-name`]" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : Number($event.target.value))" />',
  props: ['modelValue', 'min', 'disabled', 'maxFractionDigits', 'mode', 'locale', 'placeholder'],
  emits: ['update:modelValue'],
}

const passthroughStub = (tag = 'div') => ({
  template: `<${tag}><slot /><slot name="footer" /></${tag}>`,
  props: ['visible', 'modelValue'],
})

function createWrapper(props = {}) {
  setActivePinia(createPinia())
  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })

  return mount(TradeForm, {
    props: { visible: false, accounts: [], symbols: [], setups: [], customFieldDefinitions: [], loading: false, trade: null, ...props },
    global: {
      plugins: [i18n, PrimeVue, ToastService],
      directives: { tooltip: {} },
      stubs: {
        Dialog: passthroughStub(),
        InputNumber: InputNumberStub,
        InputText: { template: '<input />', props: ['modelValue'] },
        Textarea: { template: '<textarea />', props: ['modelValue'] },
        AutoComplete: { template: '<input />', props: ['modelValue', 'suggestions'] },
        Select: { template: '<select />', props: ['modelValue', 'options'] },
        ToggleSwitch: { template: '<input type="checkbox" />', props: ['modelValue'] },
        DatePicker: { template: '<input />', props: ['modelValue'] },
        SymbolForm: true,
        Button: {
          template: '<button @click="$emit(`click`)"><slot />{{ label }}</button>',
          props: ['label', 'severity', 'loading', 'size', 'text', 'icon'],
        },
      },
    },
  })
}

const input = (w, name) => w.find(`[data-name="${name}"]`)
const val = (el) => (el.element.value === '' ? null : Number(el.element.value))

const buyTrade = {
  id: 1,
  account_id: 1,
  symbol: 'NASDAQ',
  direction: 'BUY',
  status: 'OPEN',
  entry_price: 18500,
  size: 1,
  sl_points: 50,
  be_points: 10,
  be_size: 0.5,
  targets: [{ id: 'tp1', label: 'TP1', points: 100, size: 0.5 }],
  opened_at: '2026-05-01 10:00:00',
}

async function openWith(trade) {
  const w = createWrapper({ trade })
  await w.setProps({ visible: true })
  await flushPromises()
  return w
}

function saveButton(w) {
  return w.findAll('button').find((b) => /enregistrer/i.test(b.text()))
}

describe('TradeForm — objectives price ⇄ points', () => {
  beforeEach(() => setActivePinia(createPinia()))

  describe('seeds prices from stored points on open (BUY)', () => {
    it('SL price = entry - sl_points', async () => {
      const w = await openWith(buyTrade)
      expect(val(input(w, 'sl_price'))).toBe(18450)
    })

    it('BE price = entry + be_points (planned, profit direction)', async () => {
      const w = await openWith(buyTrade)
      expect(val(input(w, 'be_price'))).toBe(18510)
    })

    it('target price = entry + target.points', async () => {
      const w = await openWith(buyTrade)
      expect(val(input(w, 'tp_price_0'))).toBe(18600)
    })
  })

  describe('seeds from a stored SL price when no points are recorded', () => {
    // A broker reports the stop loss as a LEVEL, never as a distance, so a
    // synced trade lands with sl_price set and sl_points NULL. The form drove
    // everything off the points, so Number(null) === 0 blanked the pair and the
    // stop loss simply disappeared on edit — while sitting in the database all
    // along.
    const syncedBuy = {
      id: 2,
      account_id: 1,
      symbol: 'GER40.cash',
      direction: 'BUY',
      status: 'OPEN',
      entry_price: 26386.34,
      size: 2.5,
      sl_points: null,
      sl_price: 26300,
      targets: [],
      opened_at: '2026-08-05 07:29:00',
    }

    it('shows the stored SL price as-is', async () => {
      const w = await openWith(syncedBuy)
      expect(val(input(w, 'sl_price'))).toBe(26300)
    })

    it('derives the points from it so both inputs agree', async () => {
      const w = await openWith(syncedBuy)
      expect(val(input(w, 'sl_points'))).toBeCloseTo(86.34, 2)
    })

    it('measures the distance the other way on a SELL', async () => {
      const w = await openWith({ ...syncedBuy, direction: 'SELL', sl_price: 26450 })
      expect(val(input(w, 'sl_price'))).toBe(26450)
      expect(val(input(w, 'sl_points'))).toBeCloseTo(63.66, 2)
    })

    it('invents no stop when neither side is recorded', async () => {
      // The displayed price still falls back to the entry here — that comes
      // from PricePointsInput deriving it from zero points, and predates this.
      // What matters is that the derivation above adds no distance of its own.
      const w = await openWith({ ...syncedBuy, sl_price: null })
      expect(val(input(w, 'sl_points'))).toBe(0)
    })
  })

  describe('editing a price updates the points side', () => {
    it('typing an SL price recomputes sl_points (magnitude)', async () => {
      const w = await openWith(buyTrade)
      await input(w, 'sl_price').setValue(18430)
      await flushPromises()
      expect(val(input(w, 'sl_points'))).toBe(70)
    })

    it('typing a target price recomputes its points', async () => {
      const w = await openWith(buyTrade)
      await input(w, 'tp_price_0').setValue(18650)
      await flushPromises()
      expect(val(input(w, 'tp_points_0'))).toBe(150)
    })
  })

  describe('save contract — backend reads points, price companions stripped', () => {
    it('emits points; sl_price / be_price are not shipped', async () => {
      const w = await openWith(buyTrade)
      await input(w, 'sl_price').setValue(18430) // 70 pts
      await flushPromises()
      await saveButton(w).trigger('click')

      const payload = w.emitted('save').at(-1)[0]
      expect(payload.sl_points).toBe(70)
      expect(payload.be_points).toBe(10)
      expect(payload).not.toHaveProperty('sl_price')
      expect(payload).not.toHaveProperty('be_price')
    })

    it('targets carry points + derived price', async () => {
      const w = await openWith(buyTrade)
      await saveButton(w).trigger('click')

      const payload = w.emitted('save').at(-1)[0]
      expect(payload.targets[0].points).toBe(100)
      expect(payload.targets[0].price).toBe(18600)
    })
  })
})
