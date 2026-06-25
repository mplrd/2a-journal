import { describe, it, expect, vi, afterEach } from 'vitest'
import { remapNumpadDecimal, installNumpadDecimalRemap } from '../numpadDecimal'

// Builds a DOM node that looks like PrimeVue's InputNumber inner input.
function inputNumberEl() {
  const el = document.createElement('input')
  el.className = 'p-inputtext p-component p-inputnumber-input'
  document.body.appendChild(el)
  return el
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('remapNumpadDecimal', () => {
  it('on a comma-locale, replays the numpad decimal as a comma keypress and prevents the dot', () => {
    const el = inputNumberEl()
    const seen = []
    el.addEventListener('keypress', (e) => seen.push(e.key))
    const preventDefault = vi.fn()

    const handled = remapNumpadDecimal({ code: 'NumpadDecimal', target: el, preventDefault }, ',')

    expect(handled).toBe(true)
    expect(preventDefault).toHaveBeenCalledOnce()
    expect(seen).toEqual([','])
  })

  it('is a no-op in a dot-locale (the native dot already works)', () => {
    const el = inputNumberEl()
    const seen = []
    el.addEventListener('keypress', (e) => seen.push(e.key))
    const preventDefault = vi.fn()

    const handled = remapNumpadDecimal({ code: 'NumpadDecimal', target: el, preventDefault }, '.')

    expect(handled).toBe(false)
    expect(preventDefault).not.toHaveBeenCalled()
    expect(seen).toEqual([])
  })

  it('uses whatever separator it is handed (multi-locale, not hard-coded to comma)', () => {
    const el = inputNumberEl()
    const seen = []
    el.addEventListener('keypress', (e) => seen.push(e.key))

    // A hypothetical locale whose decimal separator is an Arabic decimal mark.
    remapNumpadDecimal({ code: 'NumpadDecimal', target: el, preventDefault: vi.fn() }, '٫')

    expect(seen).toEqual(['٫'])
  })

  it('ignores keys other than the numpad decimal', () => {
    const el = inputNumberEl()
    const preventDefault = vi.fn()

    expect(remapNumpadDecimal({ code: 'Period', target: el, preventDefault }, ',')).toBe(false)
    expect(preventDefault).not.toHaveBeenCalled()
  })

  it('ignores inputs that are not a PrimeVue InputNumber', () => {
    const el = document.createElement('input') // plain input, no p-inputnumber-input class
    document.body.appendChild(el)
    const preventDefault = vi.fn()

    expect(remapNumpadDecimal({ code: 'NumpadDecimal', target: el, preventDefault }, ',')).toBe(false)
    expect(preventDefault).not.toHaveBeenCalled()
  })
})

describe('installNumpadDecimalRemap', () => {
  it('reads the separator on each keydown (reactive to a language switch) and cleans up', () => {
    const el = inputNumberEl()
    const seen = []
    el.addEventListener('keypress', (e) => seen.push(e.key))
    let separator = ','
    const uninstall = installNumpadDecimalRemap(() => separator)

    const press = () =>
      el.dispatchEvent(new KeyboardEvent('keydown', { code: 'NumpadDecimal', bubbles: true, cancelable: true }))

    press()
    expect(seen).toEqual([',']) // French → comma

    separator = '.' // user switched the app to English
    press()
    expect(seen).toEqual([',']) // dot-locale → no remap, native dot path left alone

    uninstall()
    separator = ','
    press()
    expect(seen).toEqual([',']) // listener removed → nothing more happens
  })
})
