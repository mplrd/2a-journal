import { ref, onMounted, onBeforeUnmount } from 'vue'

/**
 * Reactive mobile-vs-desktop flag, gated on the Tailwind `lg:` breakpoint
 * (1024px). `isMobile.value === true` means "any narrow viewport that
 * struggles with multi-column DataTables" — phones in portrait/landscape,
 * tablets in portrait, narrow split-screen laptop windows. The full
 * web layout kicks in only on real desktop widths (≥ 1024px).
 *
 * The breakpoint was bumped from md: (768) to lg: (1024) after
 * iPhone 14 Pro Max + iPad portrait feedback — at 768–1023px the
 * standard web layout was still too cramped (filters, period picker,
 * many-column tables).
 *
 * Convention used across responsive views (Accounts, Orders, Trades):
 *   - Filters: collapsed by default, expandable on demand.
 *   - Action buttons: standalone "Nouveau" replaced by a FAB (fixed
 *     bottom-right, icon-only).
 *   - Lists: DataTable (desktop) ↔ TileList (mobile), with the same
 *     fields surfaced in tile cards.
 *
 * Implementation: matchMedia is the lightest possible probe — no
 * resize-listener throttling needed, browsers fire `change` only when
 * the predicate flips. Falls back to a static `false` when matchMedia
 * is unavailable (SSR, tests).
 */
export function useIsMobile(query = '(max-width: 1023px)') {
  const isMobile = ref(false)

  let mediaQueryList = null
  let listener = null

  function update(e) {
    isMobile.value = e?.matches ?? mediaQueryList?.matches ?? false
  }

  onMounted(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return
    mediaQueryList = window.matchMedia(query)
    isMobile.value = mediaQueryList.matches
    listener = (e) => update(e)
    mediaQueryList.addEventListener?.('change', listener)
  })

  onBeforeUnmount(() => {
    if (mediaQueryList && listener) {
      mediaQueryList.removeEventListener?.('change', listener)
    }
  })

  return { isMobile }
}
