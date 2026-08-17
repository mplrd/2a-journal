import { describe, it, expect } from 'vitest'
import { maskToDays, daysToMask, planToForm, formToPayload, blankPlanForm, isPlanFormValid, pointValueSummary } from '@/utils/planForm'

/**
 * A plan targets an asset but carries no account, while the risk caps resolve
 * through point_value(asset, account) — so "1 %" can mean two different amounts
 * on two accounts. The editor states that instead of hiding it (docs/103).
 */
describe('pointValueSummary', () => {
  const dax = { id: 3, code: 'DAX', point_value: 25 }
  const accounts = [{ id: 1, name: 'Demo FTMO' }, { id: 2, name: 'Live IB' }]
  const noOverride = () => null

  it('says nothing without an asset or without an account', () => {
    expect(pointValueSummary(null, accounts, noOverride)).toBeNull()
    expect(pointValueSummary(dax, [], noOverride)).toBeNull()
  })

  it('collapses to one figure when every account resolves the same', () => {
    const summary = pointValueSummary(dax, accounts, noOverride)
    expect(summary).toEqual({ uniform: true, value: 25, entries: expect.any(Array) })
  })

  it('falls back to the asset default only where no override exists', () => {
    // The per-account setting wins (SignalRiskCalculator), the asset value fills in.
    const override = (symbolId, accountId) => (accountId === 2 ? 1 : null)
    const summary = pointValueSummary(dax, accounts, override)
    expect(summary.uniform).toBe(false)
    expect(summary.entries).toEqual([
      { accountId: 1, accountName: 'Demo FTMO', value: 25 },
      { accountId: 2, accountName: 'Live IB', value: 1 },
    ])
  })

  it('treats a symbol without a stored default as 1, never as unknown', () => {
    // Both columns are NOT NULL DEFAULT 1; a missing figure is 1, not a hole.
    const summary = pointValueSummary({ id: 9, code: 'NEW' }, [accounts[0]], noOverride)
    expect(summary).toMatchObject({ uniform: true, value: 1 })
  })
})

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
    expect(f.symbol).toBeNull()
  })

  // The instrument a plan targets: a zone is a pair of bare prices and means
  // nothing without it. null = every instrument (a plan's prior behaviour).
  it('planToForm carries the targeted instrument', () => {
    expect(planToForm({ id: 1, name: 'Nasdaq', symbol: 'NASDAQ' }).symbol).toBe('NASDAQ')
  })

  it('planToForm leaves the instrument null when the plan targets none', () => {
    expect(planToForm({ id: 1, name: 'Any' }).symbol).toBeNull()
  })

  it('formToPayload sends the instrument back', () => {
    const payload = formToPayload({
      id: null,
      name: 'Nasdaq',
      symbol: 'NASDAQ',
      allowed_direction: null,
      timezone: 'UTC',
      max_risk_percent: null,
      zones: [],
      windows: [],
    })
    expect(payload.symbol).toBe('NASDAQ')
  })

  // The editor refuses to save a plan that names no instrument: its price zones
  // would then be held against signals from any market, which is the very thing
  // the field exists to prevent. Plans stored before it stay valid in database —
  // reopening one asks for its instrument.
  it('isPlanFormValid requires both a name and an instrument', () => {
    expect(isPlanFormValid({ name: 'Nasdaq', symbol: 'NASDAQ' })).toBe(true)
    expect(isPlanFormValid({ name: 'Nasdaq', symbol: null })).toBe(false)
    expect(isPlanFormValid({ name: '   ', symbol: 'NASDAQ' })).toBe(false)
  })

  it('isPlanFormValid rejects a blank form', () => {
    expect(isPlanFormValid(blankPlanForm())).toBe(false)
  })

  // The cumulative cap: what the plan may carry in total, not per trade.
  it('planToForm coerces the cumulative risk cap to a number', () => {
    const form = planToForm({ id: 1, name: 'DAX', max_plan_risk_percent: '5.000' })
    expect(form.max_plan_risk_percent).toBe(5)
  })

  it('planToForm leaves the cumulative cap null when the plan sets none', () => {
    expect(planToForm({ id: 1, name: 'DAX' }).max_plan_risk_percent).toBeNull()
  })

  it('formToPayload sends the cumulative cap back, cleared included', () => {
    const base = {
      id: null, name: 'DAX', symbol: 'DAX', allowed_direction: null,
      timezone: 'UTC', max_risk_percent: 1, zones: [], windows: [],
    }
    expect(formToPayload({ ...base, max_plan_risk_percent: 5 }).max_plan_risk_percent).toBe(5)
    // An emptied InputNumber hands back undefined; the API needs an explicit
    // null to clear the column rather than a missing key.
    expect(formToPayload({ ...base, max_plan_risk_percent: undefined }).max_plan_risk_percent).toBeNull()
  })
})
