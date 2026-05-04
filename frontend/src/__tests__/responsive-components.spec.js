import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import PrimeVue from 'primevue/config'
import CollapsibleFilters from '@/components/common/CollapsibleFilters.vue'
import FloatingActionButton from '@/components/common/FloatingActionButton.vue'
import TileList from '@/components/common/TileList.vue'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

function createI18nInstance() {
  return createI18n({
    legacy: false,
    locale: 'fr',
    messages: { fr, en },
  })
}

const baseStubs = { Button: true }

describe('CollapsibleFilters', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('starts collapsed by default and reveals slot on toggle', async () => {
    const wrapper = mount(CollapsibleFilters, {
      props: { storageKey: 'unit-test-filters' },
      slots: { default: '<div data-testid="slot-content">SLOT</div>' },
      global: { plugins: [createI18nInstance(), PrimeVue], stubs: baseStubs },
    })

    expect(wrapper.find('[data-testid="collapsible-filters-body"]').exists()).toBe(false)

    await wrapper.find('[data-testid="collapsible-filters-toggle"]').trigger('click')

    expect(wrapper.find('[data-testid="collapsible-filters-body"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('SLOT')
  })

  it('persists expanded state in localStorage', async () => {
    const wrapper = mount(CollapsibleFilters, {
      props: { storageKey: 'unit-test-persisted' },
      global: { plugins: [createI18nInstance(), PrimeVue], stubs: baseStubs },
    })

    await wrapper.find('[data-testid="collapsible-filters-toggle"]').trigger('click')

    expect(localStorage.getItem('unit-test-persisted')).toBe('true')
  })

  it('honors defaultExpanded when no localStorage value is present', () => {
    const wrapper = mount(CollapsibleFilters, {
      props: { storageKey: 'unit-test-default-expanded', defaultExpanded: true },
      slots: { default: '<div data-testid="slot-content">SLOT</div>' },
      global: { plugins: [createI18nInstance(), PrimeVue], stubs: baseStubs },
    })

    expect(wrapper.find('[data-testid="collapsible-filters-body"]').exists()).toBe(true)
  })
})

describe('FloatingActionButton', () => {
  it('emits click and exposes the aria-label', async () => {
    const wrapper = mount(FloatingActionButton, {
      props: { icon: 'plus', ariaLabel: 'Create' },
    })

    const btn = wrapper.find('[data-testid="fab"]')
    expect(btn.attributes('aria-label')).toBe('Create')
    expect(btn.classes()).toContain('lg:hidden')
    expect(btn.classes()).toContain('fixed')

    await btn.trigger('click')
    expect(wrapper.emitted('click')).toHaveLength(1)
  })
})

describe('TileList', () => {
  it('renders one tile per item via the default slot', () => {
    const wrapper = mount(TileList, {
      props: { items: [{ id: 1, name: 'A' }, { id: 2, name: 'B' }] },
      slots: { default: '<div class="tile">{{ params.item.name }}</div>' },
      global: { plugins: [createI18nInstance(), PrimeVue], stubs: baseStubs },
    })

    const tiles = wrapper.findAll('.tile')
    expect(tiles).toHaveLength(2)
  })

  it('renders the empty slot when items is empty', () => {
    const wrapper = mount(TileList, {
      props: { items: [] },
      slots: { empty: '<div data-testid="empty-msg">Nothing here</div>' },
      global: { plugins: [createI18nInstance(), PrimeVue], stubs: baseStubs },
    })

    expect(wrapper.find('[data-testid="tile-list-empty"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Nothing here')
  })

  it('hides pagination when totalRecords <= perPage', () => {
    const wrapper = mount(TileList, {
      props: { items: [{ id: 1 }], totalRecords: 5, page: 1, perPage: 10 },
      slots: { default: '<div />' },
      global: { plugins: [createI18nInstance(), PrimeVue], stubs: baseStubs },
    })

    expect(wrapper.find('[data-testid="tile-list-pagination"]').exists()).toBe(false)
  })

  it('shows pagination and emits @page with 0-based contract on next click', async () => {
    const wrapper = mount(TileList, {
      props: { items: [{ id: 1 }], totalRecords: 50, page: 2, perPage: 10 },
      slots: { default: '<div />' },
      global: { plugins: [createI18nInstance(), PrimeVue] },
    })

    expect(wrapper.find('[data-testid="tile-list-pagination"]').exists()).toBe(true)

    // Find the "Next" button by label translation. Two PrimeVue Buttons in
    // the pagination: prev (chevron-left) and next (chevron-right).
    const buttons = wrapper.findAllComponents({ name: 'Button' })
    const nextBtn = buttons.find((b) => b.props('icon') === 'pi pi-chevron-right')
    await nextBtn.trigger('click')

    const events = wrapper.emitted('page')
    expect(events).toBeTruthy()
    // page=2 (1-based) → next emits 0-based page=2 (display page 3)
    expect(events[0][0]).toEqual({ page: 2, rows: 10 })
  })

  it('emits @page with 0-based contract on previous click', async () => {
    const wrapper = mount(TileList, {
      props: { items: [{ id: 1 }], totalRecords: 50, page: 3, perPage: 10 },
      slots: { default: '<div />' },
      global: { plugins: [createI18nInstance(), PrimeVue] },
    })

    const buttons = wrapper.findAllComponents({ name: 'Button' })
    const prevBtn = buttons.find((b) => b.props('icon') === 'pi pi-chevron-left')
    await prevBtn.trigger('click')

    const events = wrapper.emitted('page')
    // page=3 (1-based) → prev emits 0-based page=1 (display page 2)
    expect(events[0][0]).toEqual({ page: 1, rows: 10 })
  })
})
