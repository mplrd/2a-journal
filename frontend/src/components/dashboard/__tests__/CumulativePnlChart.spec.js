import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import zoomPlugin from 'chartjs-plugin-zoom'
import CumulativePnlChart from '../CumulativePnlChart.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      dashboard: {
        cumulative_pnl: 'Cumulative P&L',
        reset_zoom: 'Reset zoom',
        cumulative_pnl_zoom_help: 'Drag to zoom.',
      },
      performance: { no_data: 'No data', view_details: 'View details' },
    },
  },
})

const POINTS = [
  { closed_at: '2026-09-01 10:00:00', cumulative_pnl: 100 },
  { closed_at: '2026-09-02 10:00:00', cumulative_pnl: 150 },
  { closed_at: '2026-09-03 10:00:00', cumulative_pnl: 120 },
]

const ChartStub = {
  name: 'Chart',
  props: ['type', 'data', 'options', 'plugins'],
  template: '<div class="chart-stub" />',
}

function mountChart(data = POINTS) {
  return mount(CumulativePnlChart, {
    props: { data },
    global: {
      plugins: [i18n],
      stubs: { Chart: ChartStub },
      directives: { tooltip: {} },
    },
  })
}

// What chartjs-plugin-zoom hands its callbacks: the live chart instance.
function fakeChart() {
  return {
    options: { scales: { y: { beginAtZero: true } } },
    update: vi.fn(),
    resetZoom: vi.fn(),
  }
}

const chart = (wrapper) => wrapper.findComponent(ChartStub)
const zoomOptions = (wrapper) => chart(wrapper).props('options').plugins.zoom
const resetButton = (wrapper) => wrapper.find('[data-testid="reset-zoom"]')

describe('CumulativePnlChart — zoom', () => {
  it('registers the zoom plugin on this chart only', () => {
    expect(chart(mountChart()).props('plugins')).toContain(zoomPlugin)
  })

  it('zooms on the time axis by selecting an area', () => {
    const zoom = zoomOptions(mountChart())

    expect(zoom.zoom.mode).toBe('x')
    expect(zoom.zoom.drag.enabled).toBe(true)
  })

  it('leaves the plain mouse wheel to the page scroll', () => {
    // A dashboard scrolls: the wheel only zooms with Ctrl held down.
    const zoom = zoomOptions(mountChart())

    expect(zoom.zoom.wheel.enabled).toBe(true)
    expect(zoom.zoom.wheel.modifierKey).toBe('ctrl')
  })

  it('pans with Shift held, a plain drag being the area selection', () => {
    const zoom = zoomOptions(mountChart())

    expect(zoom.pan.enabled).toBe(true)
    expect(zoom.pan.mode).toBe('x')
    expect(zoom.pan.modifierKey).toBe('shift')
  })

  it('never zooms out past the whole history', () => {
    expect(zoomOptions(mountChart()).limits.x).toEqual({ min: 'original', max: 'original' })
  })

  it('explains the gestures', () => {
    const help = mountChart().find('[data-testid="cumulative-pnl-zoom-help"]')

    expect(help.exists()).toBe(true)
    expect(help.attributes('aria-label')).toBe('Drag to zoom.')
  })

  it('offers no reset before any zoom', () => {
    expect(resetButton(mountChart()).exists()).toBe(false)
  })

  it('offers a reset once zoomed, and fits the curve to the period', async () => {
    // Zoomed on a stretch of history far from zero, keeping zero on the axis
    // would flatten the very curve one zoomed in to read.
    const wrapper = mountChart()
    const live = fakeChart()

    zoomOptions(wrapper).zoom.onZoomComplete({ chart: live })
    await wrapper.vm.$nextTick()

    expect(resetButton(wrapper).exists()).toBe(true)
    expect(live.options.scales.y.beginAtZero).toBe(false)
    expect(live.update).toHaveBeenCalled()
  })

  it('offers a reset after a pan as well', async () => {
    const wrapper = mountChart()

    zoomOptions(wrapper).pan.onPanComplete({ chart: fakeChart() })
    await wrapper.vm.$nextTick()

    expect(resetButton(wrapper).exists()).toBe(true)
  })

  it('goes back to the whole history on reset', async () => {
    const wrapper = mountChart()
    const live = fakeChart()
    zoomOptions(wrapper).zoom.onZoomComplete({ chart: live })
    await wrapper.vm.$nextTick()

    await resetButton(wrapper).trigger('click')

    expect(live.resetZoom).toHaveBeenCalled()
    expect(live.options.scales.y.beginAtZero).toBe(true)
    expect(resetButton(wrapper).exists()).toBe(false)
  })

  it('drops the reset when new data redraws the chart', async () => {
    // Switching account rebuilds the chart from scratch, unzoomed.
    const wrapper = mountChart()
    zoomOptions(wrapper).zoom.onZoomComplete({ chart: fakeChart() })
    await wrapper.vm.$nextTick()

    await wrapper.setProps({ data: POINTS.slice(0, 2) })

    expect(resetButton(wrapper).exists()).toBe(false)
  })
})
