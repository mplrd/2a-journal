/**
 * Pure formatters that turn a trading plan's raw filters into short, readable
 * strings for the plans list (docs/83). Kept out of the SFC so the day-mask
 * range compression and price trimming stay unit-testable.
 *
 * days_mask: bit 0 = Monday … bit 6 = Sunday.
 */

/** Drop the DECIMAL(15,5) trailing zeros the API sends (24000.00000 → 24000). */
export function trimPrice(value) {
  const n = Number(value)
  return Number.isFinite(n) ? String(n) : String(value)
}

/** A price zone as "low–high", normalized min-first and trimmed. */
export function formatZoneRange(zone) {
  const a = Number(zone.low_price)
  const b = Number(zone.high_price)
  return `${trimPrice(Math.min(a, b))}–${trimPrice(Math.max(a, b))}`
}

/** A window's "HH:MM–HH:MM" from its HH:MM:SS bounds. */
export function formatWindowTime(win) {
  const start = (win.start_time ?? '').slice(0, 5)
  const end = (win.end_time ?? '').slice(0, 5)
  return `${start}–${end}`
}

/**
 * Human day set from a mask: consecutive days collapse into "Mon–Fri" ranges,
 * isolated days join with commas ("Mon, Wed, Fri"), a full week returns
 * allDaysLabel, an empty mask returns "".
 */
export function daysMaskToLabel(mask, dayLabels, allDaysLabel) {
  const m = Number(mask)
  const active = [0, 1, 2, 3, 4, 5, 6].filter((i) => (m & (1 << i)) !== 0)
  if (active.length === 0) return ''
  if (active.length === 7) return allDaysLabel

  const runs = []
  let start = active[0]
  let prev = active[0]
  for (let k = 1; k < active.length; k++) {
    if (active[k] === prev + 1) {
      prev = active[k]
      continue
    }
    runs.push([start, prev])
    start = active[k]
    prev = active[k]
  }
  runs.push([start, prev])

  return runs
    .map(([a, b]) => (a === b ? dayLabels[a] : `${dayLabels[a]}–${dayLabels[b]}`))
    .join(', ')
}
