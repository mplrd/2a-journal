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

  it('colours a day amber when its P&L rounds to zero, whatever its sign', () => {
    // The cell shows the P&L rounded to the unit: +0.30 and -0.30 both read 0,
    // and used to come out green and red next to an amber exact zero.
    const wrapper = mountOn(2026, 8, [
      { date: '2026-09-03', trade_count: 1, total_pnl: 0.3 },
      { date: '2026-09-04', trade_count: 1, total_pnl: -0.3 },
      { date: '2026-09-07', trade_count: 1, total_pnl: 0 },
    ])

    for (const date of ['2026-09-03', '2026-09-04', '2026-09-07']) {
      const cell = cellOn(wrapper, date)
      expect(cell.classes(), date).toContain('bg-amber-500/80')
      expect(cell.find('[data-testid="calendar-day-pnl"]').text(), date).toBe('0')
    }
    expect(cellOn(wrapper, '2026-09-04').attributes('title')).toBe('1 trade(s) : 0')
  })

  it('reads the colour off the rounded figure it shows', () => {
    // Half a unit rounds away from zero on the label (+1 / -1): those days are
    // a gain and a loss, not a zero. Math.round would have made -0.5 a zero.
    const wrapper = mountOn(2026, 8, [
      { date: '2026-09-08', trade_count: 1, total_pnl: 0.5 },
      { date: '2026-09-09', trade_count: 1, total_pnl: -0.5 },
    ])

    const halfUp = cellOn(wrapper, '2026-09-08')
    const halfDown = cellOn(wrapper, '2026-09-09')
    expect(halfUp.find('[data-testid="calendar-day-pnl"]').text()).toBe('+1')
    expect(halfUp.classes()).toContain('bg-green-500/80')
    expect(halfDown.find('[data-testid="calendar-day-pnl"]').text()).toBe('-1')
    expect(halfDown.classes()).toContain('bg-red-500/80')
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

// Each row ends with the result of its week: the sum of the seven days it
// shows, days of the adjacent month included, since a week is a week whatever
// month it straddles.
describe('PnlCalendar — weekly total', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  function mountOn(year, monthIndex, dailyPnl = []) {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(year, monthIndex, 15, 12))
    return mountCalendar(dailyPnl)
  }

  const totals = (wrapper) => wrapper.findAll('[data-testid="calendar-week-total"]')

  it('ends every week with its total', () => {
    // September 2026 lays out five weeks.
    expect(totals(mountOn(2026, 8))).toHaveLength(5)
  })

  it('places the total right after the sunday of its week', () => {
    const wrapper = mountOn(2026, 8)
    const grid = wrapper.findAll('[data-testid="calendar-day"], [data-testid="calendar-week-total"]')

    expect(grid[7].attributes('data-testid')).toBe('calendar-week-total')
    expect(grid[6].attributes('data-date')).toBe('2026-09-06')
  })

  it('sums the days of the week, those of the adjacent month included', () => {
    // First week of September 2026: Monday 31 August to Sunday 6 September.
    const wrapper = mountOn(2026, 8, [
      { date: '2026-08-31', trade_count: 2, total_pnl: 120.4 },
      { date: '2026-09-02', trade_count: 1, total_pnl: -35.2 },
      { date: '2026-09-07', trade_count: 1, total_pnl: 999 },
    ])

    expect(totals(wrapper)[0].text()).toBe('+85')
    expect(totals(wrapper)[0].classes()).toContain('bg-green-500/80')
    expect(totals(wrapper)[1].text()).toBe('+999')
  })

  it('rounds the exact sum, not the sum of the rounded days', () => {
    // Three days each reading 0 add up to +1.2: the week is a gain.
    const wrapper = mountOn(2026, 8, [
      { date: '2026-09-01', trade_count: 1, total_pnl: 0.4 },
      { date: '2026-09-02', trade_count: 1, total_pnl: 0.4 },
      { date: '2026-09-03', trade_count: 1, total_pnl: 0.4 },
    ])

    expect(totals(wrapper)[0].text()).toBe('+1')
  })

  it('colours a losing week red and a week reading zero amber', () => {
    const wrapper = mountOn(2026, 8, [
      { date: '2026-09-08', trade_count: 1, total_pnl: -50 },
      { date: '2026-09-15', trade_count: 1, total_pnl: 40 },
      { date: '2026-09-16', trade_count: 1, total_pnl: -40.2 },
    ])

    expect(totals(wrapper)[1].text()).toBe('-50')
    expect(totals(wrapper)[1].classes()).toContain('bg-red-500/80')
    expect(totals(wrapper)[2].text()).toBe('0')
    expect(totals(wrapper)[2].classes()).toContain('bg-amber-500/80')
  })

  it('shows a dash for a week without any trade', () => {
    const total = totals(mountOn(2026, 8))[0]

    expect(total.text()).toBe('-')
    expect(total.classes()).not.toContain('bg-amber-500/80')
  })
})
