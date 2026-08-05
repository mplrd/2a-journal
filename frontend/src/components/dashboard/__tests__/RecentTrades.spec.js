import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import RecentTrades from '../RecentTrades.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      dashboard: {
        open_trades: 'Ongoing',
        recent_trades: 'Recent',
        view_all: 'View all',
        no_open_trades: 'None',
        no_recent_trades: 'None',
      },
      positions: {
        symbol: 'Symbol',
        direction: 'Direction',
        entry_price: 'Entry',
        size: 'Size',
        directions: { BUY: 'Buy', SELL: 'Sell' },
      },
      trades: {
        opened_at: 'Opened',
        closed_at: 'Closed',
        pnl: 'P&L',
        exit_type: 'Exit',
        exit_types: { MANUAL: 'Manual' },
      },
    },
  },
})

vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))

/**
 * The DataTable stub renders every row through each Column's body slot, which
 * is where the values under test are formatted.
 */
function mountPanel(openTrades) {
  return mount(RecentTrades, {
    props: { trades: [], openTrades },
    global: {
      plugins: [i18n],
      stubs: {
        Button: true,
        Tag: { props: ['value'], template: '<span>{{ value }}</span>' },
        Tabs: { template: '<div><slot /></div>' },
        TabList: { template: '<div><slot /></div>' },
        Tab: { template: '<div><slot /></div>' },
        TabPanels: { template: '<div><slot /></div>' },
        TabPanel: { template: '<div><slot /></div>' },
        DataTable: {
          props: ['value'],
          provide() {
            return { rows: this.value }
          },
          template: '<div><slot /></div>',
        },
        Column: {
          inject: ['rows'],
          template: '<div><template v-for="(row, i) in rows" :key="i"><slot name="body" :data="row" /></template></div>',
        },
      },
    },
  })
}

describe('RecentTrades — ongoing panel', () => {
  it('shows what is still running, not the original position size', () => {
    // A position partially closed at TP1 keeps its ORIGINAL size on the
    // position row and what is left on the trade. The "ongoing" panel is about
    // what is still exposed, so reading `size` there overstates it: a
    // 2.5-contract short half-closed at TP1 reads 2.5 when 1.5 is running.
    // The two only started to differ once the connectors reconstructed the
    // original size — before that they were always equal.
    const wrapper = mountPanel([{
      id: 1,
      symbol: 'GER40',
      direction: 'SELL',
      entry_price: 26386.34,
      size: 2.5,
      remaining_size: 1.5,
      opened_at: '2026-08-05 07:29:00',
    }])

    expect(wrapper.text()).toContain('1.5')
    expect(wrapper.text()).not.toContain('2.5')
  })

  it('falls back to the position size when no remaining size is reported', () => {
    // Rows predating the split, and connectors that report no remainder.
    const wrapper = mountPanel([{
      id: 2,
      symbol: 'EURUSD',
      direction: 'BUY',
      entry_price: 1.09,
      size: 0.5,
      opened_at: '2026-08-05 07:29:00',
    }])

    expect(wrapper.text()).toContain('0.5')
  })
})
