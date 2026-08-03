import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FieldHelpIcon from '../FieldHelpIcon.vue'

/**
 * Shared help affordance next to a form label (same pattern as the hints in
 * docs/82): an info icon carrying the text both as a hover tooltip and as an
 * aria-label, so the information is never hover-only.
 */
describe('FieldHelpIcon', () => {
  const mountIcon = (props = {}) =>
    mount(FieldHelpIcon, {
      props: { text: 'Where to find this value', ...props },
      global: { directives: { tooltip: {} } },
    })

  it('exposes the help text to screen readers, not only on hover', () => {
    const wrapper = mountIcon()

    expect(wrapper.attributes('aria-label')).toBe('Where to find this value')
    expect(wrapper.attributes('role')).toBe('img')
  })

  it('renders the shared info-circle affordance', () => {
    const wrapper = mountIcon()

    expect(wrapper.classes()).toContain('pi-info-circle')
    expect(wrapper.classes()).toContain('cursor-help')
  })

  it('forwards a data-testid so callers can target their own hint', () => {
    const wrapper = mountIcon({ testid: 'ctrader-account-id-help' })

    expect(wrapper.attributes('data-testid')).toBe('ctrader-account-id-help')
  })
})
