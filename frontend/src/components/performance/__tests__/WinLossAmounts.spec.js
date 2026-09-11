import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import WinLossAmounts from '../WinLossAmounts.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

function mountAmounts(data) {
  const i18n = createI18n({
    legacy: false,
    locale: 'fr',
    fallbackLocale: 'en',
    messages: { fr, en },
  })

  return mount(WinLossAmounts, {
    props: { data },
    global: { plugins: [i18n] },
  })
}

const winLoss = {
  win: 29,
  loss: 16,
  be: 3,
  avg_win: 108.28,
  avg_loss: -58.75,
  max_win: 750,
  max_loss: -120,
}

const cell = (wrapper, id) => wrapper.find(`[data-testid="win-loss-amounts-${id}"]`)
const widthOf = (wrapper, id) => Number.parseFloat(cell(wrapper, id).element.style.width)

describe('WinLossAmounts', () => {
  it('titles the block and says breakeven trades are left out', () => {
    const wrapper = mountAmounts(winLoss)

    expect(wrapper.text()).toContain('Montant des gains et des pertes')
    expect(wrapper.text()).toContain('Trades breakeven exclus')
  })

  it('heads each side with its count, losses on the left of zero and gains on the right', () => {
    const wrapper = mountAmounts(winLoss)

    const loss = cell(wrapper, 'header-loss')
    const win = cell(wrapper, 'header-win')
    expect(loss.text()).toContain('Pertes')
    expect(loss.text()).toContain('16 trades')
    expect(win.text()).toContain('Gains')
    expect(win.text()).toContain('29 trades')

    // Identity never rests on colour alone: the red/green pair is not
    // separable under deuteranopia, the arrow and the sign carry it too.
    expect(loss.find('.pi-arrow-down').exists()).toBe(true)
    expect(win.find('.pi-arrow-up').exists()).toBe(true)
  })

  it('writes a single trade in the singular', () => {
    const wrapper = mountAmounts({ ...winLoss, loss: 1 })

    expect(cell(wrapper, 'header-loss').text()).toContain('1 trade')
    expect(cell(wrapper, 'header-loss').text()).not.toContain('1 trades')
  })

  it('reads the average and the largest amount of each side', () => {
    const wrapper = mountAmounts(winLoss)

    expect(wrapper.text()).toContain('Moyenne')
    expect(wrapper.text()).toContain('Maximum')
    expect(cell(wrapper, 'average-loss').text()).toBe('-58.75')
    expect(cell(wrapper, 'average-win').text()).toBe('+108.28')
    expect(cell(wrapper, 'largest-loss').text()).toBe('-120.00')
    expect(cell(wrapper, 'largest-win').text()).toBe('+750.00')
  })

  it('colours a gain as a gain and a loss as a loss', () => {
    const wrapper = mountAmounts(winLoss)

    expect(cell(wrapper, 'average-win').classes()).toContain('text-success')
    expect(cell(wrapper, 'average-loss').classes()).toContain('text-danger')
  })

  it('scales each row on its own larger side so the smaller one reads as a share of it', () => {
    const wrapper = mountAmounts(winLoss)

    // Average: the win is the larger side, the loss is 58.75 / 108.28 of it.
    expect(widthOf(wrapper, 'average-win-bar')).toBe(100)
    expect(widthOf(wrapper, 'average-loss-bar')).toBeCloseTo(54.26, 1)
    // Largest: an outlier win of 750 must not flatten the average row.
    expect(widthOf(wrapper, 'largest-win-bar')).toBe(100)
    expect(widthOf(wrapper, 'largest-loss-bar')).toBeCloseTo(16, 1)
  })

  it('lets the loss side lead when losses weigh more', () => {
    const wrapper = mountAmounts({ ...winLoss, avg_win: 40, avg_loss: -80 })

    expect(widthOf(wrapper, 'average-loss-bar')).toBe(100)
    expect(widthOf(wrapper, 'average-win-bar')).toBe(50)
  })

  it('draws no bar and a dash for a side that holds no trade', () => {
    const wrapper = mountAmounts({ ...winLoss, loss: 0, avg_loss: null, max_loss: null })

    expect(cell(wrapper, 'header-loss').text()).toContain('0 trade')
    expect(cell(wrapper, 'average-loss').text()).toBe('-')
    expect(cell(wrapper, 'largest-loss').text()).toBe('-')
    expect(cell(wrapper, 'average-loss-bar').exists()).toBe(false)
    expect(widthOf(wrapper, 'average-win-bar')).toBe(100)
  })

  it('shows dashes and no bar while the distribution is not loaded', () => {
    const wrapper = mountAmounts(null)

    for (const id of ['average-loss', 'average-win', 'largest-loss', 'largest-win']) {
      expect(cell(wrapper, id).text()).toBe('-')
      expect(cell(wrapper, `${id}-bar`).exists()).toBe(false)
    }
  })
})
