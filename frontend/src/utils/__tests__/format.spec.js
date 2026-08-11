import { describe, it, expect } from 'vitest'
import { formatPrice, formatSize } from '@/utils/format'

describe('formatPrice', () => {
  // The bug this pins down: an order placed without a stop loss displayed
  // "0" — a price the user never set, and a dangerous one to read on a risk
  // field. Number(null) is 0 in JS, so the raw Number(...).toLocaleString()
  // the orders table used turned "no stop" into "stop at zero".
  it('renders a missing price as a dash, not as zero', () => {
    expect(formatPrice(null)).toBe('-')
    expect(formatPrice(undefined)).toBe('-')
    expect(formatPrice('')).toBe('-')
  })

  it('never turns a non-numeric value into a number', () => {
    expect(formatPrice('abc')).toBe('-')
  })

  it('keeps a real price localized, including a genuine zero', () => {
    expect(formatPrice(26389.74)).toBe((26389.74).toLocaleString())
    // A price the API really sent as 0 is not the same as no price at all.
    expect(formatPrice(0)).toBe((0).toLocaleString())
    // The API surfaces DECIMALs as strings.
    expect(formatPrice('26389.74000')).toBe((26389.74).toLocaleString())
  })
})

describe('formatSize', () => {
  // Guarding the convention formatPrice follows.
  it('renders a missing size as a dash', () => {
    expect(formatSize(null)).toBe('-')
    expect(formatSize('')).toBe('-')
  })

  it('drops the trailing zeros the API sends', () => {
    expect(formatSize('1.50000')).toBe('1.5')
  })
})
