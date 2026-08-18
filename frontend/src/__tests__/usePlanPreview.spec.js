import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { usePlanPreview } from '@/composables/usePlanPreview'
import { plansService } from '@/services/plans'

vi.mock('@/services/plans', () => ({
  plansService: { evaluate: vi.fn() },
}))

/**
 * The verdict shown while the user is still typing (docs/102). It must never
 * get in the way: a half-filled form is the normal case, and a read-only check
 * that fails is not the user's problem.
 */
describe('usePlanPreview', () => {
  const draft = { account_id: 1, direction: 'BUY', symbol: 'DAX', entry_price: 18000 }

  beforeEach(() => {
    vi.useFakeTimers()
    plansService.evaluate.mockReset()
  })
  afterEach(() => vi.useRealTimers())

  it('says nothing while no plan is picked', async () => {
    const { planVerdict, schedulePlanCheck } = usePlanPreview()
    schedulePlanCheck(null, draft)
    await vi.runAllTimersAsync()

    expect(plansService.evaluate).not.toHaveBeenCalled()
    expect(planVerdict.value).toBeNull()
  })

  it.each([
    ['account', { ...draft, account_id: null }],
    ['direction', { ...draft, direction: null }],
    ['symbol', { ...draft, symbol: '' }],
    ['entry price', { ...draft, entry_price: 0 }],
  ])('stays quiet while the %s is missing', async (_field, payload) => {
    const { planVerdict, planChecking, schedulePlanCheck } = usePlanPreview()
    schedulePlanCheck(7, payload)
    await vi.runAllTimersAsync()

    expect(plansService.evaluate).not.toHaveBeenCalled()
    expect(planVerdict.value).toBeNull()
    expect(planChecking.value).toBe(false)
  })

  it('asks once when the typing stops, not on every keystroke', async () => {
    plansService.evaluate.mockResolvedValue({ data: { plan_adherence: 'IN_PLAN', plan_adherence_reason: null } })
    const { planVerdict, schedulePlanCheck } = usePlanPreview()

    schedulePlanCheck(7, { ...draft, entry_price: 1 })
    schedulePlanCheck(7, { ...draft, entry_price: 12 })
    schedulePlanCheck(7, { ...draft, entry_price: 18000 })
    await vi.runAllTimersAsync()

    expect(plansService.evaluate).toHaveBeenCalledTimes(1)
    expect(plansService.evaluate).toHaveBeenCalledWith(7, expect.objectContaining({ entry_price: 18000 }))
    expect(planVerdict.value.plan_adherence).toBe('IN_PLAN')
  })

  it('carries the reason back for a refusal', async () => {
    plansService.evaluate.mockResolvedValue({
      data: { plan_adherence: 'OUT_OF_PLAN', plan_adherence_reason: 'entry 18000 outside BUY zones (17000-17500)' },
    })
    const { planVerdict, schedulePlanCheck } = usePlanPreview()

    schedulePlanCheck(7, draft)
    await vi.runAllTimersAsync()

    expect(planVerdict.value.plan_adherence_reason).toContain('17000-17500')
  })

  it('swallows a failure rather than showing a stale or scary verdict', async () => {
    // The form must stay usable when a read-only check cannot be reached.
    plansService.evaluate.mockRejectedValue(new Error('offline'))
    const { planVerdict, planChecking, schedulePlanCheck } = usePlanPreview()

    schedulePlanCheck(7, draft)
    await vi.runAllTimersAsync()

    expect(planVerdict.value).toBeNull()
    expect(planChecking.value).toBe(false)
  })

  it('drops an answer that arrives after a newer one', async () => {
    // Requests overtake each other while typing; the last question asked is the
    // only one whose answer describes what is on screen.
    let resolveFirst
    plansService.evaluate
      .mockImplementationOnce(() => new Promise((r) => { resolveFirst = r }))
      .mockResolvedValueOnce({ data: { plan_adherence: 'OUT_OF_PLAN', plan_adherence_reason: 'newest' } })

    const { planVerdict, schedulePlanCheck } = usePlanPreview()
    schedulePlanCheck(7, draft)
    await vi.advanceTimersByTimeAsync(400)
    schedulePlanCheck(7, { ...draft, entry_price: 19000 })
    await vi.runAllTimersAsync()

    resolveFirst({ data: { plan_adherence: 'IN_PLAN', plan_adherence_reason: 'stale' } })
    await vi.runAllTimersAsync()

    expect(planVerdict.value.plan_adherence_reason).toBe('newest')
  })
})
