import { describe, it, expect } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import BalanceAdjustmentInput from '../BalanceAdjustmentInput.vue'

// The component derives its InputNumber locale from the active i18n locale
// (useNumberLocale), so an i18n instance is required to mount it.
const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr: {}, en: {} } })

function createWrapper(props = {}) {
  return mount(BalanceAdjustmentInput, {
    props: { base: 10000, amount: null, ...props },
    global: {
      plugins: [i18n],
      stubs: {
        InputNumber: {
          template:
            '<input class="input-number" :data-name="$attrs[`data-name`]" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value === \'\' ? null : Number($event.target.value))" />',
          props: ['modelValue'],
          emits: ['update:modelValue'],
        },
      },
    },
  })
}

function input(wrapper, name) {
  return wrapper.find(`[data-name="${name}"]`)
}

describe('BalanceAdjustmentInput', () => {
  it('seeds the target field with the current balance (base) and a null delta', () => {
    const wrapper = createWrapper({ base: 10000, amount: null })
    expect(Number(input(wrapper, 'target').element.value)).toBe(10000)
  })

  it('typing a target balance derives the signed delta', async () => {
    const wrapper = createWrapper({ base: 10000 })
    await input(wrapper, 'target').setValue(10018)
    await flushPromises()
    expect(wrapper.props('amount') ?? wrapper.emitted('update:amount').at(-1)[0]).toBe(18)
  })

  it('typing a negative-going target derives a negative delta', async () => {
    const wrapper = createWrapper({ base: 10000 })
    await input(wrapper, 'target').setValue(9950)
    await flushPromises()
    expect(wrapper.emitted('update:amount').at(-1)[0]).toBe(-50)
  })

  it('typing a delta derives the target balance', async () => {
    const wrapper = createWrapper({ base: 10000 })
    await input(wrapper, 'adjustment').setValue(18)
    await flushPromises()
    expect(Number(input(wrapper, 'target').element.value)).toBe(10018)
  })

  it('typing a negative delta lowers the target balance', async () => {
    const wrapper = createWrapper({ base: 10000 })
    await input(wrapper, 'adjustment').setValue(-250.5)
    await flushPromises()
    expect(Number(input(wrapper, 'target').element.value)).toBe(9749.5)
  })

  it('reflects an external delta into the target field', async () => {
    const wrapper = createWrapper({ base: 10000, amount: 18 })
    await flushPromises()
    expect(Number(input(wrapper, 'target').element.value)).toBe(10018)
  })
})
