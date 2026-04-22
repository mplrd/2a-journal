import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useStatsStore } from '@/stores/stats'
import { statsService } from '@/services/stats'

vi.mock('@/services/stats', () => ({
  statsService: {
    getDashboard: vi.fn(),
    getCharts: vi.fn(),
  },
}))

describe('stats store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useStatsStore()
    vi.restoreAllMocks()
  })

  it('initial state is empty', () => {
    expect(store.overview).toBeNull()
    expect(store.recentTrades).toEqual([])
    expect(store.charts).toBeNull()
    expect(store.loading).toBe(false)
    expect(store.chartsLoading).toBe(false)
    expect(store.error).toBeNull()
    expect(store.filters).toEqual({})
  })

  it('fetchDashboard loads overview and recent trades', async () => {
    const mockResponse = {
      success: true,
      data: {
        overview: { total_trades: 5, win_rate: 60.0, total_pnl: 300.0 },
        recent_trades: [{ id: 1, symbol: 'NASDAQ', pnl: 100.0 }],
      },
    }
    statsService.getDashboard.mockResolvedValue(mockResponse)

    await store.fetchDashboard()

    expect(store.overview).toEqual(mockResponse.data.overview)
    expect(store.recentTrades).toEqual(mockResponse.data.recent_trades)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('fetchDashboard sets error on failure', async () => {
    const error = new Error('Network error')
    error.messageKey = 'error.network'
    statsService.getDashboard.mockRejectedValue(error)

    await expect(store.fetchDashboard()).rejects.toThrow()

    expect(store.error).toBe('error.network')
    expect(store.overview).toBeNull()
    expect(store.loading).toBe(false)
  })

  it('fetchCharts loads chart data', async () => {
    const mockResponse = {
      success: true,
      data: {
        cumulative_pnl: [{ closed_at: '2026-01-10', cumulative_pnl: 100 }],
        win_loss: { win: 5, loss: 2, be: 1 },
        pnl_by_symbol: [{ symbol: 'NASDAQ', total_pnl: 150 }],
      },
    }
    statsService.getCharts.mockResolvedValue(mockResponse)

    await store.fetchCharts()

    expect(store.charts).toEqual(mockResponse.data)
    expect(store.chartsLoading).toBe(false)
  })

  it('setFilters updates filters', () => {
    store.setFilters({ account_id: 5 })

    expect(store.filters).toEqual({ account_id: 5 })
  })

  it('$reset clears all state', async () => {
    const mockResponse = {
      success: true,
      data: {
        overview: { total_trades: 5 },
        recent_trades: [{ id: 1 }],
      },
    }
    statsService.getDashboard.mockResolvedValue(mockResponse)
    await store.fetchDashboard()

    store.$reset()

    expect(store.overview).toBeNull()
    expect(store.recentTrades).toEqual([])
    expect(store.charts).toBeNull()
    expect(store.loading).toBe(false)
    expect(store.chartsLoading).toBe(false)
    expect(store.error).toBeNull()
    expect(store.filters).toEqual({})
  })
})
