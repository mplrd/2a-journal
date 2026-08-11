/**
 * Format a numeric size (lot, contract, share count) without trailing zeros.
 * DB stores DECIMAL with up to 5 fraction digits; the API surfaces them as
 * strings ("1.50000"). We render only the digits the value actually carries.
 */
export function formatSize(value) {
  if (value == null || value === '') return '-'
  const num = Number(value)
  if (Number.isNaN(num)) return '-'
  return num.toString()
}

/**
 * Format a price (entry, stop loss, take profit) for display, grouped for the
 * reader's locale. Same missing-value convention as formatSize.
 *
 * The dash matters on a risk field: Number(null) is 0 in JS, so rendering a
 * raw Number(...).toLocaleString() turned an order placed WITHOUT a stop loss
 * into one showing a stop at 0. A price the API genuinely sent as 0 still
 * renders as 0 — absent and zero are not the same thing.
 */
export function formatPrice(value) {
  if (value == null || value === '') return '-'
  const num = Number(value)
  if (Number.isNaN(num)) return '-'
  return num.toLocaleString()
}
