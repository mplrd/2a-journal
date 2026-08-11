/**
 * Read the positions.targets JSON column.
 *
 * The API surfaces it as the raw JSON string, and some callers hand over an
 * already-parsed array. Both are accepted, and anything else — null, an empty
 * column, broken JSON — yields an empty list rather than throwing: this runs
 * per row in list views, where one malformed value must not blank the page.
 *
 * @param {string|Array|null|undefined} value
 * @returns {Array<object>}
 */
export function parseTargets(value) {
  if (Array.isArray(value)) return value
  if (typeof value !== 'string' || value === '') return []

  try {
    const parsed = JSON.parse(value)
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

/**
 * The price of the nearest objective, or null when there is none.
 *
 * Targets are stored nearest-first, so the first entry is the next one to be
 * hit. Null rather than 0 for "no objective": a pending order placed without
 * a take profit must render as a dash, and 0 would read as a real price.
 *
 * @param {string|Array|null|undefined} value
 * @returns {number|null}
 */
export function firstTargetPrice(value) {
  const first = parseTargets(value)[0]
  if (!first || first.price == null || first.price === '') return null

  const price = Number(first.price)
  return Number.isNaN(price) ? null : price
}
