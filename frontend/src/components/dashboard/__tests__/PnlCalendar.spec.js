import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PnlCalendar from '../PnlCalendar.vue'

const HELP = 'Realized gains and losses for that day, no currency conversion.'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      dashboard: {
        daily_calendar: 'Daily P&L',
        daily_calendar_help: HELP,
      },
      performance: {
        heatmap_mon: 'Mon',
        heatmap_tue: 'Tue',
        heatmap_wed: 'Wed',
        heatmap_thu: 'Thu',
        heatmap_fri: 'Fri',
        heatmap_sat: 'Sat',
        heatmap_sun: 'Sun',
      },
    },
  },
})

function mountCalendar(dailyPnl = []) {
  return mount(PnlCalendar, {
    props: { dailyPnl },
    global: {
      plugins: [i18n],
      directives: { tooltip: {} },
    },
  })
}

describe('PnlCalendar', () => {
  it('shows the title', () => {
    expect(mountCalendar().text()).toContain('Daily P&L')
  })

  it('explains what the figures mean', () => {
    // Ticket #36: a user asked what the values are — "is it rounded to the
    // nearest euro?" — and got the colour code wrong on his own, reading amber
    // as "BE exit without TP" when it means a result of exactly zero. The
    // figures are worth nothing if they have to be guessed at.
    const info = mountCalendar().find('[data-testid="daily-pnl-help"]')

    expect(info.exists()).toBe(true)
    expect(info.attributes('aria-label')).toBe(HELP)
  })
})
