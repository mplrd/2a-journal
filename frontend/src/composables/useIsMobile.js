import { ref, onMounted, onBeforeUnmount, computed } from 'vue'

/**
 * Reactive layout flag — three buckets aligned with Tailwind breakpoints:
 *
 *   mobile   < 768 px         → TileList + FAB + collapsible filters
 *   compact  768 – 1023 px    → DataTable size="small" + selected columns
 *                              hidden (per-view) + inline button
 *   desktop  ≥ 1024 px        → DataTable full + inline button + open
 *                              filters
 *
 * The compact tier was added on top of the binary mobile/desktop split
 * after iPad portrait + narrow laptop windows feedback: at 768–1023 px
 * the screen has room for a real grid, just not a full-density one.
 *
 * Implementation: two matchMedia queries fire `change` only on the
 * predicate flip, so no resize-throttle needed. SSR/test-safe: defaults
 * to 'desktop' when matchMedia is unavailable.
 */
export function useLayout() {
  const layout = ref('desktop')

  let mobileMq = null
  let compactMq = null
  let mobileListener = null
  let compactListener = null

  function recompute() {
    if (mobileMq?.matches) {
      layout.value = 'mobile'
    } else if (compactMq?.matches) {
      layout.value = 'compact'
    } else {
      layout.value = 'desktop'
    }
  }

  onMounted(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return
    mobileMq = window.matchMedia('(max-width: 767px)')
    compactMq = window.matchMedia('(min-width: 768px) and (max-width: 1023px)')
    mobileListener = recompute
    compactListener = recompute
    mobileMq.addEventListener?.('change', mobileListener)
    compactMq.addEventListener?.('change', compactListener)
    recompute()
  })

  onBeforeUnmount(() => {
    if (mobileMq && mobileListener) mobileMq.removeEventListener?.('change', mobileListener)
    if (compactMq && compactListener) compactMq.removeEventListener?.('change', compactListener)
  })

  return {
    layout,
    isMobile: computed(() => layout.value === 'mobile'),
    isCompact: computed(() => layout.value === 'compact'),
    isDesktop: computed(() => layout.value === 'desktop'),
  }
}

/**
 * Backward-compat alias for callers that only need the boolean. New
 * call-sites should prefer useLayout() to access the three-way layout.
 */
export function useIsMobile() {
  const { isMobile } = useLayout()
  return { isMobile }
}
