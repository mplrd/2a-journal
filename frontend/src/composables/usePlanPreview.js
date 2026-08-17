import { ref } from 'vue'
import { plansService } from '@/services/plans'

/**
 * Tells the user, while they are still typing, whether the entry they are
 * describing falls inside the plan they picked (docs/102).
 *
 * Until now the verdict only showed up as a badge once the trade was saved: a
 * statement of fact, not a warning. Same evaluator on the server, so the preview
 * and the verdict recorded a second later cannot disagree.
 *
 * Never blocks a save, and never surfaces its own failures: a form that refused
 * to submit because a read-only check was unreachable would be a regression.
 */
const DEBOUNCE_MS = 400

export function usePlanPreview() {
  const verdict = ref(null)
  const checking = ref(false)
  let timer = null
  // Requests overtake each other while typing; only the newest answer counts.
  let latest = 0

  function reset() {
    clearTimeout(timer)
    verdict.value = null
    checking.value = false
  }

  /**
   * @param {?number} planId  no plan → nothing to say
   * @param {object}  payload direction / symbol / entry_price / account_id, plus
   *                          size, sl_points and opened_at when known
   */
  function schedule(planId, payload) {
    clearTimeout(timer)

    // The three fields the server insists on. A form still being filled in is
    // the normal case, not an error to report.
    if (!planId || !payload.account_id || !payload.direction || !payload.symbol || !(payload.entry_price > 0)) {
      verdict.value = null
      checking.value = false
      return
    }

    checking.value = true
    timer = setTimeout(async () => {
      const ticket = ++latest
      try {
        const resp = await plansService.evaluate(planId, payload)
        if (ticket === latest) verdict.value = resp.data
      } catch {
        if (ticket === latest) verdict.value = null
      } finally {
        if (ticket === latest) checking.value = false
      }
    }, DEBOUNCE_MS)
  }

  return { planVerdict: verdict, planChecking: checking, schedulePlanCheck: schedule, resetPlanCheck: reset }
}
