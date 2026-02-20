import { computed } from 'vue'
import { Direction } from '@/constants/enums'

function num(value) {
  if (value === null || value === undefined || isNaN(value)) return '0'
  return parseFloat(parseFloat(value).toFixed(10)).toString()
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

    // R/R
    if (targets && targets.length > 0 && f.sl_points) {
      const rr = num(targets[0].points / f.sl_points)
      lines.push(`\u{2696}\u{FE0F} R/R: ${rr}`)
    }

    // Setup
    if (f.setup) {
      lines.push('')
      lines.push(`\u{1F4AC} ${f.setup}`)
    }

    return lines.join('\n')
  })

  return { sharePreviewText }
}
