import { describe, it, expect } from 'vitest'
import { toNumberLocale, decimalSeparatorForLocale } from '../numberLocale'

// Maps the app i18n locale ('fr' | 'en') to the BCP-47 locale string fed to
// PrimeVue InputNumber. In a 'fr*' locale PrimeVue accepts both ',' and '.' as
// the decimal separator (typing AND paste) — which is what fixes the FTMO
// paste corruption (#22) while restoring the French display (#24).
describe('toNumberLocale', () => {
  it('maps the French app locale to fr-FR', () => {
    expect(toNumberLocale('fr')).toBe('fr-FR')
  })

  it('keeps an already-regional French locale as fr-FR', () => {
    expect(toNumberLocale('fr-FR')).toBe('fr-FR')
  })

  it('is case-insensitive on the French prefix', () => {
    expect(toNumberLocale('FR')).toBe('fr-FR')
  })

  it('maps the English app locale to en-US', () => {
    expect(toNumberLocale('en')).toBe('en-US')
  })

  it('keeps en-US as en-US', () => {
    expect(toNumberLocale('en-US')).toBe('en-US')
  })

  it('falls back to en-US for an unsupported locale', () => {
    expect(toNumberLocale('de')).toBe('en-US')
  })

  it.each([null, undefined, ''])('falls back to en-US for %p', (value) => {
    expect(toNumberLocale(value)).toBe('en-US')
  })
})

// Derives the decimal separator of a locale (used to remap the numpad decimal
// key — see numpadDecimal.js). Locale-agnostic: read from Intl, never hard-coded.
describe('decimalSeparatorForLocale', () => {
  it("returns ',' for French", () => {
    expect(decimalSeparatorForLocale('fr-FR')).toBe(',')
  })

  it("returns '.' for US English", () => {
    expect(decimalSeparatorForLocale('en-US')).toBe('.')
  })

  it("derives the separator for any BCP-47 locale (de-DE → ',')", () => {
    expect(decimalSeparatorForLocale('de-DE')).toBe(',')
  })

  it.each([null, undefined, '@@bogus@@'])("falls back to '.' for %p", (value) => {
    expect(decimalSeparatorForLocale(value)).toBe('.')
  })
})
