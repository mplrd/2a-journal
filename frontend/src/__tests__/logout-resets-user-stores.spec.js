import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useStatsStore } from '@/stores/stats'
import { useSetupsStore } from '@/stores/setups'
import { useCustomFieldsStore } from '@/stores/customFields'
import { useNotebookStore } from '@/stores/notebook'
import { useSupportStore } from '@/stores/support'
import { useSymbolAccountSettingsStore } from '@/stores/symbolAccountSettings'
import { useBillingStore } from '@/stores/billing'
import { authService } from '@/services/auth'

vi.mock('@/services/auth', () => ({
  authService: {
    logout: vi.fn(),
    deleteAccount: vi.fn(),
    me: vi.fn(),
  },
}))

// Logging out is an SPA navigation, not a reload: whatever a store still holds
// is served to the next user who signs in from the same tab — and the stores
// that cache behind a `loaded` flag never go back to the API to replace it.

// Every store module, so a store added later cannot slip past the reset.
const storeModules = import.meta.glob('../stores/*.js', { eager: true })

// Not user data: auth clears its own user and tokens, and features holds the
// platform-wide flags, the same for every user.
const NOT_USER_DATA = ['auth', 'features']

function userStores() {
  return Object.values(storeModules)
    .flatMap((mod) => Object.entries(mod))
    .filter(([name, value]) => /^use\w+Store$/.test(name) && typeof value === 'function')
    .map(([, useStore]) => useStore())
    .filter((store) => !NOT_USER_DATA.includes(store.$id))
}

const leaveWith = {
  logout: (auth) => auth.logout(),
  deleteAccount: (auth) => auth.deleteAccount({ password: 'Test1234' }),
}

describe('leaving the session resets every store holding user data', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    authService.logout.mockResolvedValue({ success: true })
    authService.deleteAccount.mockResolvedValue({ success: true })
  })

  it('finds the user stores to guard', () => {
    const ids = userStores().map((store) => store.$id)

    // A sanity floor, so a broken glob cannot make the guard below pass empty.
    expect(ids).toEqual(expect.arrayContaining(['accounts', 'stats', 'setups', 'symbolAccountSettings']))
    expect(ids).not.toContain('auth')
    expect(ids).not.toContain('features')
  })

  describe.each(Object.keys(leaveWith))('on %s', (gesture) => {
    it('calls the own $reset of every user store', async () => {
      const spies = userStores().map((store) => {
        // A setup store that does not implement $reset inherits Pinia's, which throws.
        expect(() => store.$reset(), `store "${store.$id}" implements no $reset`).not.toThrow()
        return [store.$id, vi.spyOn(store, '$reset')]
      })

      await leaveWith[gesture](useAuthStore())

      for (const [id, spy] of spies) {
        expect(spy, `store "${id}" is not reset on ${gesture}`).toHaveBeenCalled()
      }
    })

    it('leaves nothing of the previous user in the stores that were left behind', async () => {
      const stats = useStatsStore()
      stats.charts = { win_loss: { win: 3, loss: 1, be: 0 } }
      stats.overview = { total_trades: 4 }
      stats.bySymbol = [{ symbol: 'NASDAQ' }]
      const setups = useSetupsStore()
      setups.setups = [{ id: 1, label: 'Breakout' }]
      setups.loaded = true
      const customFields = useCustomFieldsStore()
      customFields.definitions = [{ id: 1, label: 'Tendance' }]
      customFields.loaded = true
      const notebook = useNotebookStore()
      notebook.categories = [{ id: 1, label: 'Money' }]
      notebook.notes = [{ id: 1, content: 'a note' }]
      notebook.categoriesLoaded = true
      const support = useSupportStore()
      support.tickets = [{ id: 40 }]
      support.current = { id: 40 }
      const settings = useSymbolAccountSettingsStore()
      settings.settings = [{ symbol_id: 1, account_id: 1, point_value: 25 }]
      settings.loaded = true
      const billing = useBillingStore()
      billing.status = { has_access: true }

      await leaveWith[gesture](useAuthStore())

      expect(stats.charts).toBeNull()
      expect(stats.overview).toBeNull()
      expect(stats.bySymbol).toEqual([])
      expect(setups.setups).toEqual([])
      expect(setups.loaded).toBe(false)
      expect(customFields.definitions).toEqual([])
      expect(customFields.loaded).toBe(false)
      expect(notebook.categories).toEqual([])
      expect(notebook.notes).toEqual([])
      expect(notebook.categoriesLoaded).toBe(false)
      expect(support.tickets).toEqual([])
      expect(support.current).toBeNull()
      // The point values feed the plan risk: a cached matrix would price the
      // next user's plans with the previous user's settings.
      expect(settings.settings).toEqual([])
      expect(settings.loaded).toBe(false)
      expect(settings.getPointValue(1, 1)).toBeNull()
      expect(billing.status).toBeNull()
    })
  })
})
