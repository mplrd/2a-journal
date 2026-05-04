import { describe, it, expect, vi, beforeEach } from 'vitest'
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
  // Source of truth for the per-test default: the component initialises
  // collapsed unless localStorage says otherwise. Each test resets the
  // storage to start from the same baseline.
  beforeEach(() => {
    localStorage.removeItem('dashboardFiltersExpanded')
  })

  async function mountAndExpand() {
    const wrapper = mountFilters()
    const toggle = wrapper.findAll('button').find((b) => b.text().includes('Filters'))
    await toggle.trigger('click')
    return wrapper
  }

  it('renders collapsed by default — body and reset hidden', () => {
    const wrapper = mountFilters()
    expect(wrapper.text()).toContain('Filters')
    expect(wrapper.findAll('button').some((b) => b.text().includes('Reset'))).toBe(false)
    expect(wrapper.find('.badge-filter-stub').exists()).toBe(false)
    expect(wrapper.find('.date-range-stub').exists()).toBe(false)
  })

  it('expands when title is clicked, revealing reset and body', async () => {
    const wrapper = await mountAndExpand()
    expect(wrapper.findAll('button').some((b) => b.text().includes('Reset'))).toBe(true)
    expect(wrapper.find('.badge-filter-stub').exists()).toBe(true)
    expect(wrapper.find('.date-range-stub').exists()).toBe(true)
  })

  it('renders a reset button but no apply button (autosubmit) when expanded', async () => {
    const wrapper = await mountAndExpand()
    const buttons = wrapper.findAll('button')
    const resetBtn = buttons.find((b) => b.text().includes('Reset'))
    const applyBtn = buttons.find((b) => b.text().includes('Apply'))
    expect(resetBtn).toBeTruthy()
    expect(applyBtn).toBeFalsy()
  })

  it('emits reset event when reset is clicked', async () => {
    const wrapper = await mountAndExpand()
    const resetBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset'))
    await resetBtn.trigger('click')
    expect(wrapper.emitted('reset')).toBeTruthy()
  })

  it('collapses on second click, hiding the body and reset again', async () => {
    const wrapper = await mountAndExpand()
    const toggle = wrapper.findAll('button').find((b) => b.text().includes('Filters'))
    await toggle.trigger('click')
    expect(wrapper.findAll('button').some((b) => b.text().includes('Reset'))).toBe(false)
    expect(wrapper.find('.badge-filter-stub').exists()).toBe(false)
    expect(wrapper.find('.date-range-stub').exists()).toBe(false)
  })
})
