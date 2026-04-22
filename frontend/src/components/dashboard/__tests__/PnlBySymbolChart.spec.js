import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PnlBySymbolChart from '../PnlBySymbolChart.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      dashboard: {
        pnl_by_symbol: 'P&L by Symbol',
        no_data: 'No data',
        trade_count: '{count} trade(s)',
      },
      performance: {
        no_data: 'No data',
        view_details: 'View details',
      },
    },
  },
})

function mountChart(props = {}) {
  return mount(PnlBySymbolChart, {
    props,
    global: {
      plugins: [i18n],
      stubs: { Chart: { template: '<div class="chart-stub" />' } },
    },
  })
}

describe('PnlBySymbolChart', () => {
  it('shows no-data message when data is empty', () => {
    const wrapper = mountChart({ data: [] })
    expect(wrapper.text()).toContain('No data')
  })

  it('renders chart when data is provided', () => {
    const wrapper = mountChart({
      data: [
        { symbol: 'NASDAQ', trade_count: 3, total_pnl: 150 },
        { symbol: 'DAX', trade_count: 1, total_pnl: -30 },
      ],
    })
    expect(wrapper.find('.chart-stub').exists()).toBe(true)
  })

  it('shows title', () => {
    const wrapper = mountChart({ data: [] })
    expect(wrapper.text()).toContain('P&L by Symbol')
  })
})
