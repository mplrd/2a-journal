import { ExitType } from '@/constants/enums'

// Returns the next planned exit step for an open trade, or null when none is
// left. Drives the "next objective" shortcut (↑↑) in the trades grid.
//   Step 1 — BE: while be_price is set and BE is not yet reached.
//   Step 2 — first untaken target.
export function getNextObjective(trade) {
  const partialExits = trade.partial_exits || []

  // Step 1: BE if be_price defined
  if (trade.be_price) {
    const beSize = Number(trade.be_size) || 0
    if (beSize > 0) {
      // BE with planned lightening: it's done once reached — either via a BE
      // partial exit (lightening) or marked reached without lightening
      // ("protect but don't lighten" → be_reached).
      const beDone =
        partialExits.some((pe) => pe.exit_type === ExitType.BE) || Boolean(Number(trade.be_reached))
      if (!beDone) {
        return {
          label: 'BE',
          exit_price: Number(trade.be_price),
          exit_size: beSize,
          exit_type: ExitType.BE,
          action: 'close',
        }
      }
    } else if (!Number(trade.be_reached)) {
      // BE without planned lightening: just mark as reached
      return {
        label: 'BE',
        action: 'mark',
      }
    }
  }

  // Step 2: First untaken target
  let targets = trade.targets
  if (typeof targets === 'string') {
    try { targets = JSON.parse(targets) } catch { targets = null }
  }
  if (Array.isArray(targets)) {
    const takenTargetIds = new Set(partialExits.map((pe) => pe.target_id).filter(Boolean))
    for (const target of targets) {
      if (!takenTargetIds.has(target.id)) {
        return {
          label: target.label || target.id,
          exit_price: Number(target.price),
          exit_size: Number(target.size),
          exit_type: ExitType.TP,
          target_id: target.id,
          action: 'close',
        }
      }
    }
  }

  return null
}
