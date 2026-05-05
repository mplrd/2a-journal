import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import SetupCombinationDialog from '../SetupCombinationDialog.vue'
import { useStatsStore } from '@/stores/stats'
import { useSetupsStore } from '@/stores/setups'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

vi.mock('@/services/stats', () => ({
  statsService: {
    getDashboard: vi.fn(),
    getCharts: vi.fn(),
    getBySymbol: vi.fn(),
    getByDirection: vi.fn(),
    getBySetup: vi.fn(),
    getByPeriod: vi.fn(),
    getRrDistribution: vi.fn(),
    getHeatmap: vi.fn(),
    getBySession: vi.fn(),
    getByAccount: vi.fn(),
    getByAccountType: vi.fn(),
    getOpenTrades: vi.fn(),
    getDailyPnl: vi.fn(),
    analyzeSetupCombinations: vi.fn(),
  },
}))

vi.mock('@/services/setups', () => ({
  setupsService: { list: vi.fn() },
}))

import { statsService } from '@/services/stats'

function createWrapper(props = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(SetupCombinationDialog, {
    props: {
      visible: false,
      ...props,
    },
    global: {
      plugins: [i18n, PrimeVue],
      stubs: {
        Dialog: {
          template: '<div v-if="visible" class="dialog-stub"><slot /></div>',
          props: ['visible', 'header', 'modal', 'closable', 'style', 'dismissableMask'],
        },
        MultiSelect: {
          template: `
            <select
              class="multiselect-stub"
              multiple
              :value="modelValue"
              @change="$emit('update:modelValue', Array.from($event.target.selectedOptions).map(o => Number(o.value)))"
            >
              <option v-for="o in options" :key="o.id" :value="o.id">{{ o.label }}</option>
            </select>
          `,
          props: ['modelValue', 'options', 'optionLabel', 'optionValue', 'placeholder', 'filter', 'showClear', 'disabled'],
          emits: ['update:modelValue'],
        },
        Button: {
          template: '<button :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\')">{{ label }}<slot /></button>',
          props: ['label', 'icon', 'severity', 'size', 'outlined', 'disabled', 'loading', 'text', 'aria-label'],
          emits: ['click'],
          inheritAttrs: false,
        },
        Chart: {
          template: '<div class="chart-stub" :data-type="type" :data-labels="JSON.stringify(data?.labels || [])" />',
          props: ['type', 'data', 'options'],
        },
      },
    },
  })
}

function selectInRow(rowEl, ids) {
  const ms = rowEl.querySelector('.multiselect-stub')
  Array.from(ms.options).forEach((opt) => (opt.selected = ids.includes(Number(opt.value))))
  ms.dispatchEvent(new Event('change'))
}

function fakeBaseline(overrides = {}) {
  return {
    total_trades: 48,
    wins: 29,
    losses: 19,
    win_rate: 60,
    total_pnl: 2200,
    avg_rr: 0.83,
    profit_factor: 1.5,
    ...overrides,
  }
}

describe('SetupCombinationDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.useFakeTimers()
    vi.restoreAllMocks()
    statsService.analyzeSetupCombinations.mockReset()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('does not render dialog content when not visible', () => {
    const wrapper = createWrapper({ visible: false })
    expect(wrapper.find('.dialog-stub').exists()).toBe(false)
    expect(statsService.analyzeSetupCombinations).not.toHaveBeenCalled()
  })

  it('fetches the baseline alone as soon as the modal opens', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: { baseline: fakeBaseline(), combinations: [], match: 'all' },
    })

    const wrapper = createWrapper({ visible: false })
    await wrapper.setProps({ visible: true })
    await vi.runAllTimersAsync()

    expect(statsService.analyzeSetupCombinations).toHaveBeenCalledTimes(1)
    expect(statsService.analyzeSetupCombinations).toHaveBeenCalledWith({
      combinations: [],
      match: 'all',
    })
  })

  it('renders one combination row by default and an "Add combination" button', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: { baseline: fakeBaseline(), combinations: [], match: 'all' },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()

    expect(wrapper.findAll('.multiselect-stub')).toHaveLength(1)
    const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Ajouter une combinaison'))
    expect(addBtn).toBeDefined()
  })

  it('does NOT render an "Analyze" button (auto-fetch model)', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: { baseline: fakeBaseline(), combinations: [], match: 'all' },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()

    const analyzeBtn = wrapper.findAll('button').find((b) => b.text().includes('Analyser'))
    expect(analyzeBtn).toBeUndefined()
  })

  it('lets the user add and remove combination rows', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: { baseline: fakeBaseline(), combinations: [], match: 'all' },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()

    const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Ajouter une combinaison'))
    await addBtn.trigger('click')
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('.multiselect-stub')).toHaveLength(2)

    const removeBtns = wrapper.findAll('[data-test="remove-combo"]')
    expect(removeBtns.length).toBeGreaterThanOrEqual(1)
    await removeBtns[0].trigger('click')
    await wrapper.vm.$nextTick()
    expect(wrapper.findAll('.multiselect-stub')).toHaveLength(1)
  })

  it('auto-refetches when a row is filled, sending the active combinations', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [
      { id: 1, label: 'Breakout', category: 'pattern' },
      { id: 2, label: 'Trend Follow', category: 'timeframe' },
    ]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: {
        baseline: fakeBaseline(),
        combinations: [
          {
            setup_ids: [1, 2],
            setups: ['Breakout', 'Trend Follow'],
            stats: { total_trades: 9, wins: 8, losses: 0, win_rate: 89, total_pnl: 2050, avg_rr: 2.13, profit_factor: 8 },
          },
        ],
        match: 'all',
      },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()
    statsService.analyzeSetupCombinations.mockClear()

    selectInRow(wrapper.findAll('.combo-row')[0].element, [1, 2])
    await wrapper.vm.$nextTick()
    await vi.runAllTimersAsync()

    expect(statsService.analyzeSetupCombinations).toHaveBeenCalledWith({
      combinations: [{ setup_ids: [1, 2] }],
      match: 'all',
    })
  })

  it('debounces rapid changes to a single fetch', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [
      { id: 1, label: 'Breakout', category: 'pattern' },
      { id: 2, label: 'Trend Follow', category: 'timeframe' },
    ]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: { baseline: fakeBaseline(), combinations: [], match: 'all' },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()
    statsService.analyzeSetupCombinations.mockClear()

    const row = wrapper.findAll('.combo-row')[0].element
    selectInRow(row, [1])
    await wrapper.vm.$nextTick()
    selectInRow(row, [1, 2])
    await wrapper.vm.$nextTick()
    selectInRow(row, [2])
    await wrapper.vm.$nextTick()

    // Before the debounce window has elapsed: no fetch yet.
    expect(statsService.analyzeSetupCombinations).not.toHaveBeenCalled()

    await vi.runAllTimersAsync()

    // After the debounce: a single fetch with the final state ([2]).
    expect(statsService.analyzeSetupCombinations).toHaveBeenCalledTimes(1)
    expect(statsService.analyzeSetupCombinations).toHaveBeenCalledWith({
      combinations: [{ setup_ids: [2] }],
      match: 'all',
    })
  })

  it('inherits global stats filters in the payload', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    const statsStore = useStatsStore()
    statsStore.filters = { account_id: 7, date_from: '2026-01-01' }
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: { baseline: fakeBaseline(), combinations: [], match: 'all' },
    })

    const wrapper = createWrapper({ visible: false })
    await wrapper.setProps({ visible: true })
    await vi.runAllTimersAsync()

    expect(statsService.analyzeSetupCombinations).toHaveBeenCalledWith({
      combinations: [],
      match: 'all',
      account_id: 7,
      date_from: '2026-01-01',
    })
  })

  it('renders 3 horizontal bar charts whose Y-axis labels include the trade counts', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: {
        baseline: fakeBaseline({ total_trades: 48 }),
        combinations: [
          {
            setup_ids: [1],
            setups: ['Breakout'],
            stats: { total_trades: 9, wins: 8, losses: 0, win_rate: 89, total_pnl: 2050, avg_rr: 2.13, profit_factor: 8 },
          },
        ],
        match: 'all',
      },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()

    const charts = wrapper.findAll('.chart-stub')
    expect(charts.length).toBe(3)
    charts.forEach((c) => expect(c.attributes('data-type')).toBe('bar'))

    // Trade counts surface inside the labels (axis), not as separate badges.
    const allLabels = charts
      .map((c) => JSON.parse(c.attributes('data-labels')))
      .flat()
      .join(' ')
    expect(allLabels).toMatch(/\(48\)/) // baseline trade count
    expect(allLabels).toMatch(/\(9\)/) // combination trade count
  })

  it('does NOT render a stand-alone color legend or "too few trades" warning', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: {
        baseline: fakeBaseline(),
        combinations: [
          {
            setup_ids: [1],
            setups: ['Breakout'],
            stats: { total_trades: 1, wins: 1, losses: 0, win_rate: 100, total_pnl: 50, avg_rr: 2, profit_factor: null },
          },
        ],
        match: 'all',
      },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()

    expect(wrapper.find('[data-test="result-legend"]').exists()).toBe(false)
    expect(wrapper.text()).not.toMatch(/Échantillon trop petit|trop peu de trades/i)
  })

  it('uses the 2-column layout container', async () => {
    const setupsStore = useSetupsStore()
    setupsStore.setups = [{ id: 1, label: 'Breakout', category: 'pattern' }]
    statsService.analyzeSetupCombinations.mockResolvedValue({
      success: true,
      data: { baseline: fakeBaseline(), combinations: [], match: 'all' },
    })

    const wrapper = createWrapper({ visible: true })
    await vi.runAllTimersAsync()

    expect(wrapper.find('[data-test="dialog-grid"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="config-column"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="charts-column"]').exists()).toBe(true)
  })
})
