/**
 * Pure conversions between a trading plan's API shape and the editor's form
 * model (docs/83). Kept out of the SFC so the fiddly bitmask / number coercion
 * logic is unit-testable without mounting the component.
 *
 * days_mask: bit 0 = Monday … bit 6 = Sunday. The form stores days as an array
 * of 7 booleans (Monday-first) to drive the day toggles.
 */

export function maskToDays(mask) {
  return [0, 1, 2, 3, 4, 5, 6].map((i) => (Number(mask) & (1 << i)) !== 0)
}

export function daysToMask(days) {
  return days.reduce((m, on, i) => (on ? m | (1 << i) : m), 0)
}

export function blankPlanForm() {
  return {
    id: null,
    name: '',
    symbol: null,
    allowed_direction: null,
    timezone: 'Europe/Paris',
    max_risk_percent: null,
    zones: [],
    windows: [],
  }
}

export function planToForm(plan) {
  return {
    id: plan.id,
    name: plan.name,
    // The instrument the plan targets; null = every instrument.
    symbol: plan.symbol ?? null,
    allowed_direction: plan.allowed_direction ?? null,
    timezone: plan.timezone ?? 'Europe/Paris',
    max_risk_percent: plan.max_risk_percent != null ? Number(plan.max_risk_percent) : null,
    zones: (plan.zones ?? []).map((z) => ({
      direction: z.direction,
      low_price: Number(z.low_price),
      high_price: Number(z.high_price),
    })),
    windows: (plan.windows ?? []).map((w) => ({
      days: maskToDays(w.days_mask),
      start_time: (w.start_time ?? '').slice(0, 5),
      end_time: (w.end_time ?? '').slice(0, 5),
    })),
  }
}

export function formToPayload(f) {
  return {
    name: f.name.trim(),
    symbol: f.symbol,
    allowed_direction: f.allowed_direction,
    timezone: f.timezone,
    max_risk_percent: f.max_risk_percent,
    zones: f.zones.map((z) => ({
      direction: z.direction,
      low_price: z.low_price,
      high_price: z.high_price,
    })),
    windows: f.windows.map((w) => ({
      days_mask: daysToMask(w.days),
      start_time: w.start_time,
      end_time: w.end_time,
    })),
  }
}
