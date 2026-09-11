import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import PerformanceView from '@/views/PerformanceView.vue'
import { useStatsStore } from '@/stores/stats'
import { useAccountsStore } from '@/stores/accounts'
import { useSymbolsStore } from '@/stores/symbols'
import { useSetupsStore } from '@/stores/setups'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

const winLoss = { win: 3, loss: 2, be: 1, avg_win: 120.5, avg_loss: -80.2, max_win: 450, max_loss: -210 }

const stubs = {
  DashboardFilters: true,
  SetupCombinationDialog: true,
  RrDistributionChart: true,
  EquityCurveChart: true,
  HeatmapChart: true,
  SelectButton: true,
  Button: true,
  ChartCard: {
    // Typed so a bare `detailable` attribute is cast to true, as in the real card.
    props: { title: String, type: String, data: Object, options: Object, detailable: Boolean },
    emits: ['detail'],
    template: '<div class="chart-card-stub"><button v-if="detailable" :data-title="title" @click="$emit(\'detail\')" /></div>',
  },
  StatsDetailDialog: {
    props: ['visible', 'dimension', 'data', 'header'],
    template: '<div v-if="visible" data-testid="stats-detail" :data-dimension="dimension" :data-header="header"><slot /></div>',
  },
  WinLossAmounts: {
    props: ['data'],
    template: '<div data-testid="win-loss-amounts" :data-avg-win="data?.avg_win" />',
  },
}

async function mountView() {
  const pinia = createPinia()
  setActivePinia(pinia)

  const statsStore = useStatsStore()
  for (const action of [
    'fetchCharts', 'fetchBySymbol', 'fetchByDirection', 'fetchBySetup',
    'fetchByPeriod', 'fetchBySession', 'fetchRrDistribution', 'fetchHeatmap',
  ]) {
    vi.spyOn(statsStore, action).mockResolvedValue()
  }
  vi.spyOn(useAccountsStore(), 'fetchAccounts').mockResolvedValue()
  vi.spyOn(useSymbolsStore(), 'fetchSymbols').mockResolvedValue()
  vi.spyOn(useSetupsStore(), 'fetchSetups').mockResolvedValue()
  statsStore.charts = { cumulative_pnl: [], win_loss: winLoss, pnl_by_symbol: [] }

  const i18n = createI18n({ legacy: false, locale: 'fr', fallbackLocale: 'en', messages: { fr, en } })
  const wrapper = mount(PerformanceView, { global: { plugins: [pinia, i18n], stubs } })
  await flushPromises()
  return wrapper
}

// Matched on the attribute value rather than a CSS selector: jsdom's selector
// engine chokes on the `&` of "Win Rate & R:R par symbole".
function openDetailOf(wrapper, title) {
  const button = wrapper.findAll('button').find((b) => b.attributes('data-title') === title)
  return button.trigger('click')
}

describe('PerformanceView — win / loss detail', () => {
  beforeEach(() => vi.restoreAllMocks())

  it('adds the average and largest amounts under the win / loss chart detail', async () => {
    const wrapper = await mountView()

    await openDetailOf(wrapper, fr.dashboard.win_loss_distribution)

    const dialog = wrapper.find('[data-testid="stats-detail"]')
    expect(dialog.attributes('data-dimension')).toBe('direction')
    // The modal now holds more than the by-direction table: it is the chart's detail.
    expect(dialog.attributes('data-header')).toBe(fr.dashboard.win_loss_distribution)
    const amounts = dialog.find('[data-testid="win-loss-amounts"]')
    expect(amounts.exists()).toBe(true)
    expect(amounts.attributes('data-avg-win')).toBe('120.5')
  })

  it('leaves the amounts out of any other chart detail', async () => {
    const wrapper = await mountView()

    await openDetailOf(wrapper, fr.dashboard.win_loss_distribution)
    await openDetailOf(wrapper, fr.performance.perf_by_symbol)

    const dialog = wrapper.find('[data-testid="stats-detail"]')
    expect(dialog.attributes('data-dimension')).toBe('symbol')
    expect(dialog.attributes('data-header')).toBeUndefined()
    expect(dialog.find('[data-testid="win-loss-amounts"]').exists()).toBe(false)
  })
})
