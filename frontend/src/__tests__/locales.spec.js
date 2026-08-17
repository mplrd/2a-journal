import { describe, it, expect } from 'vitest'
import fr from '@/locales/fr.json'
import en from '@/locales/en.json'

/**
 * Guards the two locale files against the drift a vocabulary pass invites
 * (docs/103): a key renamed on one side only, a translation that lost its
 * interpolation, and — the one that actually happened — the same notion
 * spelled differently depending on the screen that shows it.
 */
function flatten(obj, prefix = '', acc = {}) {
  for (const [key, value] of Object.entries(obj)) {
    const path = prefix ? `${prefix}.${key}` : key
    if (value !== null && typeof value === 'object') flatten(value, path, acc)
    else acc[path] = value
  }
  return acc
}

const FR = flatten(fr)
const EN = flatten(en)

describe('locale files', () => {
  it('expose exactly the same keys', () => {
    expect(Object.keys(FR).sort()).toEqual(Object.keys(EN).sort())
  })

  it('never carry an empty translation', () => {
    const empty = Object.keys(FR).filter((k) => !String(FR[k]).trim() || !String(EN[k]).trim())
    expect(empty).toEqual([])
  })

  it('keep the same interpolations on both sides', () => {
    // A translation that dropped its {count} renders the sentence without the
    // number, which reads as finished text rather than as a bug.
    const placeholders = (s) => [...String(s).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort()
    const drifted = Object.keys(FR).filter(
      (k) => placeholders(FR[k]).join() !== placeholders(EN[k]).join(),
    )
    expect(drifted).toEqual([])
  })
})

describe('plan vocabulary', () => {
  // The verdict is shown as a badge on a trade, on an order, and as a rejection
  // reason in the webhook event log. Three screens, one notion: it must not be
  // "Hors plan" here and "Hors du plan" there.
  it.each([
    ['out of plan', ['trades.adherence.out_of_plan', 'orders.adherence.out_of_plan', 'webhook.tradingview.reject_reason.OUT_OF_PLAN']],
    ['in plan', ['trades.adherence.in_plan', 'orders.adherence.in_plan']],
    ['no plan', ['trades.adherence.none', 'orders.adherence.none', 'trades.no_plan', 'orders.no_plan']],
  ])('says "%s" the same way everywhere', (_notion, keys) => {
    for (const locale of [FR, EN]) {
      const wordings = new Set(keys.map((k) => locale[k]))
      expect([...wordings]).toHaveLength(1)
    }
  })

  it('does not describe the plans as a robot-only feature', () => {
    // Since docs/102 the plan also warns on manual entry; the introduction and
    // the archive confirmation both used to speak of robots alone.
    for (const locale of [FR, EN]) {
      expect(locale['plan.subtitle']).toBeTruthy()
      expect(locale['plan.archive_confirm']).toBeTruthy()
    }
    expect(FR['plan.subtitle']).toMatch(/saisie/i)
    expect(EN['plan.subtitle']).toMatch(/manual/i)
    expect(FR['plan.archive_confirm']).toMatch(/saisie/i)
    expect(EN['plan.archive_confirm']).toMatch(/entry/i)
  })

  it('states the zone bound rule and the window purpose', () => {
    for (const locale of [FR, EN]) {
      expect(locale['plan.zone_bounds_hint']).toBeTruthy()
      expect(locale['plan.windows_hint']).toBeTruthy()
    }
    // "(sessions)" read as "describe your market sessions" rather than "when
    // the plan applies".
    expect(FR['plan.field.windows']).not.toMatch(/session/i)
    expect(EN['plan.field.windows']).not.toMatch(/session/i)
  })

  it('names the account when it states a point value', () => {
    // A plan has no account, but the caps resolve through point_value(asset,
    // account) — a sentence that omits the account states something false.
    for (const locale of [FR, EN]) {
      expect(locale['plan.point_value_uniform']).toContain('{asset}')
      expect(locale['plan.point_value_varies']).toContain('{list}')
      expect(locale['plan.point_value_entry']).toContain('{account}')
    }
    expect(FR['plan.point_value_uniform']).toMatch(/comptes/i)
    expect(EN['plan.point_value_uniform']).toMatch(/account/i)
  })

  it('distinguishes the two risk tags without their tooltips', () => {
    // Side by side in the list column, "≤ 1 %" next to "cumul ≤ 5 %" left the
    // first one unqualified.
    expect(FR['plan.tag.risk']).toMatch(/trade/i)
    expect(EN['plan.tag.risk']).toMatch(/trade/i)
  })
})
