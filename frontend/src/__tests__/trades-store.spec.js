import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useTradesStore } from '@/stores/trades'
import { tradesService } from '@/services/trades'
import { TradeStatus } from '@/constants/enums'

vi.mock('@/services/trades', () => ({
  tradesService: {
    list: vi.fn(),
    get: vi.fn(),
    create: vi.fn(),
    close: vi.fn(),
    remove: vi.fn(),
  },
}))

describe('trades store', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useTradesStore()
    vi.restoreAllMocks()
  })

  it('initial state is empty', () => {
    expect(store.trades).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
    expect(store.filters).toEqual({})
  })

  it('fetchTrades loads trades', async () => {
    const mockTrades = [
      { id: 1, symbol: 'NASDAQ', status: TradeStatus.OPEN },
      { id: 2, symbol: 'DAX', status: TradeStatus.CLOSED },
    ]
    tradesService.list.mockResolvedValue({ success: true, data: mockTrades })

    await store.fetchTrades()

    expect(store.trades).toEqual(mockTrades)
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  it('fetchTrades sets error on failure', async () => {
    const error = new Error('Network error')
    error.messageKey = 'error.network'
    tradesService.list.mockRejectedValue(error)

    await expect(store.fetchTrades()).rejects.toThrow()

    expect(store.error).toBe('error.network')
    expect(store.trades).toEqual([])
  })

  it('createTrade adds to list', async () => {
    const newTrade = { id: 1, symbol: 'NASDAQ', status: TradeStatus.OPEN }
    tradesService.create.mockResolvedValue({ success: true, data: newTrade })

    await store.createTrade({ symbol: 'NASDAQ' })

    expect(store.trades).toHaveLength(1)
    expect(store.trades[0].symbol).toBe('NASDAQ')
  })

  it('closeTrade updates in list', async () => {
    store.trades = [{ id: 1, symbol: 'NASDAQ', status: TradeStatus.OPEN }]
    const closed = { id: 1, symbol: 'NASDAQ', status: TradeStatus.CLOSED, pnl: 100 }
    tradesService.close.mockResolvedValue({ success: true, data: closed })

    await store.closeTrade(1, { exit_price: 18600, exit_size: 1, exit_type: 'TP' })

    expect(store.trades[0].status).toBe(TradeStatus.CLOSED)
    expect(store.trades[0].pnl).toBe(100)
  })

  it('deleteTrade removes from list', async () => {
    store.trades = [
      { id: 1, symbol: 'NASDAQ' },
      { id: 2, symbol: 'DAX' },
    ]
    tradesService.remove.mockResolvedValue({ success: true })

    await store.deleteTrade(1)

    expect(store.trades).toHaveLength(1)
    expect(store.trades[0].id).toBe(2)
  })

  it('deleteTrade sets error on failure', async () => {
    store.trades = [{ id: 1, symbol: 'NASDAQ' }]
    const error = new Error('Forbidden')
    error.messageKey = 'trades.error.forbidden'
    tradesService.remove.mockRejectedValue(error)

    await expect(store.deleteTrade(1)).rejects.toThrow()

    expect(store.error).toBe('trades.error.forbidden')
    expect(store.trades).toHaveLength(1)
  })

  it('loading is true during operations', async () => {
    let resolvePromise
    tradesService.list.mockReturnValue(
      new Promise((resolve) => {
        resolvePromise = resolve
      }),
    )

    const promise = store.fetchTrades()
    expect(store.loading).toBe(true)

    resolvePromise({ success: true, data: [] })
    await promise

    expect(store.loading).toBe(false)
  })

  it('setFilters updates filters', () => {
    store.setFilters({ status: TradeStatus.OPEN, symbol: 'NASDAQ' })

    expect(store.filters).toEqual({ status: TradeStatus.OPEN, symbol: 'NASDAQ' })
  })

  it('closeTrade sets error on failure', async () => {
    store.trades = [{ id: 1, symbol: 'NASDAQ', status: TradeStatus.OPEN }]
    const error = new Error('Already closed')
    error.messageKey = 'trades.error.already_closed'
    tradesService.close.mockRejectedValue(error)

    await expect(store.closeTrade(1, {})).rejects.toThrow()

    expect(store.error).toBe('trades.error.already_closed')
  })
})
