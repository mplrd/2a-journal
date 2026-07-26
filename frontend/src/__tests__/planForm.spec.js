import { describe, it, expect } from 'vitest'
import { maskToDays, daysToMask, planToForm, formToPayload, blankPlanForm } from '@/utils/planForm'

describe('planForm conversions', () => {
  it('maskToDays / daysToMask round-trip on Mon–Fri (0b0011111 = 31)', () => {
    const days = maskToDays(31)
    expect(days).toEqual([true, true, true, true, true, false, false])
    expect(daysToMask(days)).toBe(31)
  })

  it('daysToMask sets Monday (bit0) and Sunday (bit6)', () => {
    expect(daysToMask([true, false, false, false, false, false, true])).toBe(1 | (1 << 6))
  })

  it('planToForm coerces string prices/risk to numbers, mask to booleans, trims seconds', () => {
    const form = planToForm({
      id: 5,
      name: 'DAX',
      allowed_direction: 'BUY',
      timezone: 'Europe/Paris',
      max_risk_percent: '1.500',
      zones: [{ direction: 'BUY', low_price: '24000.00000', high_price: '24400.00000' }],
      windows: [{ days_mask: 31, start_time: '09:00:00', end_time: '17:30:00' }],
    })
    expect(form.zones[0].low_price).toBe(24000)
    expect(form.zones[0].high_price).toBe(24400)
    expect(form.max_risk_percent).toBe(1.5)
    expect(form.windows[0].days).toEqual([true, true, true, true, true, false, false])
    expect(form.windows[0].start_time).toBe('09:00')
  })

  it('planToForm tolerates missing optional fields', () => {
    const form = planToForm({ id: 1, name: 'Empty' })
    expect(form.allowed_direction).toBeNull()
    expect(form.max_risk_percent).toBeNull()
    expect(form.zones).toEqual([])
    expect(form.windows).toEqual([])
  })

  it('formToPayload trims the name and maps days back to a mask', () => {
    const payload = formToPayload({
      id: null,
      name: '  DAX  ',
      allowed_direction: null,
      timezone: 'UTC',
      max_risk_percent: null,
      zones: [{ direction: 'SELL', low_price: 100, high_price: 200 }],
      windows: [{ days: [true, false, false, false, false, false, false], start_time: '09:00', end_time: '17:00' }],
    })
    expect(payload.name).toBe('DAX')
    expect(payload.windows[0].days_mask).toBe(1)
    expect(payload.zones[0].direction).toBe('SELL')
  })

  it('blankPlanForm starts with no filters', () => {
    const f = blankPlanForm()
    expect(f.zones).toEqual([])
    expect(f.windows).toEqual([])
    expect(f.allowed_direction).toBeNull()
  })
})
