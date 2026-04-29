/**
 * Brand chart palette — derived from docs/charte-graphique/AUDIT-UI.html §1.2
 *
 * Use these constants in any Chart.js dataset to keep visual identity
 * coherent across the app. Order convention:
 *  - primary    → equity / cumulative / neutral series
 *  - positive   → gains / above-water amounts
 *  - positiveLt → soft positive accent (BE-touched, win rate)
 *  - negative   → losses
 *  - warning    → BE / amber cautions
 *  - accent     → R:R, secondary metric in dual-axis charts
 *  - neutral    → axes, grids
 *  - secondary  → fall-back series in multi-series charts beyond the 1st
 */

export const CHART_PALETTE = {
  primary: '#1f2a3c',     // brand-navy-900
  positive: '#2d7952',    // brand-green-700
  positiveLt: '#46926b',  // brand-green-500
  negative: '#b5384a',    // danger
  warning: '#c98a2b',     // warning
  accent: '#9bc7af',      // brand-green-300
  neutral: '#6b6e75',     // gray-500
  secondary: '#283449',   // brand-navy-800
  cream: '#e8e8e6',       // brand-cream (used in dark mode for primary lines)
}

/**
 * Add an alpha channel to a hex color. Used for fill backgrounds where the
 * line itself stays solid.
 */
export function withAlpha(hex, alpha) {
  const a = Math.max(0, Math.min(1, alpha))
  const h = hex.replace('#', '')
  const r = parseInt(h.slice(0, 2), 16)
  const g = parseInt(h.slice(2, 4), 16)
  const b = parseInt(h.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${a})`
}

/**
 * Resolve the primary series color according to the active theme. Dark
 * surfaces are mostly navy already, so a navy primary line would blend in;
 * we flip to brand-cream for dark.
 */
export function primaryFor(isDark) {
  return isDark ? CHART_PALETTE.cream : CHART_PALETTE.primary
}
