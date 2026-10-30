import { computed } from 'vue'
import { Direction } from '@/constants/enums'

function num(value) {
  if (value === null || value === undefined || isNaN(value)) return '0'
  return parseFloat(parseFloat(value).toFixed(10)).toString()
}

/**
 * Size-weighted mean R across the target ladder (ticket #41). Each target
 * contributes its reward distance weighted by the size exited there, so a
 * scale-out plan reads its blended R:R rather than the nearest TP alone. BE is
 * a risk-reduction move, not a profit target, so it never enters here. Falls
 * back to the plain mean when no target carries a usable size, and returns null
 * when the risk is not measurable (no SL distance). Mirrors ShareService.php.
 */
function weightedRiskReward(targets, slPoints) {
  if (!(slPoints > 0)) return null

  let totalSize = 0
  let weightedPoints = 0
  for (const t of targets) {
    const size = Number(t.size) || 0
    if (size > 0) {
      totalSize += size
      weightedPoints += Number(t.points) * size
    }
  }

  let meanRewardPoints
  if (totalSize > 0) {
    meanRewardPoints = weightedPoints / totalSize
  } else {
    const sumPoints = targets.reduce((acc, t) => acc + Number(t.points), 0)
    meanRewardPoints = sumPoints / targets.length
  }

  return meanRewardPoints / slPoints
}

export function useSharePreview(form, calculatedTargets, calculatedSlPrice, calculatedBePrice) {
  const sharePreviewText = computed(() => {
    const f = form.value
    if (!f.symbol || !f.entry_price) return ''

    const lines = []

    // Header
    const dirEmoji = f.direction === Direction.BUY ? '\u{1F4C8}' : '\u{1F4C9}'
    const entryPrice = num(f.entry_price)
    lines.push(`${dirEmoji} ${f.direction} ${f.symbol} @ ${entryPrice}`)

    // Targets
    const targets = calculatedTargets.value
    if (targets && targets.length > 0) {
      const multiple = targets.length > 1
      targets.forEach((target, i) => {
        const label = multiple ? `TP${i + 1}` : 'TP'
        const price = num(target.price)
        const points = num(target.points)
        lines.push(`\u{1F3AF} ${label}: ${price} (+${points} pts)`)
      })
    }

    // BE
    if (f.be_points && calculatedBePrice.value != null) {
      const bePrice = num(calculatedBePrice.value)
      const bePoints = num(f.be_points)
      lines.push(`\u{1F512} BE: ${bePrice} (+${bePoints} pts)`)
    }

    // SL
    if (f.sl_points && calculatedSlPrice.value != null) {
      const slPrice = num(calculatedSlPrice.value)
      const slPoints = num(f.sl_points)
      lines.push(`\u{1F6D1} SL: ${slPrice} (-${slPoints} pts)`)
    }

    // R/R — size-weighted mean R across the whole target ladder (ticket #41).
    if (targets && targets.length > 0) {
      const rr = weightedRiskReward(targets, Number(f.sl_points))
      if (rr !== null) {
        lines.push(`\u{2696}\u{FE0F} R/R: ${num(rr)}`)
      }
    }

    // Setup
    const setupArr = Array.isArray(f.setup) ? f.setup : [f.setup]
    if (setupArr.length > 0 && setupArr[0]) {
      lines.push('')
      lines.push(`\u{1F4AC} ${setupArr.join(' | ')}`)
    }

    return lines.join('\n')
  })

  return { sharePreviewText }
}
