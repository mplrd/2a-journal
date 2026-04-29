/**
 * Setup tag styling per category — drives the visual taxonomy proposed in
 * the audit (§3.2). The category is owned by the setup row itself (table
 * `setups.category`) and edited via the Account → Setups tab.
 *
 * Tag look per category:
 *   - timeframe → navy soft (h1, h4, m5…)
 *   - pattern   → green soft (Demand, BOS, Combo…)
 *   - context   → warning amber (Open cash, News, London…)
 *
 * If a label has no matching setup row (e.g. legacy data), we fall back
 * to the default `pattern` tone so existing tags don't break.
 */
import { useSetupsStore } from '@/stores/setups'

export const SETUP_TAG_CLASSES = {
  timeframe: 'bg-brand-navy-200 dark:bg-brand-navy-700/40 text-brand-navy-900 dark:text-brand-cream',
  pattern: 'bg-brand-green-100 dark:bg-brand-green-700/25 text-brand-green-800 dark:text-brand-green-300',
  context: 'bg-warning-bg dark:bg-warning/25 text-warning dark:text-warning-bg',
}

export function useSetupCategory() {
  const store = useSetupsStore()
  function classFor(label) {
    const setup = store.setups.find((s) => s.label === label)
    const category = setup?.category || 'pattern'
    return SETUP_TAG_CLASSES[category]
  }
  return { classFor }
}
