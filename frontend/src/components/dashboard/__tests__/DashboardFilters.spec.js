import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import DashboardFilters from '../DashboardFilters.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      dashboard: {
        filters: 'Filters',
        filter_account: 'Filter account',
        all_accounts: 'All accounts',
        date_from: 'From',
        date_to: 'To',
        direction: 'Direction',
        all_directions: 'All directions',
        symbols: 'Symbols',
        setups: 'Setups',
        apply_filters: 'Apply',
        reset_filters: 'Reset',
      },
    },
  },
})

vi.mock('@/services/accounts', () => ({
  accountsService: { list: vi.fn().mockResolvedValue({ data: [] }) },
}))
vi.mock('@/services/symbols', () => ({
  symbolsService: { list: vi.fn().mockResolvedValue({ data: [] }) },
}))
vi.mock('@/services/setups', () => ({
  setupsService: { list: vi.fn().mockResolvedValue({ data: [] }) },
}))

function mountFilters() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return mount(DashboardFilters, {
    global: {
      plugins: [pinia, i18n],
      stubs: {
        MultiSelect: { template: '<div class="multiselect-stub" />', props: ['modelValue'] },
        BadgeFilter: { template: '<div class="badge-filter-stub" />', props: ['modelValue', 'options', 'multi'] },
        DateRangePicker: { template: '<div class="date-range-stub" />', props: ['from', 'to'] },
        Button: {
          template: '<button @click="$emit(\'click\')">{{ label }}</button>',
          props: ['label'],
          emits: ['click'],
        },
      },
    },
  })
}

describe('DashboardFilters', () => {
  it('renders only a reset button (autosubmit, no apply)', () => {
    const wrapper = mountFilters()
    const buttons = wrapper.findAll('button')
    expect(buttons.length).toBe(1)
    expect(buttons[0].text()).toContain('Reset')
  })

  it('emits reset event', async () => {
    const wrapper = mountFilters()
    const resetBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset'))
    await resetBtn.trigger('click')
    expect(wrapper.emitted('reset')).toBeTruthy()
  })

  it('renders title', () => {
    const wrapper = mountFilters()
    expect(wrapper.text()).toContain('Filters')
  })
})
