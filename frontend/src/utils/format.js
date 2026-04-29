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
