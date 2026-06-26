import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import WinLossChart from '../WinLossChart.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      dashboard: {
        win_loss_distribution: 'Win / Loss Distribution',
        wins: 'Wins',
        losses: 'Losses',
        breakeven: 'Breakeven',
        win_loss_be_help: 'The BE threshold is set in My account.',
      },
      performance: {
        no_data: 'No data',
        view_details: 'View details',
      },
    },
  },
})

function mountChart(props = {}) {
  return mount(WinLossChart, {
    props,
    global: {
      plugins: [i18n],
      stubs: { Chart: { template: '<div class="chart-stub" />' } },
      directives: { tooltip: {} },
    },
  })
}

describe('WinLossChart', () => {
  it('shows no-data message when distribution is all zeros', () => {
    const wrapper = mountChart({ data: { win: 0, loss: 0, be: 0 } })
    expect(wrapper.text()).toContain('No data')
  })

  it('renders chart when data is provided', () => {
    const wrapper = mountChart({ data: { win: 5, loss: 2, be: 1 } })
    expect(wrapper.find('.chart-stub').exists()).toBe(true)
  })

  it('shows the title', () => {
    const wrapper = mountChart({ data: { win: 1, loss: 0, be: 0 } })
    expect(wrapper.text()).toContain('Win / Loss Distribution')
  })

  it('exposes a BE-threshold help hint in the header', () => {
    const wrapper = mountChart({ data: { win: 1, loss: 0, be: 0 } })
    const info = wrapper.find('[data-testid="win-loss-be-help"]')
    expect(info.exists()).toBe(true)
    expect(info.attributes('aria-label')).toBe('The BE threshold is set in My account.')
  })
})
