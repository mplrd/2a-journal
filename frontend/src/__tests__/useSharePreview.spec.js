import { describe, it, expect } from 'vitest'
import { ref } from 'vue'
import { useSharePreview } from '@/composables/useSharePreview'
import { Direction } from '@/constants/enums'

/**
 * The share text R/R (ticket #41). It must weight every take-profit by the
 * size exited there, so a scale-out plan reads its blended R:R instead of the
 * nearest TP alone — matching the backend ShareService to the digit.
 */
function previewText({ form, targets = [], slPrice = null, bePrice = null }) {
  const { sharePreviewText } = useSharePreview(ref(form), ref(targets), ref(slPrice), ref(bePrice))
  return sharePreviewText.value
}

const baseForm = {
  symbol: 'NASDAQ',
  entry_price: 18240,
  direction: Direction.BUY,
  sl_points: 50,
  be_points: null,
  setup: ['Divergence'],
}

describe('useSharePreview R/R', () => {
  it('keeps a single-target R/R at points / sl (no regression)', () => {
    const text = previewText({
      form: { ...baseForm },
      targets: [{ points: 110, size: 1, price: 18350 }],
      slPrice: 18190,
    })
    expect(text).toContain('R/R: 2.2')
  })

  it('weights the R/R by the size exited at each TP across the ladder', () => {
    // Robin's GER40 (ticket #41): SL 567 pts, four scaled-out targets.
    // (400×0.08 + 910×0.05 + 1713×0.05 + 5713×0.02) / 0.20 / 567 = 2.4462962963
    const text = previewText({
      form: { ...baseForm, symbol: 'GER40', entry_price: 25713, direction: Direction.SELL, sl_points: 567 },
      targets: [
        { points: 400, size: 0.08, price: 25313 },
        { points: 910, size: 0.05, price: 24803 },
        { points: 1713, size: 0.05, price: 24000 },
        { points: 5713, size: 0.02, price: 20000 },
      ],
      slPrice: 26280,
    })
    expect(text).toContain('R/R: 2.4462962963')
    // Not the nearest-TP-only value it used to show.
    expect(text).not.toContain('R/R: 0.7054673721')
  })

  it('falls back to the unweighted mean when no target carries a size', () => {
    // (40 + 120) / 2 / 40 = 2
    const text = previewText({
      form: { ...baseForm, sl_points: 40 },
      targets: [
        { points: 40, price: 18280 },
        { points: 120, price: 18360 },
      ],
      slPrice: 18200,
    })
    expect(text).toContain('R/R: 2\n')
  })

  it('omits the R/R line when there is no SL distance', () => {
    const text = previewText({
      form: { ...baseForm, sl_points: 0 },
      targets: [{ points: 110, size: 1, price: 18350 }],
      slPrice: null,
    })
    expect(text).not.toContain('R/R')
  })

  it('omits the R/R line when there is no target', () => {
    const text = previewText({
      form: { ...baseForm },
      targets: [],
      slPrice: 18190,
    })
    expect(text).not.toContain('R/R')
  })
})
