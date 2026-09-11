import { describe, it, expect, vi, afterEach } from 'vitest'
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
        trade_count: '{count} trade(s)',
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

// Every row is a full Monday-to-Sunday week: the first one starts with the
// last days of the previous month, the last one ends with the first days of
// the next, so a week is read whole instead of against empty cells.
describe('PnlCalendar — full weeks', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  // The calendar opens on the current month.
  function mountOn(year, monthIndex, dailyPnl = []) {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(year, monthIndex, 15, 12))
    return mountCalendar(dailyPnl)
  }

  const cells = (wrapper) => wrapper.findAll('[data-testid="calendar-day"]')
  const dates = (wrapper) => cells(wrapper).map((c) => c.attributes('data-date'))
  const cellOn = (wrapper, date) => cells(wrapper).find((c) => c.attributes('data-date') === date)
  const isOutside = (cell) => cell.attributes('data-outside') === 'true'

  it('opens the first week with the last days of the previous month', () => {
    // September 2026 starts on a Tuesday.
    const wrapper = mountOn(2026, 8)

    expect(dates(wrapper).slice(0, 7)).toEqual([
      '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-09-05', '2026-09-06',
    ])
    expect(isOutside(cellOn(wrapper, '2026-08-31'))).toBe(true)
    expect(isOutside(cellOn(wrapper, '2026-09-01'))).toBe(false)
    expect(cellOn(wrapper, '2026-08-31').text()).toContain('31')
  })

  it('closes the last week with the first days of the next month', () => {
    // September 2026 ends on a Wednesday.
    const wrapper = mountOn(2026, 8)

    expect(dates(wrapper).slice(-7)).toEqual([
      '2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04',
    ])
    expect(isOutside(cellOn(wrapper, '2026-09-30'))).toBe(false)
    expect(isOutside(cellOn(wrapper, '2026-10-01'))).toBe(true)
  })

  it('lays out only whole weeks, with no empty cell', () => {
    const wrapper = mountOn(2026, 8)

    expect(cells(wrapper)).toHaveLength(35)
    expect(cells(wrapper).every((c) => c.attributes('data-date'))).toBe(true)
  })

  it('adds nothing before a month that starts on a Monday', () => {
    // June 2026 starts on a Monday.
    const wrapper = mountOn(2026, 5)

    expect(dates(wrapper)[0]).toBe('2026-06-01')
  })

  it('adds nothing after a month that ends on a Sunday', () => {
    // May 2026 ends on a Sunday.
    const wrapper = mountOn(2026, 4)

    expect(dates(wrapper).at(-1)).toBe('2026-05-31')
    expect(cells(wrapper)).toHaveLength(35)
  })

  it('shows the P&L of a day outside the month, with its trade count', () => {
    const wrapper = mountOn(2026, 8, [
      { date: '2026-08-31', trade_count: 2, total_pnl: 120.4 },
      { date: '2026-10-02', trade_count: 1, total_pnl: -35 },
    ])

    const lastOfAugust = cellOn(wrapper, '2026-08-31')
    expect(lastOfAugust.text()).toContain('+120')
    expect(lastOfAugust.attributes('title')).toBe('2 trade(s) : +120')
    expect(cellOn(wrapper, '2026-10-02').text()).toContain('-35')
  })

  it('follows the month when navigating', async () => {
    const wrapper = mountOn(2026, 8)

    // Back to August 2026, which starts on a Saturday and ends on a Monday.
    await wrapper.findAll('button')[0].trigger('click')

    expect(dates(wrapper)[0]).toBe('2026-07-27')
    expect(dates(wrapper).at(-1)).toBe('2026-09-06')
    expect(isOutside(cellOn(wrapper, '2026-09-01'))).toBe(true)
  })
})
