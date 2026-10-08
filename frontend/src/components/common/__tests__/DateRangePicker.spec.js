import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import DateRangePicker from '../DateRangePicker.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      common: {
        from: 'From',
        to: 'To',
        range: {
          placeholder: 'Any date',
          last_7_days: 'Last 7 days',
          last_30_days: 'Last 30 days',
          this_month: 'This month',
          this_quarter: 'This quarter',
          year_to_date: 'Year to date',
          clear: 'Clear',
        },
      },
    },
  },
})

function mountOn(date) {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(date)
  return mount(DateRangePicker, {
    global: {
      plugins: [i18n],
      stubs: {
        Popover: { template: '<div><slot /></div>', methods: { hide() {}, toggle() {} } },
        DatePicker: true,
      },
    },
  })
}

const preset = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)
const lastEmitted = (wrapper, event) => wrapper.emitted(event).at(-1)[0]

describe('DateRangePicker — presets', () => {
  afterEach(() => vi.useRealTimers())

  it('applies this month from its first day to today', async () => {
    const wrapper = mountOn(new Date(2026, 10, 18, 12))

    await preset(wrapper, 'This month').trigger('click')

    expect(lastEmitted(wrapper, 'update:from')).toEqual(new Date(2026, 10, 1))
    expect(lastEmitted(wrapper, 'update:to').getDate()).toBe(18)
    expect(wrapper.find('button').text()).toBe('This month')
  })

  it('applies this quarter from the first day of the quarter to today', async () => {
    const wrapper = mountOn(new Date(2026, 10, 18, 12))

    await preset(wrapper, 'This quarter').trigger('click')

    expect(lastEmitted(wrapper, 'update:from')).toEqual(new Date(2026, 9, 1))
    expect(wrapper.find('button').text()).toBe('This quarter')
  })

  it('names the quarter as chosen when it started the same day as the month', async () => {
    // 8 October: the quarter and the month both run from 1 October.
    const wrapper = mountOn(new Date(2026, 9, 8, 12))

    await preset(wrapper, 'This quarter').trigger('click')

    expect(wrapper.find('button').text()).toBe('This quarter')
  })
})

describe('DateRangePicker — presets covering the same days', () => {
  afterEach(() => vi.useRealTimers())

  it('highlights only the preset clicked', async () => {
    const wrapper = mountOn(new Date(2026, 9, 8, 12))

    await preset(wrapper, 'This quarter').trigger('click')

    expect(preset(wrapper, 'This quarter').classes()).toContain('bg-brand-green-700')
    expect(preset(wrapper, 'This month').classes()).not.toContain('bg-brand-green-700')
  })

  it('names the month again once it is clicked back', async () => {
    const wrapper = mountOn(new Date(2026, 9, 8, 12))

    await preset(wrapper, 'This quarter').trigger('click')
    await preset(wrapper, 'This month').trigger('click')

    expect(wrapper.find('button').text()).toBe('This month')
  })
})
